<?php

namespace App\Observers;

use App\Models\MobileExpense;
use App\Services\IntegratedDocumentService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 재무관리에서 영수증이 등록되면 문서통합관리 "자재·구매" 폴더에 자동 편철한다.
 *
 * 편철 실패가 지출결의 등록 자체를 막으면 안 되므로 예외는 삼키고 로그만 남긴다.
 */
class MobileExpenseReceiptObserver
{
    public function created(MobileExpense $expense): void
    {
        // 영수증 파일이 없는 지출은 편철 대상이 아니다.
        if (blank($expense->receipt_file) && blank($expense->receipt_path)) {
            return;
        }

        try {
            app(IntegratedDocumentService::class)->fileReceipt($expense);
        } catch (Throwable $e) {
            Log::warning('영수증 문서함 편철 실패(지출 등록은 정상): ' . $e->getMessage(), ['expense_id' => $expense->id]);
        }
    }
}
