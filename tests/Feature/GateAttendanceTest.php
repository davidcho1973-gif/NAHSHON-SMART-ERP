<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Services\Attendance\GateAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 게이트 QR 출퇴근 — 로그인 없이 이름으로 본인을 찾아 출근/퇴근을 찍는다.
 * (휴대폰 앱을 켜두지 않아도 동작하는 게 핵심.)
 */
class GateAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;
    private Site $otherSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'ABC ENG', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'LG-PH', 'name' => 'LG Phoenix', 'status' => 'active']);
        $this->otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'SK-AZ', 'name' => 'SK AZ', 'status' => 'active']);
    }

    private function worker(string $name, ?Site $site = null, string $status = 'active'): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'site_id' => ($site ?? $this->site)->id,
            'name' => $name, 'first_name' => $name, 'last_name' => '',
            'email' => $name . '@x.com', 'employment_status' => $status,
            'badge_company_name' => 'AUTORICA',
        ]);
    }

    public function test_first_punch_is_clock_in_and_second_is_clock_out(): void
    {
        $kim = $this->worker('김철수');
        $svc = app(GateAttendanceService::class);

        $in = $svc->punch($kim, $this->site);
        $this->assertTrue($in['success']);
        $this->assertSame('clock_in', $in['event']);

        // 5분 중복창을 피하려고 과거 clock_in 으로 만든 뒤 다시 스캔 → 퇴근.
        AttendanceLog::where('employee_id', $kim->id)->update(['event_at' => now()->subHours(6)]);

        $out = $svc->punch($kim, $this->site);
        $this->assertSame('clock_out', $out['event']);
        $this->assertSame(1, AttendanceLog::where('employee_id', $kim->id)->where('event_type', 'clock_in')->count());
        $this->assertSame(1, AttendanceLog::where('employee_id', $kim->id)->where('event_type', 'clock_out')->count());
        $this->assertSame('gate_qr', AttendanceLog::where('event_type', 'clock_out')->first()->source);
    }

    public function test_duplicate_scan_within_window_is_ignored(): void
    {
        $kim = $this->worker('김철수');
        $svc = app(GateAttendanceService::class);

        $svc->punch($kim, $this->site);
        $again = $svc->punch($kim, $this->site);

        $this->assertTrue($again['ignored'] ?? false);
        $this->assertSame(1, AttendanceLog::where('employee_id', $kim->id)->count());
    }

    public function test_worker_from_another_site_is_rejected(): void
    {
        $lee = $this->worker('이민준', $this->otherSite);
        $svc = app(GateAttendanceService::class);

        $res = $svc->punch($lee, $this->site);

        $this->assertFalse($res['success']);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_inactive_worker_is_rejected(): void
    {
        $gone = $this->worker('퇴사자', $this->site, 'terminated');
        $res = app(GateAttendanceService::class)->punch($gone, $this->site);

        $this->assertFalse($res['success']);
    }

    public function test_search_finds_site_workers_by_name_with_status(): void
    {
        $this->worker('김철수');
        $this->worker('김영희');
        $this->worker('이민준', $this->otherSite); // 다른 현장 → 안 나와야 함

        $res = app(GateAttendanceService::class)->search($this->site, '김');

        $this->assertCount(2, $res);
        $this->assertSame('clock_in', $res->first()['next']); // 오늘 기록 없음 → 다음은 출근
    }

    public function test_gate_page_and_qr_are_public(): void
    {
        $this->get(route('gate.show', ['site' => $this->site]))->assertStatus(200)->assertSee('현장 출퇴근');
        $this->get(route('gate.qr', ['site' => $this->site]))->assertStatus(200)->assertSee('data:image/svg+xml;base64,', false);
    }

    public function test_punch_endpoint_records_without_login(): void
    {
        $kim = $this->worker('김철수');

        $res = $this->postJson(route('gate.punch', ['site' => $this->site]), ['employee_id' => $kim->id]);

        $res->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('event', 'clock_in');
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $kim->id, 'source' => 'gate_qr', 'event_type' => 'clock_in']);
    }

    public function test_search_endpoint_scopes_to_site(): void
    {
        $this->worker('김철수');
        $this->worker('이민준', $this->otherSite);

        $res = $this->postJson(route('gate.search', ['site' => $this->site]), ['q' => '']);
        $res->assertStatus(200);
        $names = collect($res->json('workers'))->pluck('name');
        $this->assertTrue($names->contains('김철수'));
        $this->assertFalse($names->contains('이민준'));
    }
}
