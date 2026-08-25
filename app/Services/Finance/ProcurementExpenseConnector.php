<?php

namespace App\Services\Finance;

use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\Site;
use App\Support\FinanceChartOfAccounts;

/**
 * 입고된 자재의 발주 금액을 원가(경비 원장)로 넘긴다.
 *
 * 발주 금액(procurement_items.amount)을 읽는 곳은 조달 화면·대시보드·마감 보고뿐이었고
 * 원가로 넘어가는 경로가 없었다 — <b>자재를 $50만어치 발주해도 프로젝트 원가에는
 * 0원이었다.</b> 장비 임대료(RentalExpenseConnector)·급여(PayrollExpenseConnector)는
 * 이미 자동 원장으로 이어 놓고 자재만 빠져 있던 것이다.
 *
 * 넘기는 시점은 <b>입고완료</b>다. 발주 시점에 잡으면 취소·감액될 수 있는 금액이
 * 원가에 앉고, 입고는 물건이 실제로 현장에 온 것이므로 비용의 근거가 선다.
 *
 * 원칙(다른 커넥터와 동일):
 *  - 멱등: source_ref("procurement:{id}")가 같으면 다시 만들지 않는다.
 *  - 자동 생성 건은 'pending' — 사람이 승인해야 장부에 확정된다.
 *  - 이미 승인/지급된 건은 절대 덮어쓰지 않는다.
 */
class ProcurementExpenseConnector
{
    /** 입고완료가 아니면 아무것도 하지 않는다. 금액이 없어도 넘길 것이 없다. */
    public function sync(ProcurementItem $item): void
    {
        if ($item->status !== '입고완료' || $item->amount === null || (float) $item->amount <= 0) {
            return;
        }

        $sourceRef = "procurement:{$item->id}";
        $existing = MobileExpense::query()->where('source_ref', $sourceRef)->first();

        // 사람이 이미 승인/지급한 건은 손대지 않는다 — 장부 확정 후 소급 변경 금지.
        if ($existing && $existing->status !== 'pending') {
            return;
        }

        $label = $item->item?->name
            ?: ($item->wbsItem?->name ?: ($item->po_no ? "PO {$item->po_no}" : '자재 조달'));
        $currencyNote = ($item->currency && strtoupper((string) $item->currency) !== 'USD')
            ? " ({$item->currency})" : '';

        // 계정과목은 정본(FinanceChartOfAccounts)을 지나서 넣는다 — 목록에 없는 문구를
        // 직접 적으면 같은 자재비가 화면·집계에서 다른 칸으로 갈라진다.
        $account = FinanceChartOfAccounts::normalize('5201 Job Materials', (string) $item->vendor);

        $project = Project::query()->where('project_code', $item->project_code)->first();

        $attributes = [
            // 회사 스코프 계정(협력사 관리자)은 company_id 로 거른다 — 없으면 그 화면에서 자재비가 안 보인다.
            'company_id' => $project?->company_id
                ?? ($item->site_id ? Site::query()->whereKey($item->site_id)->value('company_id') : null),
            'site_id' => $item->site_id,
            'project_id' => $project?->id,
            'wbs_code' => $item->wbs_code,
            'payment_type' => 'corporate',
            'category' => $account,
            'accounting_account' => $account,
            'description' => "[자동] 자재 입고 · {$label}"
                .($item->vendor ? " · {$item->vendor}" : '')
                .($item->po_no ? " · PO {$item->po_no}" : '')
                .$currencyNote,
            'amount' => (float) $item->amount,
            'expense_date' => now()->toDateString(),
        ];

        if (! $existing) {
            MobileExpense::query()->create($attributes + ['source_ref' => $sourceRef, 'status' => 'pending']);

            return;
        }

        if ((float) $existing->amount !== (float) $item->amount
            || (string) $existing->description !== (string) $attributes['description']) {
            // 입고일은 처음 잡힌 날을 지킨다 — 금액 수정 때마다 비용 날짜가 밀리면 안 된다.
            unset($attributes['expense_date']);
            $existing->update($attributes);
        }
    }
}
