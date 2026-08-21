<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

class PayrollController extends Controller
{
    /** 급여도 돈이다 — 규칙은 AccessPolicy 한 곳에 있다. */
    private const ALLOWED_ROLES = \App\Support\AccessPolicy::MONEY_ROLES;

    public function __construct(private readonly PayrollCalculator $calculator)
    {
    }

    /** Printable WH-347 certified payroll report (browser print → PDF). */
    public function certified(PayrollRun $run): View
    {
        $this->authorizePayroll();

        return view('payroll.certified-payroll', $this->calculator->certifiedPayrollData($run));
    }

    /** Printable individual payslip (browser print → PDF). */
    public function payslip(Payslip $payslip): View
    {
        $this->authorizePayroll();

        $payslip->loadMissing(['run', 'employee.company', 'lines']);

        return view('payroll.payslip', ['payslip' => $payslip]);
    }

    private function authorizePayroll(): void
    {
        $role = auth()->user()?->access_role;

        abort_unless(in_array($role, self::ALLOWED_ROLES, true), Response::HTTP_FORBIDDEN, 'Payroll access required.');
    }
}
