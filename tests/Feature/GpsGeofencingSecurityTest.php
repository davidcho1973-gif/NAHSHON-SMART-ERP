<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpsGeofencingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private Site $site;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'code' => 'NAHSHON',
            'name' => 'NAHSHON MEP',
            'status' => 'active',
        ]);

        $this->site = Site::create([
            'company_id' => $this->company->id,
            'code' => 'TEST-GPS',
            'name' => 'GPS Test Site',
            'status' => 'active',
            'latitude' => 33.42550000,
            'longitude' => -111.94000000,
            'radius_meters' => 150,
            'setup_completed_at' => now(),
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'Secure',
            'last_name' => 'Worker',
            'email' => 'secure.worker@example.com',
            'employment_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'email' => 'secure.worker@example.com',
            'employee_id' => $this->employee->id,
            'access_role' => 'worker',
            'account_status' => 'active',
        ]);
    }

    public function test_it_blocks_mocked_gps_location(): void
    {
        $response = $this->actingAs($this->user)->postJson('/smart-company-api/api_clockInWithGps', [
            'args' => ['clock_in', 33.4255, -111.9400, 10.0, true, time()], // isMocked = true
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'message' => '가상 위치 조작 앱(Fake GPS) 사용이 감지되어 출퇴근 등록이 차단되었습니다.',
        ]);
    }

    public function test_it_blocks_low_accuracy_gps_signals(): void
    {
        $response = $this->actingAs($this->user)->postJson('/smart-company-api/api_clockInWithGps', [
            'args' => ['clock_in', 33.4255, -111.9400, 300.0, false, time()], // accuracy = 300m
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'message' => 'GPS 신호 정밀도가 낮거나 유효하지 않습니다. GPS를 켜고 실외에서 다시 시도해 주세요.',
        ]);
    }

    public function test_it_blocks_commute_with_manipulated_client_time(): void
    {
        $manipulatedTime = time() - 600; // 10 minutes ago
        $response = $this->actingAs($this->user)->postJson('/smart-company-api/api_clockInWithGps', [
            'args' => ['clock_in', 33.4255, -111.9400, 15.0, false, $manipulatedTime],
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'message' => '휴대폰 기기 시간 조작이 감지되었습니다. 인터넷 표준 시간 설정(자동 설정)을 켜주세요.',
        ]);
    }

    public function test_it_blocks_offline_sync_with_tampered_integrity_token(): void
    {
        $eventAt = Carbon::now()->toDateTimeString();
        $fakeToken = 'invalid_checksum_token';

        $queueItem = [
            'event_type' => 'clock_in',
            'event_at' => $eventAt,
            'latitude' => 33.4255,
            'longitude' => -111.9400,
            'accuracy' => 10.0,
            'token' => $fakeToken,
        ];

        $response = $this->actingAs($this->user)->postJson('/smart-company-api/api_syncOfflineAttendance', [
            'args' => [[$queueItem]],
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('synced_count', 0);
        $response->assertJsonFragment([
            'reason' => '출퇴근 데이터 무결성 검증 실패 (시간/위치 임의 조작 의심)',
        ]);
    }

    public function test_it_syncs_offline_commute_successfully_with_valid_token(): void
    {
        $eventTime = Carbon::now()->subMinutes(10); // 10 minutes ago (offline event)
        $eventAtStr = $eventTime->toDateTimeString();
        
        $secretKey = config('app.key') ?: 'base64:nahshonsmarterpdefaultkey';
        $validToken = hash_hmac(
            'sha256',
            $this->employee->id . '_clock_in_' . $eventAtStr . '_33.4255_-111.94_', // empty team code suffix
            $secretKey
        );

        $queueItem = [
            'event_type' => 'clock_in',
            'event_at' => $eventAtStr,
            'latitude' => 33.4255,
            'longitude' => -111.9400,
            'accuracy' => 15.0,
            'token' => $validToken,
        ];

        $response = $this->actingAs($this->user)->postJson('/smart-company-api/api_syncOfflineAttendance', [
            'args' => [[$queueItem]],
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('synced_count', 1);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'event_type' => 'clock_in',
            'source' => 'offline_gps_sync',
        ]);
    }
}
