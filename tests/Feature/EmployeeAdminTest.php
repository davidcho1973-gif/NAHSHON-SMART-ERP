<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\EmployeeAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 직원 등록 · 수정 — Filament EmployeeResource 를 SPA 로 옮긴 화면의 뒷단.
 *
 * 현장에 들어가는 사람을 등록하는 곳이다. 사번·NFC 가 겹치면 남의 출퇴근이 찍히고,
 * 비자·안전교육이 끊긴 사람이 들어가면 사고가 된다. 그 둘을 중심으로 검증한다.
 */
class EmployeeAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Site $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create(['code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->other = Site::create(['code' => 'OTHER', 'name' => 'Other', 'timezone' => 'America/Phoenix', 'status' => 'active']);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
    }

    private function svc(): EmployeeAdminService
    {
        return app(EmployeeAdminService::class);
    }

    private function employee(array $extra = []): Employee
    {
        return Employee::create(array_merge([
            'name' => '강민철', 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'employment_status' => 'active', 'employment_type' => 'direct',
        ], $extra));
    }

    private function base(array $over = []): array
    {
        return array_merge([
            'name' => '홍길동', 'companyId' => (string) $this->company->id,
            'employmentType' => 'direct', 'status' => 'active',
        ], $over);
    }

    // ── 접근 ────────────────────────────────────────────────────────────

    public function test_a_worker_cannot_read_the_employee_list(): void
    {
        $this->actingAs($this->user('worker', ['access_scope' => 'self']));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_a_site_manager_reads_but_cannot_manage(): void
    {
        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $res = $this->svc()->list();
        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage'], '직원 등록은 인사 담당의 일이다');
        $this->assertFalse($this->svc()->save($this->base())['success']);
    }

    public function test_a_site_manager_only_sees_their_own_site(): void
    {
        $mine = $this->employee();
        $theirs = $this->employee(['name' => '남의 현장', 'site_id' => $this->other->id]);
        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $ids = array_column($this->svc()->list()['rows'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    // ── 사번 · NFC 중복 ─────────────────────────────────────────────────

    public function test_a_duplicate_nfc_id_is_refused(): void
    {
        $this->employee(['badge_number' => 'N123456789']);
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base(['badgeNumber' => 'N123456789']));

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('출퇴근', $res['errors']['badgeNumber'], '왜 막는지 알려줘야 한다');
    }

    public function test_a_duplicate_employee_number_is_refused(): void
    {
        $existing = $this->employee();
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base(['employeeNumber' => $existing->employee_number]));

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('employeeNumber', $res['errors']);
    }

    public function test_editing_keeps_your_own_numbers(): void
    {
        $row = $this->employee(['badge_number' => 'N999']);
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base([
            'id' => $row->id, 'name' => '이름만 변경',
            'employeeNumber' => $row->employee_number, 'badgeNumber' => 'N999',
        ]));

        $this->assertTrue($res['success']);
        $this->assertSame('이름만 변경', $row->fresh()->name);
    }

    // ── 이름 · 사번 자동 처리 ────────────────────────────────────────────

    public function test_a_number_is_issued_automatically_when_left_blank(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base());

        $this->assertTrue($res['success']);
        $this->assertNotEmpty($res['employeeNumber'], '자동 발급된 사번을 화면에 알려줘야 한다');
        $this->assertSame($res['employeeNumber'], Employee::find($res['id'])->employee_number);
    }

    public function test_first_and_last_name_alone_are_enough(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base(['name' => '', 'firstName' => 'James', 'lastName' => 'Kim']));

        $this->assertTrue($res['success']);
        $this->assertSame('James Kim', Employee::find($res['id'])->name);
    }

    public function test_a_nameless_employee_is_refused(): void
    {
        // 셋 다 비면 모델이 "Employee E-0001" 을 만들어 나중에 누군지 알 수 없다.
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base(['name' => '', 'firstName' => '', 'lastName' => '']));

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('name', $res['errors']);
    }

    // ── QR 권한 ─────────────────────────────────────────────────────────

    public function test_a_worker_role_cannot_be_given_a_wider_qr_scope(): void
    {
        // 본인 출퇴근만 찍는 작업자에게 현장 범위를 주면 남의 출퇴근을 대신 찍을 수 있다.
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base(['qrRole' => 'worker', 'qrScope' => 'site']));

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('qrScope', $res['errors']);
    }

    public function test_a_foreman_may_take_a_team_scope(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base(['qrRole' => 'foreman', 'qrScope' => 'team']));

        $this->assertTrue($res['success']);
        $this->assertSame('team', Employee::find($res['id'])->attendance_app_scope);
    }

    // ── 자격 만료 표시 ───────────────────────────────────────────────────

    public function test_an_expired_certificate_is_flagged_in_the_list(): void
    {
        $this->employee([
            'name' => '만료자',
            'visa_expires_on' => Carbon::now()->subDay()->toDateString(),
            'safety_training_expires_on' => Carbon::now()->addDays(10)->toDateString(),
        ]);
        $this->actingAs($this->user('hr_manager'));

        $row = collect($this->svc()->list()['rows'])->firstWhere('name', '만료자');
        $states = collect($row['expiring'])->keyBy('label');

        $this->assertSame('expired', $states['비자']['state'], '끊긴 비자로 현장에 들어가면 안 된다');
        $this->assertSame('soon', $states['안전교육']['state'], '30일 안에 닥친 것도 미리 보여야 한다');
    }

    public function test_a_healthy_employee_has_no_expiry_flags(): void
    {
        $this->employee([
            'name' => '정상',
            'visa_expires_on' => Carbon::now()->addYear()->toDateString(),
        ]);
        $this->actingAs($this->user('hr_manager'));

        $row = collect($this->svc()->list()['rows'])->firstWhere('name', '정상');
        $this->assertSame([], $row['expiring']);
    }

    // ── 삭제 보호 ────────────────────────────────────────────────────────

    public function test_an_employee_with_attendance_records_cannot_be_deleted(): void
    {
        $row = $this->employee();
        AttendanceLog::create([
            'employee_id' => $row->id, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'attendance_date' => '2026-08-05', 'event_type' => 'clock_in',
            'event_at' => '2026-08-05 07:00:00', 'status' => 'approved',
        ]);
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->delete($row->id);

        $this->assertFalse($res['success'], '지우면 근무 사실과 급여 근거가 함께 사라진다');
        $this->assertStringContainsString('퇴사', $res['error']);
        $this->assertNotNull(Employee::find($row->id));
    }

    public function test_an_hr_manager_cannot_delete_employees(): void
    {
        $row = $this->employee();
        $this->actingAs($this->user('hr_manager'));

        $this->assertFalse($this->svc()->delete($row->id)['success']);
        $this->assertNotNull(Employee::find($row->id));
    }

    public function test_an_employee_without_records_can_be_deleted_by_an_admin(): void
    {
        $row = $this->employee();
        $this->actingAs($this->user('admin'));

        $this->assertTrue($this->svc()->delete($row->id)['success']);
        $this->assertNull(Employee::find($row->id));
    }

    // ── 입력 검증 · 저장 내용 ────────────────────────────────────────────

    public function test_a_company_is_required(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base(['companyId' => '']));

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('companyId', $res['errors']);
    }

    public function test_the_full_record_is_saved(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->save($this->base([
            'name' => '박소피아', 'firstName' => 'Sophia', 'lastName' => 'Park',
            'email' => 'Sophia@Example.test', 'nationality' => 'KR', 'language' => 'es',
            'siteId' => (string) $this->site->id, 'role' => '배관',
            'employmentType' => 'indirect', 'startDate' => '2026-07-01',
            'badgeNumber' => 'N777', 'badgePrintedNumber' => 'P-12', 'badgeCompanyName' => 'LG',
            'badgeIssuedOn' => '2026-06-25', 'visaExpiresOn' => '2027-01-01',
            'qrRole' => 'foreman', 'qrScope' => 'team',
        ]));

        $e = Employee::find($res['id']);
        $this->assertSame('sophia@example.test', $e->email, '이메일은 소문자로 정규화돼야 한다');
        $this->assertSame('es', $e->preferred_language);
        $this->assertSame('indirect', $e->employment_type);
        $this->assertSame('2026-07-01', $e->start_date->toDateString());
        $this->assertSame('N777', $e->badge_number);
    }

    public function test_the_api_exposes_the_screen_and_blocks_read_only_clients(): void
    {
        $this->actingAs($this->user('hr_manager'));
        $this->postJson('/smart-company-api/api_getEmployeeAdminList', ['args' => [[]], 'siteId' => 'ALL'])
            ->assertOk()->assertJsonPath('success', true);

        $this->actingAs($this->user('client'));
        $this->postJson('/smart-company-api/api_saveEmployeeAdmin', ['args' => [['name' => 'x']], 'siteId' => 'ALL'])
            ->assertStatus(403);
    }
}
