<?php

namespace App\Services\Finance;

use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptLine;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\Vendor;
use App\Support\FinanceChartOfAccounts;

/**
 * 확정된 자재 입고를 회계 대기(경비 원장 pending)로 넘긴다.
 *
 * ── 왜 ───────────────────────────────────────────────────────────────
 * 자재 입고는 공정표 없이 적을 수 있게 만든 화면이라(MaterialReceiptService) 조달의
 * 원장 연결(ProcurementExpenseConnector)을 타지 않는다. 그래서 확정한 입고의 금액이
 * 원가에 0 으로 남았다. 사장이 보류했다가 «이제 해줘» 라고 한 연결이 이것이다.
 *
 * ── 지키는 것 (다른 커넥터와 같다) ───────────────────────────────────
 *  - 멱등: source_ref("material-receipt:{id}") 가 같으면 다시 만들지 않는다.
 *  - 자동 생성 건은 'pending'(회계 대기) — 사람이 재무 화면에서 승인해야 장부가 된다.
 *  - 이미 승인·지급된 건은 손대지 않는다. 확정을 풀어도 그 건은 남는다(장부 확정 후 소급 금지).
 *  - 금액은 <b>단가가 적힌 줄의 합</b>이다. 단가가 하나도 없으면 «0 달러» 가 아니라 «모름» 이라
 *    원장에 넣지 않는다 — 0 이 원가가 되면 그 자재는 공짜였던 것이 된다.
 *  - 같은 PO 가 조달 화면에서 이미 원장으로 갔으면 두 번 넣지 않는다. 한 트럭이 두 번 계산되면
 *    원가가 부풀고, 어느 쪽을 지워야 하는지 나중에 아무도 모른다.
 */
class MaterialReceiptExpenseConnector
{
    public const ACCOUNT = '5201 Job Materials';

    /**
     * @return array{posted: bool, amount: ?float, note: ?string}
     */
    public function sync(MaterialReceipt $receipt): array
    {
        $sourceRef = "material-receipt:{$receipt->id}";
        $existing = MobileExpense::query()->where('source_ref', $sourceRef)->first();

        // 사람이 이미 승인/지급한 건은 손대지 않는다.
        if ($existing && $existing->status !== 'pending') {
            return ['posted' => true, 'amount' => (float) $existing->amount, 'note' => '이미 승인된 회계 내역이 있어 그대로 둡니다.'];
        }

        $receipt->loadMissing('lines');
        $priced = $receipt->lines->filter(fn (MaterialReceiptLine $l): bool => $l->amount() !== null);
        $amount = round((float) $priced->sum(fn (MaterialReceiptLine $l): float => (float) $l->amount()), 2);

        // 확정이 풀렸거나 금액을 모르면 회계 대기에 남겨 둘 것이 없다.
        if (! $receipt->isConfirmed() || $priced->isEmpty() || $amount <= 0) {
            $existing?->delete();

            return ['posted' => false, 'amount' => null,
                'note' => $receipt->isConfirmed() ? '단가가 없어 회계 대기로 넘기지 않았습니다. 단가를 적고 다시 확정하면 넘어갑니다.' : null];
        }

        // 같은 PO 가 조달 화면에서 이미 원장으로 갔으면 두 번 넣지 않는다.
        if ($existing === null && ($twin = $this->procurementTwin($receipt)) !== null) {
            return ['posted' => false, 'amount' => $amount,
                'note' => "PO {$receipt->po_no} 는 조달 관리에서 이미 회계 대기로 넘어가 있어 두 번 넣지 않았습니다(#{$twin->id})."];
        }

        $names = $receipt->lines->pluck('name')->filter()->values();
        $label = $names->isEmpty() ? '자재' : ($names->first().($names->count() > 1 ? ' 외 '.($names->count() - 1).'건' : ''));
        $account = FinanceChartOfAccounts::normalize(self::ACCOUNT, (string) $receipt->vendor);

        $attributes = [
            'company_id' => $receipt->company_id
                ?? ($receipt->site_id ? Site::query()->whereKey($receipt->site_id)->value('company_id') : null),
            'vendor_id' => $receipt->vendor_id ?: Vendor::matchByName((string) $receipt->vendor),
            'site_id' => $receipt->site_id,
            'payment_type' => 'corporate',
            'category' => $account,
            'accounting_account' => $account,
            'description' => "[자동] 자재 입고 · {$label}"
                .($receipt->vendor ? " · {$receipt->vendor}" : '')
                .($receipt->po_no ? " · PO {$receipt->po_no}" : '')
                .($receipt->delivery_no ? " · 납품서 {$receipt->delivery_no}" : ''),
            'amount' => $amount,
            'expense_date' => $receipt->received_on?->toDateString() ?? now()->toDateString(),
            'ocr_data' => ['source' => 'material-receipt', 'receipt_id' => $receipt->id],
        ];

        if ($existing === null) {
            MobileExpense::query()->create($attributes + ['source_ref' => $sourceRef, 'status' => 'pending']);
        } else {
            $existing->fill($attributes);
            if ($existing->isDirty()) {
                $existing->save();
            }
        }

        return ['posted' => true, 'amount' => $amount, 'note' => null];
    }

    /** 같은 현장·같은 PO 의 조달 건이 이미 원장에 있으면 그 원장 건. */
    private function procurementTwin(MaterialReceipt $receipt): ?MobileExpense
    {
        $po = trim((string) $receipt->po_no);
        if ($po === '') {
            return null;
        }

        $ids = ProcurementItem::query()
            ->where('site_id', $receipt->site_id)
            ->whereRaw('upper(po_no) = ?', [strtoupper($po)])
            ->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }

        return MobileExpense::query()
            ->whereIn('source_ref', $ids->map(fn ($id): string => "procurement:{$id}")->all())
            ->first();
    }
}
