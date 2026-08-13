<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollTimesheet;
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

        $company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
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

    // ── 근무 · 급여 탭 ──────────────────────────────────────────────

    public function test_the_work_tab_reads_hours_from_the_payroll_timesheet(): void
    {
        // 화면이 출퇴근 기록을 다시 계산하지 않는다. 급여가 보는 숫자와 작업자가 보는
        // 숫자가 다르면 그 차이를 아무도 설명하지 못한다.
        PayrollTimesheet::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->employee->company_id,
            'site_id' => $this->site->id,
            'work_date' => '2026-08-10',
            'check_in_at' => Carbon::parse('2026-08-10 07:00:00'),
            'check_out_at' => Carbon::parse('2026-08-10 17:30:00'),
            'regular_minutes' => 480,
            'overtime_minutes' => 90,
            'status' => 'approved',
        ]);

        $week = $this->actingAs($this->user)->getJson(route('attendance-app.home'))->json('week');

        $this->assertEqualsWithDelta(8.0, $week['regularHours'], 0.01);
        $this->assertEqualsWithDelta(1.5, $week['overtimeHours'], 0.01);
        $this->assertCount(1, $week['days']);
        $this->assertSame('07:00', $week['days'][0]['in']);
        $this->assertTrue($week['days'][0]['settled']);
    }

    public function test_unsettled_days_are_flagged_so_they_do_not_look_final(): void
    {
        PayrollTimesheet::create([
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'work_date' => '2026-08-10',
            'regular_minutes' => 240,
            'overtime_minutes' => 0,
            'status' => 'draft',
        ]);

        $week = $this->actingAs($this->user)->getJson(route('attendance-app.home'))->json('week');

        $this->assertFalse($week['days'][0]['settled']);
    }

    public function test_pay_is_estimated_from_the_rate_and_this_weeks_hours(): void
    {
        EmployeePayrollProfile::where('employee_id', $this->employee->id)
            ->update(['base_rate' => 40, 'overtime_multiplier' => 1.5, 'pay_currency' => 'USD']);
        PayrollTimesheet::create([
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'work_date' => '2026-08-10',
            'regular_minutes' => 600,   // 10h
            'overtime_minutes' => 120,  // 2h
            'status' => 'approved',
        ]);

        $pay = $this->actingAs($this->user)->getJson(route('attendance-app.home'))->json('pay');

        $this->assertTrue($pay['hasRate']);
        $this->assertEqualsWithDelta(400.0, $pay['regularPay'], 0.01);       // 10h × 40
        $this->assertEqualsWithDelta(120.0, $pay['overtimePay'], 0.01);      // 2h × 60
        $this->assertEqualsWithDelta(520.0, $pay['estimated'], 0.01);
    }

    public function test_no_rate_means_no_invented_number(): void
    {
        // 단가가 0 이면 금액을 지어내지 않는다. 0 원이라고 띄우면 작업자는 못 받는 줄 안다.
        EmployeePayrollProfile::where('employee_id', $this->employee->id)->update(['base_rate' => 0]);

        $pay = $this->actingAs($this->user)->getJson(route('attendance-app.home'))->json('pay');

        $this->assertFalse($pay['hasRate']);
    }

    public function test_elapsed_time_counts_from_the_clock_in(): void
    {
        // 화면이 초를 세려면 "지금까지 몇 초"가 한 숫자로 와야 한다.
        $this->actingAs($this->user)->postJson(route('attendance-app.punch'), [
            'direction' => 'in', 'lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:30:00'));   // 출근 07:00 기준 2시간 30분
        $res = $this->actingAs($this->user)->getJson(route('attendance-app.home'))->json();

        $this->assertTrue($res['clockedIn']);
        $this->assertSame(9000, $res['elapsedSeconds']);
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

    public function test_the_badge_qr_is_baked_into_the_page(): void
    {
        // 3단은 인터넷이 끊겼을 때 쓰는 마지막 수단이다. 그때 QR 을 받으러 서버에 다녀올
        // 수는 없다 — 끊긴 게 인터넷이기 때문이다. 그림째로 페이지에 들어 있어야 한다.
        $this->actingAs($this->user)
            ->get(route('attendance-app.index'))
            ->assertOk()
            ->assertSee('id="qr-tpl"', escape: false)
            ->assertSee('data:image/svg+xml;base64,', escape: false);

        // 그리고 배지 토큰이 생겨야 반장이 스캔했을 때 누구인지 알 수 있다.
        $this->assertDatabaseHas('employee_badge_qr_tokens', [
            'employee_id' => $this->employee->id, 'status' => 'active',
        ]);
    }

    public function test_the_screen_has_four_tabs(): void
    {
        $this->actingAs($this->user)
            ->get(route('attendance-app.index'))
            ->assertOk()
            ->assertSee('data-tab="home"', escape: false)
            ->assertSee('data-tab="work"', escape: false)
            ->assertSee('data-tab="pay"', escape: false)
            ->assertSee('data-tab="me"', escape: false);
    }

    public function test_the_qr_button_does_not_navigate_away(): void
    {
        // 예전에는 "내 QR 보여주기"가 같은 주소로 이동만 했다 — 오프라인에서는 아무 데도
        // 못 가고, 온라인이어도 같은 화면이 다시 뜰 뿐이었다.
        $this->actingAs($this->user)
            ->get(route('attendance-app.index'))
            ->assertOk()
            ->assertDontSee("window.location.href = '".route('attendance-app.index')."'", escape: false);
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

    // ── 화면의 뼈대 ────────────────────────────────────────────────

    public function test_the_screen_always_carries_all_four_tabs(): void
    {
        // 탭은 이 화면의 뼈대다. 하나라도 사라지면 근무·급여·내 QR 로 갈 길이 없어지는데,
        // 화면은 안 깨지고 멀쩡해 보인다 — 아무도 오류를 못 본 채 기능만 없어진다.
        $html = $this->actingAs($this->user)->get(route('attendance-app.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<nav class="tabs"', $html, '탭 바가 없습니다.');

        foreach (['home' => '출퇴근', 'work' => '근무', 'pay' => '급여', 'me' => '나'] as $tab => $label) {
            $this->assertStringContainsString('data-tab="'.$tab.'"', $html, "[{$tab}] 탭이 없습니다.");
            $this->assertStringContainsString($label, $html, "[{$tab}] 탭 이름이 없습니다.");
        }
    }

    public function test_the_tabs_survive_an_account_with_no_worker_attached(): void
    {
        // 직원이 연결되지 않은 계정(관리자 등)이 열어도 화면 뼈대는 그대로여야 한다.
        // 여기서 탭이 사라지면 "탭이 안 보인다"는 신고가 들어오는데 원인을 못 찾는다.
        $orphan = User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $this->actingAs($orphan)
            ->get(route('attendance-app.index'))
            ->assertOk()
            ->assertSee('<nav class="tabs"', false);
    }

    public function test_the_gate_screen_is_a_different_screen_with_no_tabs(): void
    {
        // 게이트 화면(로그인 없이 쓰는 것)에는 탭이 없다 — 설계가 그렇다.
        // 이 둘을 헷갈리면 "탭 네 개가 안 보인다"가 된다. 차이를 못 박아 둔다.
        $html = $this->get(route('gate.show', ['site' => $this->site]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-tab="home"', $html);
    }

    // ── 아직 연결되지 않은 계정 ────────────────────────────────────

    public function test_an_unlinked_account_gets_setup_guidance_not_an_error(): void
    {
        // 관리자가 이 앱을 처음 열면 반드시 이 화면을 본다 — 관리자 계정에는 직원 기록이
        // 안 붙어 있기 때문이다. 사실상 이 앱의 첫인상이라, 빨간 오류 상자로 두면
        // 앱이 깨진 줄 안다.
        $admin = User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites',
            'account_status' => 'active', 'email' => 'davidcho@example.com',
        ]);

        $res = $this->actingAs($admin)->getJson(route('attendance-app.home'));

        $res->assertStatus(422);
        $res->assertJsonPath('code', 'no_employee');
        // 지금 어느 계정으로 들어와 있는지 — 휴대폰에 구글 계정이 여러 개면 이걸 못 봐서 헤맨다.
        $res->assertJsonPath('email', 'davidcho@example.com');
        // 관리자는 그 자리에서 고칠 수 있다.
        $res->assertJsonPath('canManage', true);
    }

    public function test_a_worker_without_a_link_is_not_told_to_go_fix_it_himself(): void
    {
        // 작업자에게 "인원관리에서 연결하세요" 라고 하면 갈 수 있는 화면이 아니다.
        $orphan = User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAs($orphan)
            ->getJson(route('attendance-app.home'))
            ->assertStatus(422)
            ->assertJsonPath('canManage', false);
    }

    public function test_the_screen_can_draw_the_unlinked_state(): void
    {
        // 화면 쪽에 그리는 코드가 있어야 한다 — 서버만 고치면 여전히 빨간 상자가 뜬다.
        $html = $this->actingAs($this->user)->get(route('attendance-app.index'))->assertOk()->getContent();

        $this->assertStringContainsString("d.code === 'no_employee'", $html);
        $this->assertStringContainsString('작업자와 연결되지 않았습니다', $html);
        // 진짜 실패는 다시 해 볼 수 있어야 한다.
        $this->assertStringContainsString("data-act=\"retry\"", $html);
    }
}
