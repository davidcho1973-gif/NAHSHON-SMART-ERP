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

            $officeWages = 0.0;
            $officeTaxes = 0.0;

            // 현장 노무비는 현장별로 나눠 앉힌다. 예전에는 전 인원을 한 덩어리로 합쳐
            // "첫 번째 명세서의 첫 현장"에 전액을 적었다 — 두 현장을 오간 기간이면
            // 한 현장의 인건비가 두 배로, 다른 현장은 0 으로 보였다(연계 점검 A).
            // 급여 명세 줄(payslip_lines)이 이제 타임시트 기준 현장 분해를 담고 있으므로
            // 그 비례로 임금·세금을 나눈다.
            $siteWages = [];   // site_id(0=미지정) => 임금
            $siteTaxes = [];
            $siteProjects = []; // site_id => project_id(줄에서 가져온 귀속)

            $defaultCompanyId = null;

            foreach ($run->payslips as $payslip) {
                if (! $defaultCompanyId) {
                    $defaultCompanyId = $payslip->company_id;
                }

                $division = $payslip->snap_division ?: '';
                $isField = ($division !== '관리자');

                $gross = (float) $payslip->gross_pay;
                // Employer payroll taxes: match worker FICA + Medicare (7.65%).
                $employerTax = (float) $payslip->fica + (float) $payslip->medicare;

                if (! $isField) {
                    $officeWages += $gross;
                    $officeTaxes += $employerTax;

                    continue;
                }

                // 명세서의 현장별 줄 합계로 비례 배분. 줄이 없으면(시간 기록 없는 급여) 미지정(0).
                $lineTotal = (float) $payslip->lines->sum('amount');
                if ($lineTotal <= 0) {
                    $siteWages[0] = ($siteWages[0] ?? 0) + $gross;
                    $siteTaxes[0] = ($siteTaxes[0] ?? 0) + $employerTax;

                    continue;
                }

                foreach ($payslip->lines->groupBy(fn ($l) => (int) ($l->site_id ?? 0)) as $siteId => $lines) {
                    $share = (float) $lines->sum('amount') / $lineTotal;
                    $siteWages[$siteId] = ($siteWages[$siteId] ?? 0) + $gross * $share;
                    $siteTaxes[$siteId] = ($siteTaxes[$siteId] ?? 0) + $employerTax * $share;
                    if (! isset($siteProjects[$siteId])) {
                        $siteProjects[$siteId] = $lines->first()->project_id;
                    }
                }
            }

            $payDate = $run->pay_date ?: Carbon::now()->toDateString();
            $periodStr = sprintf('%s to %s', $run->period_start->toDateString(), $run->period_end->toDateString());

            // Field Wages (5101) + Field Employer Taxes (5102) — 현장별 한 건씩.
            foreach ($siteWages as $siteId => $wages) {
                if (round($wages, 2) <= 0) {
                    continue;
                }
                $siteNote = $siteId ? '' : ' (site unassigned)';
                MobileExpense::create([
                    'company_id' => $defaultCompanyId,
                    'site_id' => $siteId ?: null,
                    'project_id' => $siteProjects[$siteId] ?? null,
                    'payment_type' => 'corporate',
                    'category' => '5101 Gross Wages - Field',
                    'accounting_account' => '5101 Gross Wages - Field',
                    'class' => 'Field',
                    'description' => "[Payroll] Field Gross Wages for period {$periodStr} (Run: {$run->code}){$siteNote}",
                    'amount' => round($wages, 2),
                    'expense_date' => $payDate,
                    'status' => 'approved',
                    'payroll_run_id' => $run->id,
                ]);

                $taxes = (float) ($siteTaxes[$siteId] ?? 0);
                if (round($taxes, 2) > 0) {
                    MobileExpense::create([
                        'company_id' => $defaultCompanyId,
                        'site_id' => $siteId ?: null,
                        'project_id' => $siteProjects[$siteId] ?? null,
                        'payment_type' => 'corporate',
                        'category' => '5102 Payroll Taxes - Field',
                        'accounting_account' => '5102 Payroll Taxes - Field',
                        'class' => 'Field',
                        'description' => "[Payroll] Field Employer Taxes (FICA matching) for period {$periodStr} (Run: {$run->code}){$siteNote}",
                        'amount' => round($taxes, 2),
                        'expense_date' => $payDate,
                        'status' => 'approved',
                        'payroll_run_id' => $run->id,
                    ]);
                }
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
