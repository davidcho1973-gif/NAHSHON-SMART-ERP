<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\SiteWifiAccessPoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 작업자 화면 — 자동 → 직접 → QR 사다리의 2단.
 *
 * 여기서 지키는 것은 하나다: **길을 막지는 않되, 확인 안 된 기록을 그냥 통과시키지 않는다.**
 * 현장에 있는 것이 확인되면 바로 승인하고, 확인이 안 되면 반장이 한 번 보게 남긴다.
 * 막아 버리면 지오펜스가 아직 없는 새 현장이나 신호가 안 잡히는 구석에서 일하는 사람이
 * 그날 근무를 통째로 못 남긴다.
 */
class WorkerAttendanceScreenTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_LAT = 33.453316;

    private const SITE_LNG = -112.177502;

    private Site $site;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-10 07:00:00'));

        $company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $company->id, 'code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX',
            'timezone' => 'America/Phoenix', 'status' => 'active',
            'latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG, 'radius_meters' => 500,
        ]);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'name' => '김성훈', 'employment_status' => 'active', 'role' => '배관',
        ]);
        $this->user = User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
            'employee_id' => $this->employee->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_screen_opens(): void
    {
        $this->actingAs($this->user)->get(route('attendance-app.index'))->assertOk();
    }

    public function test_home_tells_the_screen_what_it_needs_to_pick_a_tier(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('attendance-app.home'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('employee.name', '김성훈')
            ->assertJsonPath('site.code', 'LG_ESS_PH')
            // 자동이 가능한지는 이 두 값으로 화면이 판단한다.
            ->assertJsonPath('site.hasGeofence', true)
            ->assertJsonPath('site.hasNetwork', false)
            ->assertJsonPath('clockedIn', false);
    }

    public function test_site_network_is_reported_once_registered(): void
    {
        SiteWifiAccessPoint::create([
            'site_id' => $this->site->id,
            'kind' => SiteWifiAccessPoint::KIND_NETWORK,
            'bssid' => '203.0.113.0/24',
        ]);

        $this->actingAs($this->user)
            ->getJson(route('attendance-app.home'))
            ->assertJsonPath('site.hasNetwork', true);
    }

    public function test_an_account_without_an_employee_gets_a_plain_explanation(): void
    {
        $orphan = User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAs($orphan)
            ->getJson(route('attendance-app.home'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_manual_punch_inside_the_fence_is_approved_immediately(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('attendance-app.punch'), [
                'direction' => 'in', 'lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('verified', true);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee->id, 'event_type' => 'clock_in',
            'source' => 'web_portal', 'status' => 'approved',
        ]);
    }

    public function test_manual_punch_from_far_away_waits_for_approval(): void
    {
        // 집에서 눌러도 막지는 않는다. 다만 반장이 한 번 본다.
        $this->actingAs($this->user)
            ->postJson(route('attendance-app.punch'), [
                'direction' => 'in', 'lat' => 33.9, 'lng' => -112.9, 'accuracy' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('verified', false);

        $this->assertDatabaseHas('attendance_logs', ['event_type' => 'clock_in', 'status' => 'pending']);
    }

    public function test_manual_punch_without_any_location_waits_for_approval(): void
    {
        // 위치 권한을 껐거나 실내라 안 잡히는 경우. 확인할 방법이 없으면 승인 대기다.
        $this->actingAs($this->user)
            ->postJson(route('attendance-app.punch'), ['direction' => 'in'])
            ->assertJsonPath('verified', false);

        $this->assertDatabaseHas('attendance_logs', ['event_type' => 'clock_in', 'status' => 'pending']);
    }

    public function test_site_network_alone_verifies_a_manual_punch(): void
    {
        // 실내라 GPS 가 죽어도 현장 WiFi 를 타고 왔으면 현장에 있는 것이다.
        SiteWifiAccessPoint::create([
            'site_id' => $this->site->id,
            'kind' => SiteWifiAccessPoint::KIND_NETWORK,
            'bssid' => '203.0.113.0/24',
        ]);

        $this->actingAs($this->user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->postJson(route('attendance-app.punch'), ['direction' => 'in'])
            ->assertJsonPath('verified', true);

        $this->assertDatabaseHas('attendance_logs', ['event_type' => 'clock_in', 'status' => 'approved']);
    }

    public function test_a_sloppy_fix_does_not_count_as_being_on_site(): void
    {
        // 좌표는 현장 한가운데인데 오차가 900m 다. 자동 판정과 같은 규칙을 써야 한다.
        $this->actingAs($this->user)
            ->postJson(route('attendance-app.punch'), [
                'direction' => 'in', 'lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 900,
            ])
            ->assertJsonPath('verified', false);
    }

    public function test_you_cannot_clock_in_twice(): void
    {
        $this->actingAs($this->user)->postJson(route('attendance-app.punch'), ['direction' => 'in']);

        $this->actingAs($this->user)
            ->postJson(route('attendance-app.punch'), ['direction' => 'in'])
            ->assertJsonPath('success', false);

        $this->assertSame(1, AttendanceLog::where('event_type', 'clock_in')->count());
    }

    public function test_you_cannot_clock_out_before_clocking_in(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('attendance-app.punch'), ['direction' => 'out'])
            ->assertJsonPath('success', false);

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_clocking_out_closes_the_day(): void
    {
        $this->actingAs($this->user)->postJson(route('attendance-app.punch'), [
            'direction' => 'in', 'lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 16:30:00'));
        $this->actingAs($this->user)->postJson(route('attendance-app.punch'), [
            'direction' => 'out', 'lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10,
        ])->assertJsonPath('success', true);

        $res = $this->actingAs($this->user)->getJson(route('attendance-app.home'))->json();
        $this->assertFalse($res['clockedIn']);
        $this->assertCount(2, $res['logs']);
        $this->assertSame('출근', $res['logs'][0]['typeLabel']);
        $this->assertSame('퇴근', $res['logs'][1]['typeLabel']);
    }

    public function test_todays_records_show_how_each_one_was_made(): void
    {
        AttendanceLog::create([
            'employee_id' => $this->employee->id, 'site_id' => $this->site->id,
            'attendance_date' => '2026-08-10', 'event_type' => 'clock_in',
            'event_at' => Carbon::parse('2026-08-10 07:02:00'),
            'source' => 'geo_auto', 'status' => 'approved',
        ]);

        $logs = $this->actingAs($this->user)->getJson(route('attendance-app.home'))->json('logs');

        $this->assertSame('자동', $logs[0]['sourceLabel']);
        $this->assertFalse($logs[0]['needsReview']);
    }
}
