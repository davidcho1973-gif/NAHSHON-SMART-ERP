<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\MobileExpense;
use App\Support\FinanceChartOfAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PayrollExpenseConnector
{
    /**
     * Synchronize a PayrollRun's paid amount into the MobileExpense accounting system.
     * Generates:
     *   1. Gross Wages - Field (5101)
     *   2. Payroll Taxes - Field (5102)
     *   3. Office Salaries (6101)
     *   4. Employer Payroll Taxes - Office (6102)
     */
    public function syncExpense(PayrollRun $run): void
    {
        DB::transaction(function () use ($run): void {
            // 1. Idempotency: Clean up any existing expense records for this run.
            MobileExpense::where('payroll_run_id', $run->id)->delete();

            if ($run->status !== 'paid') {
                return;
            }

            $run->load(['payslips.employee', 'payslips.lines']);

            $fieldWages = 0.0;
            $fieldTaxes = 0.0;
            $officeWages = 0.0;
            $officeTaxes = 0.0;

            // Fetch default company & site for expenses grouping (usually first active or default)
            $defaultCompanyId = null;
            $defaultSiteId = null;

            foreach ($run->payslips as $payslip) {
                if (! $defaultCompanyId) {
                    $defaultCompanyId = $payslip->company_id;
                }

                $division = $payslip->snap_division ?: '';
                $isField = ($division !== '관리자');

                // Wages (Gross Pay)
                $gross = (float) $payslip->gross_pay;

                // Employer Payroll Taxes (FICA matching: Social Security 6.2% + Medicare 1.45% = 7.65% matching worker)
                // We match worker FICA + Medicare, plus add Arizona SUTA/FUTA estimation (0.6% FUTA + ~2.0% SUTA)
                $workerFica = (float) $payslip->fica;
                $workerMedicare = (float) $payslip->medicare;
                
                // Arizona SUTA limit is $8,000, FUTA is $7,000. For estimation, we matching worker FICA/Medicare exactly (7.65% total)
                $employerTax = $workerFica + $workerMedicare;

                if ($isField) {
                    $fieldWages += $gross;
                    $fieldTaxes += $employerTax;
                } else {
                    $officeWages += $gross;
                    $officeTaxes += $employerTax;
                }

                // If no default site, pick from lines or employee
                if (! $defaultSiteId && $payslip->lines->isNotEmpty()) {
                    $defaultSiteId = $payslip->lines->first()->site_id;
                }
            }

            $payDate = $run->pay_date ?: Carbon::now()->toDateString();
            $periodStr = sprintf('%s to %s', $run->period_start->toDateString(), $run->period_end->toDateString());

            // Create Field Wages (5101)
            if ($fieldWages > 0) {
                MobileExpense::create([
                    'company_id' => $defaultCompanyId,
                    'site_id' => $defaultSiteId,
                    'payment_type' => 'corporate',
                    'category' => '5101 Gross Wages - Field',
                    'accounting_account' => '5101 Gross Wages - Field',
                    'class' => 'Field',
                    'description' => "[Payroll] Field Gross Wages for period {$periodStr} (Run: {$run->code})",
                    'amount' => round($fieldWages, 2),
                    'expense_date' => $payDate,
                    'status' => 'approved',
                    'payroll_run_id' => $run->id,
                ]);
            }

            // Create Field Taxes (5102)
            if ($fieldTaxes > 0) {
                MobileExpense::create([
                    'company_id' => $defaultCompanyId,
                    'site_id' => $defaultSiteId,
                    'payment_type' => 'corporate',
                    'category' => '5102 Payroll Taxes - Field',
                    'accounting_account' => '5102 Payroll Taxes - Field',
                    'class' => 'Field',
                    'description' => "[Payroll] Field Employer Taxes (FICA matching) for period {$periodStr} (Run: {$run->code})",
                    'amount' => round($fieldTaxes, 2),
                    'expense_date' => $payDate,
                    'status' => 'approved',
                    'payroll_run_id' => $run->id,
                ]);
            }

            // Create Office Wages (6101)
            if ($officeWages > 0) {
                MobileExpense::create([
                    'company_id' => $defaultCompanyId,
                    'site_id' => null, // Office expenses are general/global
                    'payment_type' => 'corporate',
                    'category' => '6101 Office Salaries',
                    'accounting_account' => '6101 Office Salaries',
                    'class' => 'Office',
                    'description' => "[Payroll] Office Salaries for period {$periodStr} (Run: {$run->code})",
                    'amount' => round($officeWages, 2),
                    'expense_date' => $payDate,
                    'status' => 'approved',
                    'payroll_run_id' => $run->id,
                ]);
            }

            // Create Office Taxes (6102)
            if ($officeTaxes > 0) {
                MobileExpense::create([
                    'company_id' => $defaultCompanyId,
                    'site_id' => null,
                    'payment_type' => 'corporate',
                    'category' => '6102 Employer Payroll Taxes - Office',
                    'accounting_account' => '6102 Employer Payroll Taxes - Office',
                    'class' => 'Office',
                    'description' => "[Payroll] Office Employer Taxes (FICA matching) for period {$periodStr} (Run: {$run->code})",
                    'amount' => round($officeTaxes, 2),
                    'expense_date' => $payDate,
                    'status' => 'approved',
                    'payroll_run_id' => $run->id,
                ]);
            }
        });
    }

    /**
     * Clean up all synchronized expenses if a PayrollRun is deleted or rolled back from paid status.
     */
    public function removeExpense(int $payrollRunId): void
    {
        MobileExpense::where('payroll_run_id', $payrollRunId)->delete();
    }
}
