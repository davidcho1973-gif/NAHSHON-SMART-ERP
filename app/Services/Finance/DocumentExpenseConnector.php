<?php

namespace App\Services\Finance;

use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Support\ReceiptFilePayload;
use Illuminate\Support\Facades\Storage;

/**
 * 문서함에 들어온 "돈이 나간 문서"를 재무(경비 원장)로 넘긴다.
 *
 * 영수증·공급사 인보이스·급여 지급 내역을 문서함에 올리면 AI 가 분류·편철까지는
 * 했지만 <b>거기서 끝났다.</b> 문서는 "정리완료" 인데 그 돈은 재무 어디에도 없어서,
 * 같은 내용을 재무 화면에 사람이 다시 입력해야 했다 — 두 번 일하거나, 안 하면
 * 지출이 장부에서 빠졌다.
 *
 * 원인은 문서 분석이 금액을 <b>구조화해서 뽑지 않았다</b>는 것이다. 금액이 key_facts
 * 문장 속에만 있으니 코드가 집어갈 수가 없었다. 그래서 분석 스키마에 money(방향·금액·
 * 수취인·용도·분류)를 추가하고, 분석이 끝나는 지점 한 곳에서 이 커넥터가 돈이 나간
 * 문서(flow=out)를 경비로 등록한다.
 *
 * 원칙(다른 커넥터와 동일):
 *  - 멱등: source_ref("document:{id}")가 같으면 다시 만들지 않는다. 재분석해도 한 건이다.
 *  - 자동 생성 건은 'pending' — 사람이 재무 화면에서 승인해야 장부에 확정된다.
 *    급여처럼 급여 모듈이 이미 원장에 넣는 항목과 겹칠 수 있는데, 둘 다 pending 으로
 *    나란히 보이므로 사람이 하나를 반려하면 된다. 자동으로 합치지 않는 이유는,
 *    어느 쪽이 맞는지 코드가 판단하면 틀렸을 때 지출이 소리 없이 사라지기 때문이다.
 *  - 이미 승인/지급된 건은 절대 덮어쓰지 않는다.
 */
class DocumentExpenseConnector
{
    /** AI 의 분류 힌트 → 계정. 모르는 값은 기타로 — 계정을 지어내지 않는다. */
    private const CATEGORY_MAP = [
        'payroll' => '5101 Gross Wages - Field',
        'materials' => '5201 Materials & Supplies',
        'equipment' => '5401 Equipment Rental',
        'lodging' => '5503 Crew Lodging & Housing',
        'fuel' => '5502 Vehicle & Fuel',
        'meals' => '5504 Meals & Per Diem',
        'utilities' => '5601 Utilities & Communications',
    ];

    private const FALLBACK_CATEGORY = '5900 Other Expenses';

    /** 돈이 나간 문서가 아니면 아무것도 하지 않는다. */
    public function sync(IntelligentDocument $document): void
    {
        $money = (array) (($document->ai_payload ?? [])['money'] ?? []);
        $flow = strtolower(trim((string) ($money['flow'] ?? '')));
        $amount = is_numeric($money['amount'] ?? null) ? (float) $money['amount'] : 0.0;

        if ($flow !== 'out' || $amount <= 0) {
            return;
        }

        $sourceRef = "document:{$document->id}";
        $existing = MobileExpense::query()->where('source_ref', $sourceRef)->first();

        // 사람이 이미 승인/지급한 건은 손대지 않는다 — 장부 확정 후 소급 변경 금지.
        if ($existing && $existing->status !== 'pending') {
            return;
        }

        $hint = strtolower(trim((string) ($money['category_hint'] ?? '')));
        $account = self::CATEGORY_MAP[$hint] ?? self::FALLBACK_CATEGORY;

        $payee = trim((string) ($money['payee'] ?? '')) ?: trim((string) $document->sender);
        $purpose = trim((string) ($money['purpose'] ?? ''));
        $paidOn = (string) ($money['paid_on'] ?? '');
        $currency = strtoupper(trim((string) ($money['currency'] ?? '')));

        $attributes = [
            'company_id' => $document->company_id,
            'site_id' => $document->site_id,
            'project_id' => $document->project_id,
            'payment_type' => 'corporate',
            'category' => $account,
            'accounting_account' => $account,
            'description' => '[문서함] '.($document->title ?: $document->original_file_name)
                .($payee !== '' ? ' · '.$payee : '')
                .($purpose !== '' ? ' · '.$purpose : '')
                .($currency !== '' && $currency !== 'USD' ? " ({$currency})" : ''),
            'amount' => $amount,
            'expense_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) === 1
                ? $paidOn
                : ($document->document_date?->toDateString() ?? now()->toDateString()),
            'ocr_data' => [
                'source' => 'document-hub',
                'document_id' => $document->id,
                'document_uuid' => $document->uuid,
                'document_type' => $document->document_type,
                'category_hint' => $hint,
            ],
        ];

        // 영수증 원본을 경비 줄에 사본으로 붙인다. 파일이 문서함에만 있으면 경비
        // 목록에 사진 버튼이 안 떠서, 승인하는 사람이 근거를 못 보고 승인하게 된다.
        $attributes += $this->receiptCopy($document);

        if (! $existing) {
            MobileExpense::query()->create($attributes + ['source_ref' => $sourceRef, 'status' => 'pending']);

            return;
        }

        if ((float) $existing->amount !== $amount
            || (string) $existing->description !== (string) $attributes['description']
            || (blank($existing->receipt_file) && isset($attributes['receipt_file']))) {
            // 지출일은 처음 잡힌 값을 지킨다 — 재분석 때마다 날짜가 밀리면 안 된다.
            unset($attributes['expense_date']);
            $existing->update($attributes);
        }
    }

    /**
     * 문서 원본을 경비의 영수증 칸(DB 보관)으로 복사한다.
     *
     * DB 에 넣는 이유는 기존 모바일 영수증과 같다 — 배포가 로컬 디스크를 초기화해도
     * 장부의 근거는 남아야 한다. 너무 큰 파일(10MB 초과)은 붙이지 않는다: 장부 근거로
     * 쓰기엔 과하고, 원본은 문서함에 그대로 있다.
     *
     * @return array<string, mixed>
     */
    private function receiptCopy(IntelligentDocument $document): array
    {
        try {
            if (blank($document->file_path) || (int) $document->file_size > 10 * 1024 * 1024) {
                return [];
            }

            $disk = Storage::disk($document->disk ?: (string) config('document-intelligence.disk', 'local'));
            if (! $disk->exists($document->file_path)) {
                return [];
            }

            return [
                'receipt_file' => ReceiptFilePayload::encode((string) $disk->get($document->file_path)),
                'receipt_mime_type' => $document->mime_type ?: 'application/octet-stream',
                'receipt_original_name' => $document->original_file_name ?: 'receipt',
            ];
        } catch (\Throwable) {
            return []; // 사본 실패가 경비 등록을 막으면 안 된다.
        }
    }
}
