<?php

namespace App\Services\Finance;

use App\Models\Employee;
use App\Models\MobileExpense;
use App\Services\GeminiReceiptAnalyzer;
use App\Support\FinanceChartOfAccounts;
use App\Support\ReceiptFilePayload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 영수증 앱의 한 방 접수 — 사진 한 장이 ERP 등록과 똑같은 경비가 된다.
 *
 * ERP 본체의 등록(사진 업로드 → AI 판독 → 저장)과 완전히 같은 재료를 쓴다:
 * 같은 판독기(GeminiReceiptAnalyzer), 같은 계정과목 정본(normalize), 같은 원장
 * (mobile_expenses, status=pending). 그래서 앱으로 낸 영수증도 승인 화면·문서함
 * 편철·급여 환급(개인카드) 회로를 전부 그대로 탄다 — 기능을 "떼어 온" 것이지
 * 평행 시스템을 만든 것이 아니다.
 *
 * 원칙:
 *  - 판독이 흐려도 접수는 산다: 금액을 사람이 적어 냈으면 그 금액으로 접수하고,
 *    금액이 아예 없으면 접수하지 않고 금액을 물어본다(0원 경비는 원장 오염이다).
 *  - 자동은 pending 까지 — 승인은 재무가 한다.
 */
class ReceiptQuickIntake
{
    /**
     * @return array{success: bool, code?: string, expense?: MobileExpense, analyzed?: array<string, mixed>}
     */
    public function submit(Employee $employee, UploadedFile $photo, string $paymentType, ?float $manualAmount, ?string $memo): array
    {
        // 재직 검사 — 게이트·작업자앱과 같은 규칙(연계 점검 R1 원칙).
        if ($employee->employment_status !== 'active') {
            return ['success' => false, 'code' => 'not_active'];
        }

        $path = $photo->store('receipts', 'public');
        $absolute = Storage::disk('public')->path($path);

        // ERP 와 같은 판독기. 실패해도 접수를 막지 않는다 — 사진과 수기 금액으로 살린다.
        $ocr = [];
        try {
            $ocr = app(GeminiReceiptAnalyzer::class)->analyze($absolute, $photo->getMimeType());
        } catch (\Throwable $e) {
            Log::warning('영수증 앱 판독 실패(수기 입력으로 진행): '.$e->getMessage());
        }

        $amount = is_numeric($ocr['amount'] ?? null) && (float) $ocr['amount'] > 0
            ? (float) $ocr['amount']
            : $manualAmount;
        if ($amount === null || $amount <= 0) {
            return ['success' => false, 'code' => 'need_amount'];
        }

        $vendor = trim((string) ($ocr['vendor_name'] ?? ''));
        $memo = trim((string) $memo);
        $description = trim(implode(' · ', array_filter([
            $vendor,
            trim((string) ($ocr['description'] ?? '')),
            trim((string) ($ocr['handwritten_notes'] ?? '')),
            $memo,
        ]))) ?: '영수증 앱 제출';

        // 계정과목은 정본을 지난다 — ERP 등록(MobileExpenseController::store)과 동일 순서.
        $account = FinanceChartOfAccounts::normalize(
            (string) (($ocr['accounting_account'] ?? '') ?: ($ocr['category'] ?? '')),
            $vendor.' '.$description,
        );

        $date = (string) ($ocr['date'] ?? '');
        $expenseDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : now()->toDateString();

        $expense = MobileExpense::create([
            'company_id' => $employee->company_id,
            'vendor_id' => \App\Models\Vendor::matchByName($vendor),
            'site_id' => $employee->site_id,
            'employee_id' => $employee->id,
            'payment_type' => $paymentType === 'corporate' ? 'corporate' : 'personal',
            'category' => $account,
            'accounting_account' => $account,
            'description' => $description,
            'amount' => round($amount, 2),
            'expense_date' => $expenseDate,
            'receipt_path' => '/storage/'.$path,
            // 원본을 DB 에도 넣는다(ERP 등록과 동일) — 배포가 디스크를 초기화해도 장부 근거는 남는다.
            'receipt_file' => ReceiptFilePayload::encode((string) Storage::disk('public')->get($path)),
            'receipt_mime_type' => $photo->getMimeType() ?: 'image/jpeg',
            'receipt_original_name' => $photo->getClientOriginalName() ?: basename($path),
            'ocr_data' => $ocr + ['source' => 'expense-app'],
            'status' => 'pending',
        ]);

        return [
            'success' => true,
            'expense' => $expense,
            'analyzed' => [
                'vendor' => $vendor,
                'amount' => (float) $expense->amount,
                'account' => $account,
                'date' => $expenseDate,
                'ocrWorked' => $ocr !== [] && is_numeric($ocr['amount'] ?? null),
            ],
        ];
    }
}
