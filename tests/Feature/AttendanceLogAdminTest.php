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
 * 출퇴근 기록 수정 — Filament AttendanceLogResource 를 SPA 로 옮긴 화면의 뒷단.
 *
 * 이 표는 급여의 근거 자료다. 그래서 "고칠 수 있는가" 보다 "고친 흔적이 남는가" 와
 * "남의 현장을 건드릴 수 없는가" 가 중요하다.
 */
class AttendanceLogAdminTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $other;

    private Company $company;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create(['code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->other = Site::create(['code' => 'OTHER', 'name' => 'Other', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->employee = Employee::create([
            'name' => '강민철', 'employee_number' => 'E-1001',
            'company_id' => $this->company->id, 'site_id' => $this->site->id, 'employment_status' => 'active',
        ]);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
    }

    private function log(array $extra = []): AttendanceLog
    {
        return AttendanceLog::create(array_merge([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-08-05',
            'event_type' => 'clock_in',
            'event_at' => '2026-08-05 07:02:00',
            'source' => 'team_qr',
            'status' => 'approved',
        ], $extra));
    }

    private function svc(): AttendanceLogAdminService
    {
        return app(AttendanceLogAdminService::class);
    }

    // ── 접근 ────────────────────────────────────────────────────────────

    public function test_a_worker_cannot_read_attendance_logs(): void
    {
        $this->actingAs($this->user('worker', ['access_scope' => 'self']));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_payroll_can_read_but_not_edit(): void
    {
        // 급여 담당은 근거를 봐야 하지만 기록을 고칠 사람은 아니다.
        $this->log();
        $this->actingAs($this->user('payroll'));

        $res = $this->svc()->list();
        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage']);
        $this->assertFalse($this->svc()->save(['id' => AttendanceLog::first()->id])['success']);
    }

    public function test_a_site_manager_only_sees_their_own_site(): void
    {
        $mine = $this->log();
        $theirs = $this->log(['site_id' => $this->other->id]);

        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $ids = array_column($this->svc()->list()['rows'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_a_site_manager_cannot_edit_another_sites_record(): void
    {
        $theirs = $this->log(['site_id' => $this->other->id]);
        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $res = $this->svc()->save([
            'id' => $theirs->id, 'employeeId' => $this->employee->id,
            'eventType' => 'clock_in', 'eventAt' => '2026-08-05 09:00:00', 'status' => 'approved',
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame('07:02', $theirs->fresh()->event_at->format('H:i'));
    }

    public function test_a_site_manager_cannot_change_status_on_another_site(): void
    {
        $theirs = $this->log(['site_id' => $this->other->id, 'status' => 'pending']);
        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $this->assertFalse($this->svc()->setStatus($theirs->id, 'approved')['success']);
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    // ── 수정 이력 ────────────────────────────────────────────────────────

    public function test_an_edit_records_who_changed_what(): void
    {
        $row = $this->log();
        $admin = $this->user('admin');
        $this->actingAs($admin);

        $this->svc()->save([
            'id' => $row->id, 'employeeId' => $this->employee->id, 'siteId' => $this->site->id,
            'eventType' => 'clock_in', 'eventAt' => '2026-08-05 08:30:00', 'status' => 'approved',
            'notes' => 'QR 미인식으로 수기 보정',
        ]);

        $edits = $row->fresh()->payload['admin_edits'];
        $last = end($edits);
        $this->assertSame($admin->id, $last['byId']);
        $this->assertSame('07:02:00', substr($last['changes']['event_at']['from'], 11));
        $this->assertSame('08:30:00', substr($last['changes']['event_at']['to'], 11));
        $this->assertSame(1, $this->svc()->list()['rows'][0]['editCount'], '고친 적이 있으면 목록에서 보여야 한다');
    }

    public function test_an_unchanged_save_does_not_pile_up_history(): void
    {
        $row = $this->log();
        $this->actingAs($this->user('admin'));

        $same = [
            'id' => $row->id, 'employeeId' => $this->employee->id, 'siteId' => $this->site->id,
            'eventType' => 'clock_in', 'eventAt' => '2026-08-05 07:02:00', 'status' => 'approved',
        ];
        $this->svc()->save($same);
        $this->svc()->save($same);

        $this->assertCount(0, $row->fresh()->payload['admin_edits'] ?? [], '안 바뀐 저장까지 쌓이면 이력을 읽을 수 없다');
    }

    public function test_approving_is_recorded_in_history_and_stamps_the_approver(): void
    {
        $row = $this->log(['status' => 'pending']);
        $admin = $this->user('admin');
        $this->actingAs($admin);

        $this->assertTrue($this->svc()->setStatus($row->id, 'approved')['success']);

        $row->refresh();
        $this->assertSame('approved', $row->status);
        $this->assertSame($admin->id, $row->approved_by_id);
        $this->assertNotNull($row->approved_at);
        $edits = $row->payload['admin_edits'];
        $this->assertSame('pending', end($edits)['changes']['status']['from']);
    }

    public function test_history_is_returned_newest_first(): void
    {
        $row = $this->log(['status' => 'pending']);
        $this->actingAs($this->user('admin'));

        $this->svc()->setStatus($row->id, 'approved');
        $this->svc()->setStatus($row->id, 'rejected');

        $edits = $this->svc()->history($row->id)['edits'];
        $this->assertSame('rejected', $edits[0]['changes']['status']['to'], '최근 것이 위에 와야 읽기 편하다');
    }

    // ── 입력 검증 ────────────────────────────────────────────────────────

    public function test_a_future_timestamp_is_refused(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->save([
            'employeeId' => $this->employee->id, 'eventType' => 'clock_in',
            'eventAt' => Carbon::now()->addDays(30)->toDateTimeString(), 'status' => 'approved',
        ]);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('eventAt', $res['errors'], '연도 오타(2026→2027)를 여기서 잡아야 한다');
    }

    public function test_the_attendance_date_follows_the_site_timezone(): void
    {
        // Phoenix 기준 8/5 저녁 8시는 UTC 로는 8/6 새벽이다. 날짜가 하루 밀리면
        // 그날 인원과 급여가 어긋난다.
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->save([
            'employeeId' => $this->employee->id, 'siteId' => $this->site->id,
            'eventType' => 'clock_out', 'eventAt' => '2026-08-05 20:00:00', 'status' => 'approved',
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame('2026-08-05', AttendanceLog::find($res['id'])->attendance_date->toDateString());
    }

    public function test_a_missing_employee_is_reported_on_the_field(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->save(['eventType' => 'clock_in', 'eventAt' => '2026-08-05 07:00:00', 'status' => 'approved']);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('employeeId', $res['errors']);
    }

    public function test_a_new_record_is_stamped_as_created_by_the_admin(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);

        $res = $this->svc()->save([
            'employeeId' => $this->employee->id, 'siteId' => $this->site->id,
            'eventType' => 'clock_in', 'eventAt' => '2026-08-05 07:00:00', 'status' => 'approved',
        ]);

        $row = AttendanceLog::find($res['id']);
        $this->assertSame($admin->id, $row->recorded_by_id);
        $this->assertSame('created', $row->payload['admin_edits'][0]['action']);
    }

    // ── 삭제 ────────────────────────────────────────────────────────────

    public function test_a_site_manager_cannot_delete_a_record(): void
    {
        $row = $this->log();
        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $res = $this->svc()->delete($row->id);
        $this->assertFalse($res['success'], '지우면 그날 그 사람이 왔었다는 사실 자체가 사라진다');
        $this->assertStringContainsString('반려', $res['error']);
        $this->assertNotNull(AttendanceLog::find($row->id));
    }

    public function test_an_admin_can_delete_a_record(): void
    {
        $row = $this->log();
        $this->actingAs($this->user('admin'));

        $this->assertTrue($this->svc()->delete($row->id)['success']);
        $this->assertNull(AttendanceLog::find($row->id));
    }

    // ── 목록 필터 ────────────────────────────────────────────────────────

    public function test_the_list_filters_by_date_range_and_status(): void
    {
        $this->log(['attendance_date' => '2026-08-01', 'event_at' => '2026-08-01 07:00:00']);
        $recent = $this->log(['attendance_date' => '2026-08-05', 'status' => 'pending']);
        $this->actingAs($this->user('admin'));

        $ids = array_column($this->svc()->list(['from' => '2026-08-04', 'until' => '2026-08-06'])['rows'], 'id');
        $this->assertSame([$recent->id], $ids);

        $ids = array_column($this->svc()->list(['status' => 'pending'])['rows'], 'id');
        $this->assertSame([$recent->id], $ids);
    }

    public function test_the_api_exposes_the_screen(): void
    {
        $this->actingAs($this->user('admin'));

        $this->postJson('/smart-company-api/api_getAttendanceLogs', ['args' => [[]], 'siteId' => 'ALL'])
            ->assertOk()->assertJsonPath('success', true);
        $this->postJson('/smart-company-api/api_getAttendanceLogOptions', ['args' => [], 'siteId' => 'ALL'])
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_a_read_only_client_is_blocked_from_mutating(): void
    {
        $row = $this->log();
        $this->actingAs($this->user('client'));

        $this->postJson('/smart-company-api/api_setAttendanceLogStatus', ['args' => [$row->id, 'rejected'], 'siteId' => 'ALL'])
            ->assertStatus(403);
        $this->assertSame('approved', $row->fresh()->status);
    }
}
