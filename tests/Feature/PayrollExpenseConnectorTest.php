<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\Payroll\PayrollExpenseConnector;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PayrollExpenseConnectorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $company;
    private Site $site;
    private Employee $fieldEmployee;
    private Employee $officeEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'code' => 'NAHSHON',
            'name' => 'NAHSHON MEP',
            'status' => 'active',
        ]);

        $this->site = Site::create([
            'company_id' => $this->company->id,
            'code' => 'LGES-AZ',
            'name' => 'LGES Arizona Battery',
            'status' => 'active',
        ]);

        // Field Employee (Journeyman, etc.)
        $this->fieldEmployee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'James',
            'last_name' => 'Field',
            'email' => 'james.field@example.com',
            'role' => 'Journeyman',
            'nationality' => 'Korea',
            'employment_status' => 'active',
        ]);

        // Office Employee (Manager, Admin, etc.)
        $this->officeEmployee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'Sarah',
            'last_name' => 'Office',
            'email' => 'sarah.office@example.com',
            'role' => 'Site Manager',
            'nationality' => 'USA',
            'employment_status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin@example.com',
            'access_role' => 'admin',
            'account_status' => 'active',
        ]);
    }

    public function test_it_creates_expenses_on_payroll_payout(): void
    {
        $run = PayrollRun::create([
            'code' => 'PR-2026-0001',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-15',
            'site_scope' => 'ALL',
            'status' => 'calculated',
        ]);

        // Payslip for Field Employee
        // Gross: $2000, FICA: $124, Medicare: $29 -> Employer tax matching FICA = $153
        $run->payslips()->create([
            'employee_id' => $this->fieldEmployee->id,
            'company_id' => $this->company->id,
            'snap_pay_type' => 'hourly',
            'snap_base_rate' => 25.00,
            'snap_division' => '한국인', // Non-admin, so Field
            'gross_pay' => 2000.00,
            'fica' => 124.00,
            'medicare' => 29.00,
            'net_pay' => 1847.00,
            'status' => 'calculated',
        ]);

        // Payslip for Office Employee
        // Gross: $3000, FICA: $186, Medicare: $43.50 -> Employer tax matching FICA = $229.50
        $run->payslips()->create([
            'employee_id' => $this->officeEmployee->id,
            'company_id' => $this->company->id,
            'snap_pay_type' => 'salary',
            'snap_base_rate' => 3000.00,
            'snap_division' => '관리자', // Admin role -> Office
            'gross_pay' => 3000.00,
            'fica' => 186.00,
            'medicare' => 43.50,
            'net_pay' => 2770.50,
            'status' => 'calculated',
        ]);

        // Invoke the pay api through SmartCompanyData
        $response = $this->actingAs($this->admin)->postJson('/smart-company-api/api_payPayroll', [
            'args' => [$run->id],
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $run->refresh();
        $this->assertSame('paid', $run->status);
        $this->assertSame('paid', $run->payslips()->first()->status);

        // Verify expenses created
        $this->assertDatabaseHas('mobile_expenses', [
            'payroll_run_id' => $run->id,
            'category' => '5101 Gross Wages - Field',
            'amount' => 2000.00,
            'class' => 'Field',
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('mobile_expenses', [
            'payroll_run_id' => $run->id,
            'category' => '5102 Payroll Taxes - Field',
            'amount' => 153.00, // 124.00 + 29.00
            'class' => 'Field',
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('mobile_expenses', [
            'payroll_run_id' => $run->id,
            'category' => '6101 Office Salaries',
            'amount' => 3000.00,
            'class' => 'Office',
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('mobile_expenses', [
            'payroll_run_id' => $run->id,
            'category' => '6102 Employer Payroll Taxes - Office',
            'amount' => 229.50, // 186.00 + 43.50
            'class' => 'Office',
            'status' => 'approved',
        ]);
    }

    public function test_it_deletes_old_expenses_on_re_sync(): void
    {
        $run = PayrollRun::create([
            'code' => 'PR-2026-0002',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-15',
            'site_scope' => 'ALL',
            'status' => 'paid',
        ]);

        // Create pre-existing duplicate expense
        MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'payment_type' => 'corporate',
            'category' => '5101 Gross Wages - Field',
            'description' => 'Duplicate field wages',
            'amount' => 9999.00,
            'expense_date' => '2026-06-20',
            'status' => 'approved',
            'payroll_run_id' => $run->id,
        ]);

        $connector = new PayrollExpenseConnector();
        $connector->syncExpense($run);

        // Pre-existing expense should be deleted, and none re-created because payslips are empty
        $this->assertDatabaseMissing('mobile_expenses', [
            'amount' => 9999.00,
            'payroll_run_id' => $run->id,
        ]);
    }
}
