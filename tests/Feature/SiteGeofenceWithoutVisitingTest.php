<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 현장에 <b>가지 않고</b> 지오펜스를 넣는 길.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 지오펜스를 넣는 방법이 「현재 위치 등록」 하나뿐이었다. 그러려면 관리자가 그 현장에
 * 서 있어야 한다. 사무실은 애리조나, 현장은 조지아다 — 갈 일이 없으면 영영 못 넣는다.
 *
 * 못 넣으면 그 현장은 GPS 판정이 아예 불가능해지고(좌표가 없으면 'unknown'),
 * 작업자 전원의 출퇴근이 매일 «확인 필요» 로 쌓인다. 반장이 그것을 하나씩 누른다.
 *
 * ── 왜 중앙값인가 ──────────────────────────────────────────────────────
 * 집에서 잘못 찍힌 한 건이 평균을 몇 킬로미터씩 끌고 간다. 그렇게 옮겨진 중심으로
 * 지오펜스를 걸면 <b>진짜 현장이 반경 밖</b>이 되어, 고치려던 문제가 더 나빠진다.
 */
class SiteGeofenceWithoutVisitingTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $this->site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'S-1', 'name' => 'Far Away Site',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin', 'email' => 'a@example.test', 'password' => Hash::make('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function punchAt(float $lat, float $lng): void
    {
        $employee = Employee::query()->create([
            'company_id' => $this->site->company_id, 'site_id' => $this->site->id,
            'employee_number' => 'E-'.AttendanceLog::query()->count().'-'.mt_rand(1000, 9999),
            'first_name' => 'W', 'last_name' => 'X', 'name' => 'W X',
            'employment_status' => 'active',
        ]);

        AttendanceLog::query()->create([
            'employee_id' => $employee->id, 'company_id' => $this->site->company_id,
            'site_id' => $this->site->id, 'attendance_date' => now()->toDateString(),
            'event_type' => 'clock_in', 'event_at' => now()->subDay(), 'source' => 'web_portal',
            'status' => 'pending',
            'payload' => ['lat' => $lat, 'lng' => $lng, 'accuracy' => 30],
        ]);
    }

    public function test_it_finds_the_site_centre_from_punches_already_recorded(): void
    {
        // 현장 주변에 흩어져 찍힌 기록들.
        foreach ([[32.0800, -81.0900], [32.0810, -81.0910], [32.0820, -81.0920]] as [$lat, $lng]) {
            $this->punchAt($lat, $lng);
        }

        $this->actingAs($this->admin());
        $result = SmartCompanyData::suggestSiteGeofence((string) $this->site->id);

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta(32.0810, $result['lat'], 0.0005);
        $this->assertEqualsWithDelta(-81.0910, $result['lng'], 0.0005);
        $this->assertSame(3, $result['samples']);
        $this->assertGreaterThanOrEqual(100, $result['radius']);
    }

    public function test_one_punch_from_home_does_not_drag_the_centre_away(): void
    {
        // 현장에서 다섯 건, 집에서 잘못 찍힌 한 건(수백 km 밖).
        foreach ([[32.0800, -81.0900], [32.0805, -81.0905], [32.0810, -81.0910],
            [32.0815, -81.0915], [32.0820, -81.0920]] as [$lat, $lng]) {
            $this->punchAt($lat, $lng);
        }
        $this->punchAt(33.4484, -112.0740);   // 애리조나 — 평균이라면 여기로 끌려간다

        $this->actingAs($this->admin());
        $result = SmartCompanyData::suggestSiteGeofence((string) $this->site->id);

        $this->assertTrue($result['success']);
        // 중앙값이라 현장에 그대로 남아 있어야 한다.
        $this->assertEqualsWithDelta(32.081, $result['lat'], 0.002, '이상치 한 건이 중심을 옮기면 안 된다.');
        $this->assertEqualsWithDelta(-81.091, $result['lng'], 0.002);
        // 반경도 이상치를 덮으려고 수백 km 로 부풀면 안 된다.
        $this->assertLessThanOrEqual(2000, $result['radius'], '이상치를 덮으려 반경이 도시만큼 커지면 안 된다.');
    }

    public function test_it_refuses_when_there_is_not_enough_to_go_on(): void
    {
        // 두 건으로 현장 중심을 정하면 그것은 추측이다. 추측으로 임금 판정 기준을
        // 만들면, 틀렸을 때 아무도 왜 그런지 설명하지 못한다.
        $this->punchAt(32.0800, -81.0900);
        $this->punchAt(32.0810, -81.0910);

        $this->actingAs($this->admin());
        $result = SmartCompanyData::suggestSiteGeofence((string) $this->site->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('최소 3건', $result['error']);
        $this->assertSame(2, $result['samples']);
    }

    public function test_punches_without_a_location_are_not_counted(): void
    {
        // 위치 권한을 끈 사람의 기록에는 좌표가 없다. 그것을 0,0 으로 읽으면
        // 중심이 아프리카 앞바다로 간다.
        $this->punchAt(32.0800, -81.0900);
        $this->punchAt(32.0810, -81.0910);
        $this->punchAt(32.0820, -81.0920);

        $employee = Employee::query()->first();
        AttendanceLog::query()->create([
            'employee_id' => $employee->id, 'company_id' => $this->site->company_id,
            'site_id' => $this->site->id, 'attendance_date' => now()->toDateString(),
            'event_type' => 'clock_out', 'event_at' => now()->subDay(), 'source' => 'web_portal',
            'status' => 'pending', 'payload' => ['lat' => null, 'lng' => null],
        ]);

        $this->actingAs($this->admin());
        $result = SmartCompanyData::suggestSiteGeofence((string) $this->site->id);

        $this->assertSame(3, $result['samples'], '좌표 없는 기록은 세면 안 된다.');
    }

    public function test_only_people_who_may_set_a_geofence_can_ask(): void
    {
        $this->punchAt(32.0800, -81.0900);
        $this->punchAt(32.0810, -81.0910);
        $this->punchAt(32.0820, -81.0920);

        $worker = User::query()->create([
            'name' => 'W', 'email' => 'w@example.test', 'password' => Hash::make('x'),
            'access_role' => 'worker', 'access_scope' => 'assigned_sites', 'account_status' => 'active',
        ]);

        $this->actingAs($worker);
        $result = SmartCompanyData::suggestSiteGeofence((string) $this->site->id);

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('lat', $result, '권한이 없으면 좌표를 보여 주지도 않는다.');
    }

    public function test_typed_coordinates_can_set_the_geofence(): void
    {
        // 현장에 못 가도 좌표만 알면 넣을 수 있어야 한다 — 구글 지도에서 읽어 오면 된다.
        $this->actingAs($this->admin());

        $result = SmartCompanyData::setMySiteGeofence(32.0809, -81.0912, 600, (string) $this->site->id);

        $this->assertTrue($result['success']);
        $this->site->refresh();
        $this->assertEqualsWithDelta(32.0809, (float) $this->site->latitude, 0.00001);
        $this->assertEqualsWithDelta(-81.0912, (float) $this->site->longitude, 0.00001);
        $this->assertSame(600, (int) $this->site->radius_meters);
    }

    public function test_the_screen_offers_a_way_that_does_not_need_being_there(): void
    {
        // 서버가 받을 준비가 돼 있어도 화면에 길이 없으면 아무도 못 쓴다.
        $html = (string) file_get_contents(base_path('resources/views/smart-company/index.blade.php'));

        $this->assertStringContainsString('현장에 갈 수 없을 때', $html);
        $this->assertStringContainsString('autoAttSetGeofenceManual', $html);
        $this->assertStringContainsString('autoAttSuggestGeofence', $html);
        $this->assertStringContainsString('api_suggestSiteGeofence', $html);
    }
}
