<?php

namespace App\Services\Admin;

use App\Models\AuthEvent;
use App\Models\MobileExpense;
use App\Models\PayrollRun;
use App\Models\ProjectContract;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Finance\ExpenseReviewService;
use App\Support\JobAccess;
use App\Support\SmartCompanyData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class JobApprovalService
{
    public static function version(Model $row): string
    {
        return hash('sha256', json_encode($row->getRawOriginal()));
    }

    public function pending(User $user): array
    {
        $rows = [];
        if (JobAccess::can($user, 'purchasing', 'approve')) {
            foreach (PurchaseRequest::with('lines')->where('approval_required', true)->where('approval_status', 'pending')->whereNotIn('status', ['cancelled', 'received'])->limit(100)->get() as $row) {
                $rows[] = ['module' => 'purchasing', 'id' => $row->id, 'version' => self::version($row),
                    'title' => '구매 #'.$row->id.' · '.$row->lines->pluck('name')->implode(', '),
                    'detail' => $row->lines->map(fn ($line) => $line->name.' · '.$line->quantity.' '.$line->unit)->implode(' / ').' · '.$row->note,
                    'url' => null, 'budget' => true];
            }
        }
        if (JobAccess::can($user, 'contracts', 'approve')) {
            foreach (ProjectContract::where('status', 'under_review')->limit(100)->get() as $row) {
                $rows[] = ['module' => 'contracts', 'id' => $row->id, 'version' => self::version($row), 'title' => $row->title,
                    'detail' => $row->currency.' '.$row->original_amount.' · 변경 승인액 '.$row->approved_change_amount,
                    'url' => '/?view=contract-admin', 'budget' => false];
            }
        }
        if (JobAccess::can($user, 'payroll', 'approve')) {
            foreach (PayrollRun::whereIn('status', ['draft', 'calculated'])->limit(100)->get() as $row) {
                $rows[] = ['module' => 'payroll', 'id' => $row->id, 'version' => self::version($row), 'title' => $row->code,
                    'detail' => $row->period_start->format('Y-m-d').' ~ '.$row->period_end->format('Y-m-d').' · $'.$row->total_net,
                    'url' => '/?view=payroll', 'budget' => false];
            }
        }
        if (JobAccess::can($user, 'finance', 'approve')) {
            foreach (MobileExpense::where('status', 'pending')->limit(100)->get() as $row) {
                $rows[] = ['module' => 'finance', 'id' => $row->id, 'version' => self::version($row), 'title' => $row->description,
                    'detail' => '$'.$row->amount, 'url' => '/?view=finance', 'budget' => false];
            }
        }

        return $rows;
    }

    public function payable(User $user): array
    {
        $rows = [];
        if (JobAccess::can($user, 'payroll', 'pay')) {
            foreach (PayrollRun::where('status', 'approved')->limit(100)->get() as $row) {
                $rows[] = ['module' => 'payroll', 'id' => $row->id, 'version' => self::version($row), 'title' => $row->code, 'detail' => '지급액 $'.$row->total_net];
            }
        }
        if (JobAccess::can($user, 'finance', 'pay')) {
            foreach (MobileExpense::where('status', 'approved')->limit(100)->get() as $row) {
                $rows[] = ['module' => 'finance', 'id' => $row->id, 'version' => self::version($row), 'title' => $row->description, 'detail' => '지급액 $'.$row->amount];
            }
        }

        return $rows;
    }

    public function pay(User $user, string $module, int $id, array $input): array
    {
        abort_unless(in_array($module, ['payroll', 'finance'], true) && JobAccess::can($user, $module, 'pay'), 403);
        $data = Validator::make($input, ['version' => 'required|string', 'decision' => 'required|in:paid'])->validate();

        return DB::transaction(function () use ($user, $module, $id, $data): array {
            $model = $module === 'payroll' ? PayrollRun::class : MobileExpense::class;
            $row = $model::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'approved' && hash_equals(self::version($row), $data['version']), 409, '승인된 최신 자료를 다시 확인하세요.');
            $result = $module === 'payroll' ? SmartCompanyData::payPayroll($id) : app(ExpenseReviewService::class)->review($row, 'paid', $user);
            abort_unless($result['success'], 422, $result['error'] ?? $result['message'] ?? '처리 실패');
            AuthEvent::record('job_payment_recorded', user: $user, actor: $user, method: 'job-profile', request: request(), note: json_encode(['module' => $module, 'id' => $id]));

            return ['success' => true, 'message' => '지급 완료로 기록했습니다.'];
        });
    }

    public function decide(User $user, string $module, int $id, array $input): array
    {
        abort_unless(in_array($module, ['purchasing', 'contracts', 'payroll', 'finance'], true) && JobAccess::can($user, $module, 'approve'), 403);
        $data = Validator::make($input, ['decision' => ['required', Rule::in(['approved', 'rejected'])], 'version' => 'required|string',
            'budget' => 'nullable|numeric|min:0.01|max:9999999999999.99', 'currency' => ['nullable', Rule::in(['USD', 'KRW', 'EUR', 'CAD'])]])->validate();

        return DB::transaction(function () use ($user, $module, $id, $data): array {
            $model = match ($module) {
                'purchasing' => PurchaseRequest::class, 'contracts' => ProjectContract::class, 'payroll' => PayrollRun::class, 'finance' => MobileExpense::class
            };
            $row = $model::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(self::version($row), $data['version']), 409, '내용이 변경되었습니다. 다시 확인하세요.');
            if ($module === 'purchasing') {
                abort_unless($row->approval_required && $row->approval_status === 'pending', 409);
                abort_if($row->requested_by_id === $user->id && $user->access_role !== 'super_admin', 403, '본인 구매 요청은 다른 승인자가 확인해야 합니다.');
                abort_if($data['decision'] === 'approved' && (empty($data['budget']) || empty($data['currency'])), 422, '구매 한도와 통화를 입력하세요.');
                $row->update(['approval_status' => $data['decision'], 'approved_budget' => $data['decision'] === 'approved' ? $data['budget'] : null,
                    'approval_currency' => $data['currency'] ?? null, 'approved_by_id' => $user->id, 'approved_at' => now(), 'version' => $row->version + 1]);
                $row->events()->create(['actor_id' => $user->id, 'action' => 'approval', 'status' => $row->status, 'message' => $data['decision'] === 'approved' ? '구매 예산이 승인되었습니다.' : '구매 요청이 반려되었습니다.', 'data' => ['decision' => $data['decision'], 'budget' => $data['budget'] ?? null]]);
            } elseif ($module === 'contracts') {
                abort_unless($row->status === 'under_review', 409, '검토 중인 계약만 승인할 수 있습니다.');
                $row->update(['status' => $data['decision'] === 'approved' ? 'active' : 'draft']);
            } elseif ($module === 'payroll') {
                abort_unless($data['decision'] === 'approved', 422, '급여 수정은 담당자에게 요청하세요.');
                $result = SmartCompanyData::approvePayroll($id);
                abort_unless($result['success'], 422, $result['error'] ?? '승인 실패');
            } else {
                $result = app(ExpenseReviewService::class)->review($row, $data['decision'], $user);
                abort_unless($result['success'], 422, $result['message']);
            }
            AuthEvent::record('job_approval', user: $user, actor: $user, method: 'job-profile', request: request(), note: json_encode(['module' => $module, 'id' => $id, 'decision' => $data['decision']]));

            return ['success' => true, 'message' => '처리했습니다.'];
        });
    }
}
