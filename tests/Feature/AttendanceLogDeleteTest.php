<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\AttendanceLogAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 출퇴근 기록 삭제.
 *
 * 이 표는 급여의 근거다. 진짜로 지워 버리면 "그날 그 사람이 왔었다" 는 사실 자체가
 * 사라진다. 나중에 임금 다툼이 생겼을 때 우리 쪽에는 아무 근거가 없고, 누가 언제
 * 지웠는지도 남지 않는다.
 *
 * 그래서 삭제는 표시만 한다 — 화면과 급여 계산에서는 즉시 빠지고, 표에는 남는다.
 * 되살릴 수 있어야 사람이 그 버튼을 편히 누른다.
 */
class AttendanceLogDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private AttendanceLog $log;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'DP', 'name' => 'TEST CO', 'status' => 'active']);
        $this->site = Site::create([
            'code' => 'S1', 'name' => 'Site One',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'name' => 'Dhairo Carmona', 'employment_status' => 'active',
        ]);
        $this->log = AttendanceLog::create([
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'company_id' => $company->id,
            'event_type' => 'clock_in',
            'event_at' => Carbon::parse('2026-08-11 07:00:00'),
            'attendance_date' => '2026-08-11',
            'status' => 'approved',
            'source' => 'web_portal',
        ]);
    }

    private function actor(string $role): User
    {
        return User::factory()->create([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function svc(): AttendanceLogAdminService
    {
        return app(AttendanceLogAdminService::class);
    }

    // ── 누가 지울 수 있나 ───────────────────────────────────────────────

    public function test_a_site_manager_may_correct_but_not_delete(): void
    {
        // 고치는 것과 없애는 것은 다르다. 고친 것은 되짚을 수 있지만,
        // 없앤 것은 되짚을 사람이 정해져 있어야 한다.
        $this->actingAs($this->actor('site_manager'));

        $res = $this->svc()->delete($this->log->id);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('반려', $res['error']);
        $this->assertDatabaseHas('attendance_logs', ['id' => $this->log->id, 'deleted_at' => null]);
    }

    public function test_an_administrator_may_delete(): void
    {
        $this->actingAs($this->actor('admin'));

        $this->assertTrue($this->svc()->delete($this->log->id)['success']);
    }

    // ── 지워도 없어지지 않는다 ─────────────────────────────────────────

    public function test_the_record_survives_the_delete(): void
    {
        $this->actingAs($this->actor('admin'));
        $this->svc()->delete($this->log->id);

        // 화면에서는 빠진다.
        $this->assertNull(AttendanceLog::find($this->log->id));
        // 표에는 남는다 — 임금 다툼이 생겼을 때 근거가 된다.
        $this->assertNotNull(AttendanceLog::withTrashed()->find($this->log->id));
    }

    public function test_it_records_who_deleted_it(): void
    {
        $actor = $this->actor('admin');
        $this->actingAs($actor);
        $this->svc()->delete($this->log->id);

        $edits = AttendanceLog::withTrashed()->find($this->log->id)->payload['admin_edits'] ?? [];

        $this->assertNotEmpty($edits);
        $this->assertSame('delete', end($edits)['action']);
        $this->assertSame($actor->id, end($edits)['byId']);
    }

    // ── 되살릴 수 있다 ─────────────────────────────────────────────────

    public function test_a_deleted_record_can_be_brought_back(): void
    {
        // 되살릴 방법이 없으면 삭제는 되돌릴 수 없는 버튼이 되고,
        // 급여 근거를 다루는 화면에서 그런 버튼은 아무도 편히 못 누른다.
        $this->actingAs($this->actor('admin'));
        $this->svc()->delete($this->log->id);

        $this->assertTrue($this->svc()->restore($this->log->id)['success']);
        $this->assertNotNull(AttendanceLog::find($this->log->id));
    }

    public function test_restoring_is_also_recorded(): void
    {
        $this->actingAs($this->actor('admin'));
        $this->svc()->delete($this->log->id);
        $this->svc()->restore($this->log->id);

        $actions = array_column(AttendanceLog::find($this->log->id)->payload['admin_edits'] ?? [], 'action');

        $this->assertSame(['delete', 'restore'], $actions);
    }

    public function test_a_site_manager_may_not_restore(): void
    {
        $this->actingAs($this->actor('admin'));
        $this->svc()->delete($this->log->id);

        $this->actingAs($this->actor('site_manager'));
        $this->assertFalse($this->svc()->restore($this->log->id)['success']);
    }

    // ── 목록 ────────────────────────────────────────────────────────────

    public function test_deleted_records_are_out_of_the_normal_list(): void
    {
        $this->actingAs($this->actor('admin'));
        $this->svc()->delete($this->log->id);

        $rows = $this->svc()->list(['from' => '2026-08-01', 'until' => '2026-08-31'])['rows'];

        $this->assertSame([], $rows);
    }

    public function test_the_deleted_filter_finds_them(): void
    {
        // 볼 방법이 아예 없으면 "되살리기" 도 없는 셈이다.
        $this->actingAs($this->actor('admin'));
        $this->svc()->delete($this->log->id);

        $rows = $this->svc()->list(['status' => 'deleted'])['rows'];

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['deleted']);
        $this->assertNotNull($rows[0]['deletedAt']);
    }

    public function test_the_delete_status_is_not_offered_as_a_record_state(): void
    {
        // 수정 폼의 상태 목록에 '삭제됨' 이 있으면 사람이 상태를 골라서 삭제하게 된다.
        // 삭제는 상태가 아니라 별개의 일이다.
        $this->actingAs($this->actor('admin'));
        $o = $this->svc()->options();

        $this->assertNotContains('deleted', array_column($o['statuses'], 'value'));
        $this->assertContains('deleted', array_column($o['filterStatuses'], 'value'));
    }

    // ── 급여 ────────────────────────────────────────────────────────────

    public function test_the_screen_knows_who_may_delete(): void
    {
        $this->actingAs($this->actor('site_manager'));
        $this->assertFalse($this->svc()->list()['canDelete']);

        $this->actingAs($this->actor('admin'));
        $this->assertTrue($this->svc()->list()['canDelete']);
    }

    public function test_the_table_offers_delete_and_restore(): void
    {
        $js = file_get_contents(public_path('js/admin-attendance.js'));

        $this->assertStringContainsString('AdminAttendance.remove(', $js);
        $this->assertStringContainsString('AdminAttendance.restore(', $js);
        $this->assertStringContainsString('api_restoreAttendanceLog', $js);
    }
}
