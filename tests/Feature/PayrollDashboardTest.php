<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollTimesheet;
use App\Models\Site;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $company;
    private Site $site;
    private Employee $hourly;
    private Employee $dispatched;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'NAHSHON MEP', 'code' => 'NMEP']);
        $this->site = Site::create(['name' => 'LG AZ Plant', 'code' => 'LGES-AZ']);

        $this->hourly = Employee::create([
            'name' => 'Carlos Rivera',
            'employee_number' => 'EMP-3001',
            'badge_number' => '3001',
            'role' => 'Pipefitter',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'active',
        ]);

        $this->dispatched = Employee::create([
            'name' => 'Min Lee',
            'employee_number' => 'EMP-3002',
            'badge_number' => '3002',
            'role' => 'Welder',
            'nationality' => 'Korea',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'active',
        ]);

        EmployeePayrollProfile::create([
            'employee_id' => $this->hourly->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'pay_type' => 'hourly',
            'base_rate' => 50,
        ]);

        EmployeePayrollProfile::create([
            'employee_id' => $this->dispatched->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'pay_type' => 'hourly',
            'base_rate' => 40,
            'is_dispatched' => true,
        ]);

        // Hourly worker: 8h reg + 2h OT, fully clocked in/out.
        PayrollTimesheet::create([
            'employee_id' => $this->hourly->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'work_date' => '2026-06-16',
            'check_in_at' => '2026-06-16 07:00:00',
            'check_out_at' => '2026-06-16 17:00:00',
            'regular_minutes' => 480,
            'overtime_minutes' => 120,
            'status' => 'approved',
        ]);

        // Dispatched worker: 8h reg, MISSING check-out → anomaly.
        PayrollTimesheet::create([
            'employee_id' => $this->dispatched->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'work_date' => '2026-06-17',
            'check_in_at' => '2026-06-17 07:00:00',
            'check_out_at' => null,
            'regular_minutes' => 480,
            'overtime_minutes' => 0,
            'status' => 'approved',
        ]);

        $this->admin = User::factory()->create(['access_role' => 'payroll']);
    }

    public function test_dashboard_returns_real_aggregated_payroll(): void
    {
        $res = $this->actingAs($this->admin)
            ->postJson('/smart-company-api/api_getPayrollDashboard', [
                'args' => ['2026-06-15'],
                'siteId' => 'ALL',
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('period.totalDays', 14);

        // 8×50 + 2×50×1.5 = 550 ; 8×40 = 320 ; total 870
        $res->assertJsonPath('totals.gross', 870);
        $res->assertJsonPath('totals.regHours', 16);
        $res->assertJsonPath('totals.otHours', 2);
        $res->assertJsonPath('totals.headcount', 2);

        // Company matrix carries the nested totals + division breakdown the SPA expects.
        $company = $res->json('companies.0');
        $this->assertSame(870.0, (float) $company['totals']['gross']);
        $this->assertArrayHasKey('관리자', $company['divides']);
        $this->assertArrayHasKey('한국인', $company['divides']);
        $this->assertArrayHasKey('외국인', $company['divides']);
        $this->assertSame(1, $company['divides']['한국인']['count']);  // dispatched
        $this->assertSame(1, $company['divides']['외국인']['count']);  // local

        // Per-employee rows expose every field renderPayroll reads.
        $employees = collect($res->json('employees'));
        $this->assertCount(2, $employees);
        $top = $employees->firstWhere('badgeId', '3001');
        $this->assertSame(550.0, (float) $top['gross']);
        $this->assertSame(50.0, (float) $top['rate']);
        $this->assertSame('hourly', $top['basis']);

        // Missing check-out surfaces as an anomaly.
        $this->assertCount(1, $res->json('anomalies'));
        $res->assertJsonPath('anomalies.0.type', '미체크아웃');
        $res->assertJsonPath('anomalies.0.badgeId', '3002');
    }

    public function test_run_payroll_persists_payslips_with_snapshot_and_deductions(): void
    {
        $run = app(PayrollCalculator::class)->runPayroll('2026-06-15', 'ALL', $this->admin->id);

        $this->assertSame(2, $run->headcount);
        $this->assertSame('870.00', (string) $run->total_gross);

        $this->assertDatabaseHas('payslips', [
            'payroll_run_id' => $run->id,
            'employee_id' => $this->hourly->id,
            'snap_pay_type' => 'hourly',
            'gross_pay' => 550,
        ]);

        // payslip_lines feed job-costing + certified payroll (REG and OT split out).
        $payslip = $run->payslips()->where('employee_id', $this->hourly->id)->first();
        $this->assertSame(2, $payslip->lines()->count());
        $this->assertGreaterThan(0, $payslip->net_pay);
        $this->assertLessThan($payslip->gross_pay, $payslip->net_pay);
    }
}
