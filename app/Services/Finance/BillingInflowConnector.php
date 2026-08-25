<?php

namespace App\Services\Finance;

use App\Models\BillingReceipt;
use App\Models\IntelligentDocument;
use App\Models\PayApplication;
use App\Models\ProjectContract;
use App\Services\Alerts\UnifiedAlertService;

/**
 * 들어오는 돈(flow=in) 문서 → 기성 수금 원장.
 *
 * 지금까지 flow=in 문서는 AI 가 분석까지 하고 그대로 버려졌다 — 입금 통지를 사람이
 * 재무 화면에 다시 입력해야 했고, PayApplication/BillingReceipt.source_ref 는 죽은
 * 칼럼이었다(점검 G). 이 커넥터가 그 길을 잇는다:
 *
 *  - 입금 통지·송금 확인류 → BillingReceipt(매칭 대기)로 기록. 회차 배정은 기존
 *    "매칭 대기 입금" 화면에서 사람이 한다 — 입금이 먼저 오고 매칭은 나중인 현실 그대로.
 *  - 우리가 발행한 기성청구·인보이스 사본 → 새로 만들지 않는다(원장은 이미 기성
 *    화면에 있다). 금액이 정확히 일치하는 회차가 하나면 문서를 그 회차에 연결만 한다.
 *
 * 원칙(다른 커넥터와 동일): 멱등 source_ref("document:{id}"), 자동은 기록까지 —
 * 회차 배정·차감 판정은 사람이 한다. 계약을 유일하게 못 찾으면 만들지 않고 알림을
 * 남긴다(틀린 계약에 앉은 입금은 없는 입금보다 나쁘다).
 */
class BillingInflowConnector
{
    /** 우리가 발행한 청구 문서 — 수금이 아니라 청구의 사본이다. */
    private const CLAIM_TYPES = ['pay_application', 'invoice'];

    public function sync(IntelligentDocument $document): void
    {
        $money = (array) (($document->ai_payload ?? [])['money'] ?? []);
        $flow = strtolower(trim((string) ($money['flow'] ?? '')));
        $amount = is_numeric($money['amount'] ?? null) ? (float) $money['amount'] : 0.0;

        if ($flow !== 'in' || $amount <= 0) {
            return;
        }

        if (in_array((string) $document->document_type, self::CLAIM_TYPES, true)) {
            $this->linkClaimDocument($document, $amount);

            return;
        }

        $this->recordReceipt($document, $money, $amount);
    }

    /**
     * 우리가 발행한 청구서 사본 — 금액이 정확히 일치하는 회차가 하나면 연결만 한다.
     */
    private function linkClaimDocument(IntelligentDocument $document, float $amount): void
    {
        $candidates = PayApplication::query()
            ->whereNull('intelligent_document_id')
            ->where(function ($q) use ($amount): void {
                $q->where('amount_due', $amount)->orWhere('cumulative_amount', $amount);
            })
            ->when($document->project_id, fn ($q) => $q->where('project_id', $document->project_id))
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            return; // 애매하면 잇지 않는다 — 문서함에는 그대로 남아 있다.
        }

        $candidates->first()->update(['intelligent_document_id' => $document->id]);
    }

    /**
     * 입금 통지 → 수금(매칭 대기) 기록.
     *
     * @param  array<string, mixed>  $money
     */
    private function recordReceipt(IntelligentDocument $document, array $money, float $amount): void
    {
        $sourceRef = "document:{$document->id}";
        if (BillingReceipt::query()->where('source_ref', $sourceRef)->exists()) {
            return; // 재분석해도 한 건.
        }

        $contract = $this->contractFor($document);
        if ($contract === null) {
            // 계약을 못 찾으면 만들지 않는다 — 대신 사람에게 알린다. "분석만 하고
            // 버리는" 예전 동작과 달리, 입금이 왔다는 사실 자체는 이제 안 사라진다.
            try {
                app(UnifiedAlertService::class)->emit("billing-inflow-unmatched:{$document->id}", [
                    'company_id' => $document->company_id,
                    'site_id' => $document->site_id,
                    'project_id' => $document->project_id,
                    'source_module' => 'FIN',
                    'source_type' => IntelligentDocument::class,
                    'source_id' => (string) $document->id,
                    'event_type' => 'billing_inflow_unmatched',
                    'severity' => 'warning',
                    'title' => '입금 문서 확인 필요: '.($document->title ?: $document->original_file_name),
                    'content' => sprintf('$%s 입금 문서를 읽었지만 귀속할 수주 계약을 특정하지 못했습니다. 기성 화면에서 수금을 직접 기록하세요.', number_format($amount, 2)),
                    'action_url' => '/admin/billing',
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            return;
        }

        $paidOn = (string) ($money['paid_on'] ?? '');
        $memo = trim(implode(' · ', array_filter([
            '[문서함] '.($document->title ?: $document->original_file_name),
            trim((string) ($money['payee'] ?? '')),
            trim((string) ($money['purpose'] ?? '')),
        ])));

        BillingReceipt::create([
            'project_contract_id' => $contract->id,
            'pay_application_id' => null, // 매칭 대기 — 회차 배정은 사람이 한다.
            'company_id' => $contract->company_id ?? $document->company_id,
            'site_id' => $contract->site_id ?? $document->site_id,
            'received_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) === 1
                ? $paidOn
                : ($document->document_date?->toDateString() ?? now()->toDateString()),
            'amount' => $amount,
            'method' => 'other',
            'intelligent_document_id' => $document->id,
            'source_ref' => $sourceRef,
            'memo' => mb_substr($memo, 0, 500),
        ]);
    }

    /**
     * 문서 → 수주 계약. 유일할 때만 — 프로젝트 우선, 다음 현장.
     */
    private function contractFor(IntelligentDocument $document): ?ProjectContract
    {
        $base = ProjectContract::query()
            ->where('direction', 'receivable')
            ->whereIn('status', ['active', 'suspended', 'completed', 'expired']);

        foreach ([
            ['project_id', $document->project_id],
            ['site_id', $document->site_id],
        ] as [$column, $value]) {
            if (! $value) {
                continue;
            }
            $matches = (clone $base)->where($column, $value)->limit(2)->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }
}
