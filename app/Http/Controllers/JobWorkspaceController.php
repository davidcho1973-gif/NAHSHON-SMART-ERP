<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\MobileExpense;
use App\Models\PurchaseRequest;
use App\Services\Admin\JobApprovalService;
use App\Support\JobAccess;
use Illuminate\Http\Request;

class JobWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->account_status === 'active', 403);
        $links = [];
        $links[] = ['label' => '영수증 등록', 'url' => route('expense-app.index'), 'actions' => ['view', 'edit']];
        foreach (['attendance' => ['attendance-logs', '근태·인원 현황'], 'people' => ['employee-admin', '직원 관리'],
            'progress' => ['week-board', '공정·작업 배치'], 'reports' => ['opsroom', '현장 보고'], 'safety' => ['safety', '안전·교육'],
            'payroll' => ['payroll', '급여·정산'], 'finance' => ['finance', '회계·경비'], 'contracts' => ['contract-admin', '계약·기성'],
            'materials' => ['inventory', '자재·입고'], 'office' => ['vehicle', '차량·숙소']] as $module => [$view, $label]) {
            if (JobAccess::can($user, $module)) {
                $links[] = ['label' => $label, 'url' => '/?view='.$view, 'actions' => $user->job_permissions[$module] ?? array_keys(config('job_access.actions'))];
            }
        }
        if (JobAccess::can($user, 'purchasing')) {
            $links[] = ['label' => '구매신청', 'url' => '/attendance-app/purchase-requests', 'actions' => ['view'], 'description' => '본인 구매요청 등록 · 첨부 · 진행상태 확인'];
            if (JobAccess::can($user, 'purchasing', 'edit')) {
                $links[] = ['label' => '구매 처리', 'url' => '/?view=purchase-requests', 'actions' => ['edit']];
            }
        }
        $summary = [];
        if (JobAccess::can($user, 'attendance')) {
            $summary['담당 인원'] = Employee::where('employment_status', 'active')->count();
            $summary['오늘 근태 확인 대기'] = AttendanceLog::whereDate('attendance_date', now())->where('status', 'pending')->count();
        }
        if (JobAccess::can($user, 'finance')) {
            $summary['경비 확인 대기'] = MobileExpense::where('status', 'pending')->count();
        }
        if (JobAccess::can($user, 'purchasing', 'edit')) {
            $summary['구매 요청 대기'] = PurchaseRequest::whereIn('status', ['submitted', 'reviewing', 'needs_info'])->count();
        }
        $data = ['payments' => app(JobApprovalService::class)->payable($user), 'approvals' => app(JobApprovalService::class)->pending($user), 'label' => JobAccess::label($user), 'duties' => array_map(fn ($d) => config('job_access.duties.'.$d.'.label'), $user->job_duties ?? []),
            'summary' => $summary, 'links' => $links, 'permissions' => $user->job_permissions ?? []];
        if ($request->expectsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('attendance-app.workspace', $data)->header('Cache-Control', 'private, no-store');
    }
}
