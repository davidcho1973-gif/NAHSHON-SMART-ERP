<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\MobileExpense;
use App\Models\PayrollTimesheet;
use App\Models\Project;
use App\Models\Site;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollExpenseConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 연계 수선 R2 — 돈 흐름: 노무비 현장 귀속 · per diem 실근무일 · fringe.
 */
class IntegrationRepairR2Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $siteA;

    private Site $siteB;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'C1', 'name' => '자사', 'status' => 'active']);
        $this->siteA = Site::create(['code' => 'SA', 'name' => 'A현장', 'status' => 'active']);
        $this->siteB = Site::create(['code' => 'SB', 'name' => 'B현장', 'status' => 'active']);
        Project::create(['project_code' => 'PA-01', 'name' => 'A공사', 'construction_type' => 'equipment_setting', 'site_id' => $this->siteA->id]);

        $this->worker = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->siteA->id,
            'first_name' => '현', 'last_name' => '장', 'name' => '장현',
            'employment_status' => 'active',
        ]);
        EmployeePayrollProfile::query()->updateOrCreate(
            ['employee_id' => $this->worker->id],
            ['company_id' => $this->company->id, 'site_id' => $this->siteA->id,
                'pay_type' => 'hourly', 'base_rate' => 30, 'overtime_multiplier' => 1.5,
                'per_diem_rate' => 50, 'fringe_rate' => 5, 'worker_division' => '현장직'],
        );
    }

    private function sheet(Site $site, string $date, int $reg, int $ot = 0, bool $open = false): void
    {
        PayrollTimesheet::create([
            'employee_id' => $this->worker->id, 'company_id' => $this->company->id, 'site_id' => $site->id,
            'work_date' => $date,
            'check_in_at' => Carbon::parse($date.' 07:00:00'),
            'check_out_at' => $open ? null : Carbon::parse($date.' 16:00:00'),
            'regular_minutes' => $reg, 'overtime_minutes' => $ot,
            'payable_minutes' => $reg + $ot, 'status' => 'approved',
        ]);
    }

    public function test_두_현장을_오가면_급여_줄이_현장별로_나뉜다(): void
    {
        // A현장 이틀(8h+8h), B현장 하루(8h) — 예전엔 전부 "입사 때 현장"으로 적혔다.
        $this->sheet($this->siteA, '2026-08-10', 480);
        $this->sheet($this->siteA, '2026-08-11', 480);
        $this->sheet($this->siteB, '2026-08-12', 480);

        $run = app(PayrollCalculator::class)->runPayroll('2026-08-09', 'ALL');

        $lines = $run->payslips->first()->lines;
        $bySite = $lines->groupBy('site_id');
        $this->assertSame(16.0, (float) $bySite[$this->siteA->id]->sum('hours'), 'A현장 16시간');
        $this->assertSame(8.0, (float) $bySite[$this->siteB->id]->sum('hours'), 'B현장 8시간');

        // A현장에는 프로젝트가 하나뿐 → 노무비가 프로젝트까지 귀속된다.
        $projectId = Project::query()->where('project_code', 'PA-01')->value('id');
        $this->assertSame($projectId, $bySite[$this->siteA->id]->first()->project_id);
    }

    public function test_급여_경비도_현장별로_나뉘어_원장에_앉는다(): void
    {
        $this->sheet($this->siteA, '2026-08-10', 480);
        $this->sheet($this->siteB, '2026-08-11', 480);

        $run = app(PayrollCalculator::class)->runPayroll('2026-08-09', 'ALL');
        $run->update(['status' => 'paid']);
        app(PayrollExpenseConnector::class)->syncExpense($run->fresh());

        $wages = MobileExpense::query()->where('accounting_account', '5101 Gross Wages - Field')->get();
        $this->assertCount(2, $wages, '현장 수만큼 나뉘어야 한다 — 한 덩어리면 한 현장 인건비가 두 배가 된다');
        $this->assertEqualsCanonicalizing(
            [$this->siteA->id, $this->siteB->id],
            $wages->pluck('site_id')->all(),
        );
        // 반반 일했으니 금액도 반반.
        $this->assertSame((float) $wages[0]->amount, (float) $wages[1]->amount);
    }

    public function test_per_diem_은_실제_일한_날수로_계산된다(): void
    {
        $this->sheet($this->siteA, '2026-08-10', 480);
        $this->sheet($this->siteA, '2026-08-11', 480);
        // 퇴근 누락(open) 이상 기록 — 예전엔 이런 날만 세서 per diem 이 엉뚱했다.
        $this->sheet($this->siteA, '2026-08-12', 480, 0, true);

        $run = app(PayrollCalculator::class)->runPayroll('2026-08-09', 'ALL');

        // 실근무 3일 × $50 (open 이어도 근무시간이 있으면 일한 날이다).
        $this->assertSame(150.0, (float) $run->payslips->first()->per_diem);
    }

    public function test_차량_리스료가_매월_자동_계상된다(): void
    {
        \App\Models\Vendor::create(['name' => 'Enterprise Fleet', 'status' => 'active']);
        \App\Models\Vehicle::create([
            'company_id' => $this->company->id, 'site_id' => $this->siteA->id,
            'vehicle_type' => 'truck', 'model' => 'F-150', 'plate_number' => 'AZ-1234',
            'vendor' => 'Enterprise Fleet', 'monthly_rate' => 780,
            'rent_start' => '2026-07-01', 'status' => 'active',
        ]);

        $counts = app(\App\Services\Finance\RentalExpenseConnector::class)->accrueMonth('2026-08');

        $this->assertSame(1, $counts['created']);
        $expense = MobileExpense::query()->where('source_ref', 'like', 'vehicle:%:2026-08')->first();
        $this->assertNotNull($expense, '리스료가 시스템에 존재하지 않던 구멍(점검 F)');
        $this->assertSame(780.0, (float) $expense->amount);
        $this->assertSame('6205 Vehicle Lease & Rental', $expense->accounting_account);
        $this->assertNotNull($expense->vendor_id, '벤더 연결(AP·1099 근거)이 붙어야 한다');
        $this->assertSame('pending', $expense->status, '자동 계상은 사람이 승인해야 확정');

        // 멱등 — 다시 돌려도 한 건.
        app(\App\Services\Finance\RentalExpenseConnector::class)->accrueMonth('2026-08');
        $this->assertSame(1, MobileExpense::query()->where('source_ref', 'like', 'vehicle:%')->count());
    }

    public function test_재무_요약에_승인대기_합계가_실린다(): void
    {
        MobileExpense::create([
            'site_id' => $this->siteA->id, 'payment_type' => 'corporate',
            'category' => '5401 Equipment Rental', 'description' => '[자동] 장비 임대료',
            'amount' => 1200, 'expense_date' => '2026-08-15', 'status' => 'pending',
        ]);
        $admin = \App\Models\User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $this->actingAs($admin);

        $stats = \App\Support\SmartCompanyData::financeStats();

        $this->assertSame(1200.0, $stats['pendingSpend'], '자동 계상이 확정 숫자에서 빠져 있다는 사실이 보여야 한다(점검 C)');
        $this->assertArrayHasKey('projectedBalance', $stats);
    }

    public function test_fringe_는_시간당_요율로_쌓여_WH347_에_실린다(): void
    {
        $this->sheet($this->siteA, '2026-08-10', 480, 120); // 8h + 2h OT

        $run = app(PayrollCalculator::class)->runPayroll('2026-08-09', 'ALL');

        $payslip = $run->payslips->first();
        $this->assertSame(50.0, (float) $payslip->fringe_pay, '10시간 × $5');

        $certified = app(PayrollCalculator::class)->certifiedPayrollData($run);
        $this->assertSame(50.0, $certified['rows'][0]['fringe']);
    }
}
