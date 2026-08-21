<?php

namespace App\Services\Finance;

use App\Models\MobileExpense;
use App\Models\User;

/**
 * 경비 승인 규칙의 정본 — 누가, 어떤 상태로 바꿀 수 있는가.
 *
 * 문서함에서 자동으로 넘어온 비용은 '승인대기' 로 앉는데, 승인하려면 목록을 떠나
 * 다른 화면으로 가야 했다. 재무 목록에서 바로 처리하게 하면서 같은 규칙을
 * 두 군데(영수증 목록의 원클릭, SPA 재무 목록)에 복사하지 않도록 여기로 모은다 —
 * 규칙이 두 벌이면 언젠가 한쪽만 고쳐져 서로 다른 승인이 된다.
 */
class ExpenseReviewService
{
    /** 경비 전체를 승인·반려·지급 처리할 수 있는 역할. */
    public const MANAGER_ROLES = ['super_admin', 'admin', 'hr_manager', 'payroll'];

    public const DECISIONS = ['approved', 'rejected', 'paid'];

    public const DECISION_LABELS = ['approved' => '승인', 'rejected' => '반려', 'paid' => '지급완료'];

    public function canReview(?User $user): bool
    {
        return in_array($user?->access_role, self::MANAGER_ROLES, true);
    }

    /**
     * 한 건을 즉시 승인/반려/지급완료 처리한다. 권한·결정값 검증까지 여기서 한다.
     *
     * @return array{success: bool, message: string, status?: string}
     */
    public function review(MobileExpense $expense, string $decision, ?User $reviewer): array
    {
        if (! $this->canReview($reviewer)) {
            return ['success' => false, 'message' => '경비를 승인·반려할 권한이 없습니다.'];
        }

        if (! in_array($decision, self::DECISIONS, true)) {
            return ['success' => false, 'message' => '알 수 없는 처리 유형입니다.'];
        }

        $expense->update([
            'status' => $decision,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'paid_by_user_id' => $decision === 'paid' ? $reviewer->id : $expense->paid_by_user_id,
            'paid_at' => $decision === 'paid' ? now() : $expense->paid_at,
        ]);

        return [
            'success' => true,
            'message' => '영수증을 '.self::DECISION_LABELS[$decision].' 처리했습니다.',
            'status' => $decision,
        ];
    }
}
