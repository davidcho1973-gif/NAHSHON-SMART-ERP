<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use App\Models\Site;
use App\Models\Team;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Services\Admin\EmployeeAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 현장 QR 로 들어온 사람의 «확인» 은 한 번이고, 사람이 정할 것은 셋뿐이다.
 *
 * 예전에는 한 명마다 여덟 가지를 세 화면에서 했다 — 회사·고용형태·공정·팀·계정·
 * 추가정보 링크·임금률, 그리고 20칸짜리 폼을 열어 저장해야 «확인 필요» 딱지가
 * 사라졌다. 그중 사람만 아는 것은 소속 회사, 팀, 자사일 때의 시급이다.
 */
class SelfRegistrationReviewRowTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $own;

    private Company $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->own = Company::create([
            'code' => 'OWN', 'name' => 'ABC MEP', 'status' => 'active',
            'company_type' => Company::TYPE_OWN,
        ]);
        $this->partner = Company::create([
            'code' => 'SUB', 'name' => '한빛전기', 'status' => 'active',
            'company_type' => Company::TYPE_PARTNER,
        ]);
        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site', 'company_id' => $this->own->id,
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    /** 인사와 급여는 권한이 다르다 — 임금률까지 정할 수 있는 사람으로 확인한다. */
    private function hr(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
    }

    private function selfRegistered(): Employee
    {
        $this->post('/join/w/'.$this->site->id, ['full_name' => '이대웅', 'phone' => '480-555-0142'])->assertOk();

        return Employee::query()->sole();
    }

    private function workDay(Employee $employee, string $date): void
    {
        foreach (['clock_in' => '07:00', 'clock_out' => '16:00'] as $type => $time) {
            AttendanceLog::create([
                'employee_id' => $employee->id,
                'company_id' => $employee->company_id,
                'site_id' => $this->site->id,
                'attendance_date' => $date,
                'event_type' => $type,
                'event_at' => Carbon::parse($date.' '.$time, 'America/Phoenix'),
                'source' => 'gate_qr',
                'status' => 'approved',
            ]);
        }
    }

    public function test_one_confirmation_settles_company_team_trade_and_rate(): void
    {
        $employee = $this->selfRegistered();
        $team = Team::create([
            'company_id' => $this->own->id, 'site_id' => $this->site->id,
            'code' => 'TEAM-P1', 'name' => '배관 1팀', 'status' => 'active',
        ]);
        $this->actingAs($this->hr());

        $result = app(EmployeeAdminService::class)->confirmSelfRegistration($employee->id, [
            'companyId' => $this->own->id,
            'teamId' => $team->id,
            'role' => 'Piping',
            'baseRate' => '32.50',
        ]);

        $this->assertTrue($result['success'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $employee->refresh();
        $this->assertSame($this->own->id, $employee->company_id);
        // 고용형태는 회사에서 따라 나온다 — 사람이 두 번 답하지 않는다.
        $this->assertSame(Employee::TYPE_DIRECT, $employee->employment_type);
        $this->assertSame($team->id, $employee->team_id);
        $this->assertSame('Piping', $employee->role);
        $this->assertSame(32.5, (float) $employee->payrollProfile?->base_rate);
        // 확인이 곧 딱지 해제다 — 20칸 폼을 열어 저장할 일이 없다.
        $this->assertFalse((bool) data_get($employee->payload, 'self_registered_pending_hr'));
    }

    public function test_confirming_brings_back_the_days_already_worked(): void
    {
        $employee = $this->selfRegistered();
        $this->workDay($employee, '2026-09-21');
        $this->workDay($employee, '2026-09-22');
        $this->assertSame(0, PayrollTimesheet::query()->where('employee_id', $employee->id)->count());

        $this->actingAs($this->hr());
        app(EmployeeAdminService::class)->confirmSelfRegistration($employee->id, [
            'companyId' => $this->own->id,
            'baseRate' => '30',
        ]);

        $this->assertSame(2, PayrollTimesheet::query()->where('employee_id', $employee->id)->count());
    }

    public function test_a_subcontractor_confirmation_asks_for_no_rate_and_makes_no_timesheet(): void
    {
        $employee = $this->selfRegistered();
        $this->workDay($employee, '2026-09-21');
        $this->actingAs($this->hr());

        app(EmployeeAdminService::class)->confirmSelfRegistration($employee->id, [
            'companyId' => $this->partner->id,
        ]);

        $employee->refresh();
        $this->assertSame(Employee::TYPE_INDIRECT, $employee->employment_type);
        $this->assertSame(0, PayrollTimesheet::query()->where('employee_id', $employee->id)->count(), '협력사 인원의 임금은 그 회사가 준다');
    }

    public function test_the_review_alert_is_closed_when_the_person_is_confirmed(): void
    {
        $employee = $this->selfRegistered();
        $this->assertDatabaseHas('unified_alerts', [
            'fingerprint' => 'worker-self-registration-review:'.$employee->id,
            'status' => 'unresolved',
        ]);

        $this->actingAs($this->hr());
        app(EmployeeAdminService::class)->confirmSelfRegistration($employee->id, ['companyId' => $this->partner->id]);

        $this->assertSame('completed', UnifiedAlert::query()
            ->where('fingerprint', 'worker-self-registration-review:'.$employee->id)->value('status'),
            '처리된 알림이 목록에 남으면 남은 알림을 아무도 안 본다');
    }

    /** 회사 분류를 안 한 채로 확인하면 «미확인» 이 그대로 결론이 된다 — 그래서 막는다. */
    public function test_an_unclassified_company_cannot_be_the_answer(): void
    {
        $employee = $this->selfRegistered();
        $unknown = Company::create(['code' => 'NEW', 'name' => '새업체', 'status' => 'active']);
        $this->actingAs($this->hr());

        $result = app(EmployeeAdminService::class)->confirmSelfRegistration($employee->id, ['companyId' => $unknown->id]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('companyId', $result['errors']);
        $this->assertSame(Employee::TYPE_UNVERIFIED, $employee->fresh()->employment_type);
    }

    /**
     * 인사담당자는 임금률 권한이 없다(돈은 급여 권한이다). 그렇다고 확인이 막히면
     * 그 사람의 출퇴근이 계속 급여에서 빠진다 — 확인은 되고, 임금률은 알림이 부른다.
     */
    public function test_hr_can_confirm_even_though_the_rate_is_payrolls_job(): void
    {
        $employee = $this->selfRegistered();
        $this->actingAs(User::factory()->create(['access_role' => 'hr_manager', 'account_status' => 'active']));

        $result = app(EmployeeAdminService::class)->confirmSelfRegistration($employee->id, [
            'companyId' => $this->own->id,
            'baseRate' => '32.50',
        ]);

        $this->assertTrue($result['success'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($result['rateSaved']);
        $this->assertNotNull($result['notice']);
        $this->assertSame(Employee::TYPE_DIRECT, $employee->fresh()->employment_type);
        $this->assertDatabaseHas('unified_alerts', ['event_type' => 'payroll_setup_missing']);
    }

    public function test_a_worker_cannot_confirm_themselves(): void
    {
        $employee = $this->selfRegistered();
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']));

        $result = app(EmployeeAdminService::class)->confirmSelfRegistration($employee->id, ['companyId' => $this->own->id]);

        $this->assertFalse($result['success']);
        $this->assertSame(Employee::TYPE_UNVERIFIED, $employee->fresh()->employment_type);
    }
}
