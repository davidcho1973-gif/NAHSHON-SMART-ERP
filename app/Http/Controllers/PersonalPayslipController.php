<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Scopes\JobDataScope;
use App\Support\WorkerDeviceSession;
use Illuminate\Http\Request;

class PersonalPayslipController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize($request);
        $payslips = Payslip::where('employee_id', $request->user()->employee_id)->where('status', 'paid')->with(['run' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code', 'period_start', 'period_end', 'pay_date')])->latest('id')->get();

        return response()->view('attendance-app.payslips', compact('payslips'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id)
    {
        $this->authorize($request);
        $payslip = Payslip::where('employee_id', $request->user()->employee_id)->where('status', 'paid')->findOrFail($id);
        $payslip->setRelation('run', PayrollRun::withoutGlobalScopes()->select('id', 'code', 'period_start', 'period_end', 'pay_date')->find($payslip->payroll_run_id));
        $payslip->loadMissing(['employee.company', 'lines' => fn ($q) => $q->withoutGlobalScope(JobDataScope::class)]);

        return response()->view('payroll.payslip', compact('payslip'))->header('Cache-Control', 'private, no-store');
    }

    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->employee_id && ! $request->filled('as') && ! WorkerDeviceSession::isDeviceOnly($request), 403,
            '본인 PIN 또는 정식 로그인 후 급여명세서를 확인하세요.');
    }
}
