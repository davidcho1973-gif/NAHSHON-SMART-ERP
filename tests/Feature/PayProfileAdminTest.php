<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Admin\PayProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 임금 프로필 — 급여 사슬의 마지막 입력점.
 *
 * 시간은 출퇴근에서 자동으로 오고 프로필도 직원을 만들 때 자동으로 생긴다(Observer).
 * 사람이 정하는 것은 "얼마" 하나뿐이다. 그래서 가장 중요한 검증은 "단가가 비어 있다는
 * 사실이 화면에 드러나는가" 다 — 급여 마감 날 발견하면 늦다.
 */
class PayProfileAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function svc(): PayProfileService
    {
        return app(PayProfileService::class);
    }

    /** 직원을 만들면 Observer 가 단가 0 짜리 프로필을 함께 만든다. */
    private function employee(string $name): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'employment_status' => 'active',
        ]);
    }

    private function profileOf(Employee $employee): EmployeePayrollProfile
    {
        return EmployeePayrollProfile::where('employee_id', $employee->id)->firstOrFail();
    }

    public function test_worker_cannot_see_pay_profiles(): void
    {
        $this->actingAs($this->user('worker'));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_hr_manager_can_view_but_not_change_rates(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->list();

        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage']);
    }

    public function test_new_employee_arrives_with_a_blank_rate_that_is_flagged(): void
    {
        // 프로필은 자동으로 생기지만 단가는 0 이다 — 그대로 두면 급여가 0 으로 나간다.
        $this->actingAs($this->user('payroll'));
        $blank = $this->employee('단가 없음');
        $paid = $this->employee('단가 있음');
        $this->profileOf($paid)->update(['base_rate' => 32.50]);

        $res = $this->svc()->list();

        $this->assertSame(1, $res['missingRates']);
        $rows = collect($res['profiles'])->keyBy('employee');
        $this->assertTrue($rows['단가 없음']['rateMissing']);
        $this->assertFalse($rows['단가 있음']['rateMissing']);
        $this->assertSame($blank->id, $rows['단가 없음']['employeeId']);
    }

    public function test_employees_with_a_profile_are_not_offered_again(): void
    {
        // 한 직원에 프로필이 둘이면 어느 단가가 맞는지 알 수 없다. 이미 있는 사람은 뺀다.
        $this->actingAs($this->user('admin'));
        $taken = $this->employee('이미 있음');

        $offered = fn (): array => collect($this->svc()->options()['employees'])->pluck('value')->all();

        $this->assertNotContains((string) $taken->id, $offered());

        // 프로필이 없는 직원(과거 데이터·프로비저닝 실패)만 후보로 남는다.
        $this->profileOf($taken)->delete();
        $this->assertContains((string) $taken->id, $offered());
    }

    public function test_saving_a_profile_stores_the_rate(): void
    {
        $this->actingAs($this->user('payroll'));
        $employee = $this->employee('용접공');
        $profile = $this->profileOf($employee);

        $res = $this->svc()->save([
            'id' => $profile->id,
            'pay_type' => 'hourly',
            'base_rate' => '41.75',
            'overtime_multiplier' => '1.5',
            'trade' => 'Welder',
            'worker_division' => '한국인',
        ]);

        $this->assertTrue($res['success']);
        $profile->refresh();
        $this->assertSame(41.75, (float) $profile->base_rate);
        $this->assertSame('Welder', $profile->trade);
        $this->assertSame('한국인', $profile->worker_division);
    }

    public function test_a_second_profile_for_the_same_employee_is_refused(): void
    {
        $this->actingAs($this->user('admin'));
        $employee = $this->employee('중복');

        $res = $this->svc()->save(['employee_id' => $employee->id, 'pay_type' => 'hourly', 'base_rate' => 40]);

        $this->assertFalse($res['success']);
        $this->assertSame(1, EmployeePayrollProfile::where('employee_id', $employee->id)->count());
    }

    public function test_negative_rate_is_refused(): void
    {
        $this->actingAs($this->user('admin'));
        $profile = $this->profileOf($this->employee('음수'));

        $res = $this->svc()->save(['id' => $profile->id, 'pay_type' => 'hourly', 'base_rate' => -5]);

        $this->assertFalse($res['success']);
        $this->assertSame(0.0, (float) $profile->refresh()->base_rate);
    }

    public function test_unknown_pay_type_is_refused(): void
    {
        $this->actingAs($this->user('admin'));
        $profile = $this->profileOf($this->employee('형태'));

        $this->assertFalse($this->svc()->save(['id' => $profile->id, 'pay_type' => 'weekly', 'base_rate' => 10])['success']);
    }

    public function test_profile_with_payslips_cannot_be_deleted(): void
    {
        // 이미 지급된 급여의 근거를 지우면 명세서를 다시 설명할 수 없다.
        $this->actingAs($this->user('admin'));
        $employee = $this->employee('지급됨');
        $profile = $this->profileOf($employee);

        $run = PayrollRun::create([
            'code' => 'PR-2026-0001',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-15',
            'status' => 'open',
        ]);
        Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'company_id' => $this->company->id,
            'snap_pay_type' => 'hourly',
            'snap_base_rate' => 30,
            'gross_pay' => 1200,
            'net_pay' => 1000,
        ]);

        $res = $this->svc()->delete($profile->id);

        $this->assertFalse($res['success']);
        $this->assertDatabaseHas('employee_payroll_profiles', ['id' => $profile->id]);
    }

    public function test_unused_profile_can_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $profile = $this->profileOf($this->employee('미사용'));

        $this->assertTrue($this->svc()->delete($profile->id)['success']);
        $this->assertDatabaseMissing('employee_payroll_profiles', ['id' => $profile->id]);
    }

    public function test_hr_manager_cannot_save_but_payroll_clerk_can(): void
    {
        $profile = $this->profileOf($this->employee('권한'));

        $this->actingAs($this->user('hr_manager'));
        $this->assertFalse($this->svc()->save(['id' => $profile->id, 'pay_type' => 'hourly', 'base_rate' => 20])['success']);

        $this->actingAs($this->user('payroll'));
        $this->assertTrue($this->svc()->save(['id' => $profile->id, 'pay_type' => 'hourly', 'base_rate' => 20])['success']);
        $this->assertSame(20.0, (float) $profile->refresh()->base_rate);
    }
}
