<?php

namespace App\Services\Procurement;

use App\Models\Item;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Support\Carbon;

/**
 * 조달 관리 — 발주·조달성 WBS 공정을 자동 추출해 납기(리드타임)를 스마트하게 추적한다.
 *
 * 핵심: 도착예정(ETA)을 그 자재가 필요한 시점(need-by = WBS 종료일)과 비교해 여유(slack)를
 * 계산하고, 임계경로 자재가 늦으면 준공 지연으로 경보한다. 상태는 발주→생산→선적→통관→입고
 * 파이프라인으로 추적한다.
 */
class ProcurementService
{
    /**
     * 프로젝트의 조달 항목 목록 + KPI. (WBS 조달 공정 + 저장된 납기 추적 상태를 합친다.)
     *
     * @return array<string, mixed>
     */
    public function list(string $projectCode, string $siteId = 'ALL', ?string $today = null): array
    {
        $today ??= now()->toDateString();

        $query = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK);
        if ($siteId !== 'ALL') {
            $query->where('site_id', Site::query()->where('code', $siteId)->value('id'));
        }

        $subs = $query->get()->filter(fn (WbsItem $i) => $i->looksLikeProcurement());

        $tracking = ProcurementItem::query()
            ->where('project_code', $projectCode)
            ->whereIn('wbs_code', $subs->pluck('wbs_code')->all())
            ->with('item:id,name,unit,standard_cost')
            ->get()->keyBy('wbs_code');

        $rows = $subs->map(function (WbsItem $i) use ($tracking): array {
            $t = $tracking->get($i->wbs_code);
            $status = $t?->status ?? '발주대기';
            $eta = $t?->eta?->toDateString();
            $needBy = $i->planned_end?->toDateString();          // 납기 = WBS 종료일(그때까지 있어야 함).
            // 여유(slack) = need_by - eta (양수=ETA가 납기보다 이름=여유 있음, 음수=지연).
            $slack = ($eta !== null && $needBy !== null)
                ? (int) Carbon::parse($eta)->diffInDays(Carbon::parse($needBy), false)
                : null;

            $delay = $this->delayState($status, $eta, $slack);

            return [
                'wbs_id' => $i->wbs_code,
                'activityId' => $i->activity_id,
                'name' => $i->name,
                'trade' => $i->trade ?? '',
                'stagePath' => $this->stagePath($i),
                'isCritical' => (bool) $i->is_critical,
                'status' => $status,
                'statusIndex' => array_search($status, ProcurementItem::STATUSES, true) ?: 0,
                'progress' => ProcurementItem::progressFor($status),
                'nextStatus' => ProcurementItem::nextStatus($status),
                'vendor' => $t?->vendor ?? ($i->company ?? ''),
                'itemId' => $t?->item_id,
                'itemName' => $t?->item?->name,
                'poNo' => $t?->po_no,
                'amount' => $t?->amount !== null ? (float) $t->amount : null,
                'currency' => $t?->currency,
                'orderedOn' => $t?->ordered_on?->toDateString(),
                'eta' => $eta,
                'needBy' => $needBy,
                'slack' => $slack,
                'delay' => $delay,                                 // done/late/risk/ok/unknown
                'alert' => $this->alertLevel($delay, (bool) $i->is_critical),
                'note' => $t?->note,
                'documentName' => $t?->document_name,
                'documentUrl' => ($t && filled($t->document_path)) ? route('procurement.file', ['item' => $t->id]) : null,
                'plannedStart' => $i->planned_start?->toDateString(),
            ];
        })->sort(function (array $a, array $b): int {
            // 경보(임계 지연) 먼저 → 여유 적은 순 → 납기 빠른 순.
            $rank = ['critical' => 0, 'warning' => 1, 'watch' => 2, 'none' => 3];

            return [$rank[$a['alert']] ?? 3, $a['slack'] ?? 99999, $a['needBy'] ?? '9999']
                <=> [$rank[$b['alert']] ?? 3, $b['slack'] ?? 99999, $b['needBy'] ?? '9999'];
        })->values();

        return [
            'success' => true,
            'projectId' => $projectCode,
            'date' => $today,
            'statuses' => ProcurementItem::STATUSES,
            'items' => $rows->all(),
            'total' => $rows->count(),
            'ordered' => $rows->whereNotIn('status', ['발주대기'])->count(),
            'inTransit' => $rows->whereIn('status', ['선적중', '통관중'])->count(),
            'delivered' => $rows->where('status', '입고완료')->count(),
            'atRisk' => $rows->whereIn('delay', ['late', 'risk'])->count(),
            'lateCount' => $rows->where('delay', 'late')->count(),
        ];
    }

    /**
     * 조달 항목의 납기 추적 상태를 저장(upsert). status/eta/vendor/po/금액/발주일/메모.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function update(string $projectCode, string $wbsCode, array $patch, string $siteId = 'ALL', ?int $userId = null): array
    {
        $wbsCode = trim($wbsCode);
        if ($projectCode === '' || $wbsCode === '') {
            return ['success' => false, 'error' => '프로젝트/작업 코드가 없습니다.'];
        }

        $wbs = WbsItem::query()->where('project_code', $projectCode)->where('wbs_code', $wbsCode)->first();
        if (! $wbs) {
            return ['success' => false, 'error' => '해당 조달 공정을 찾을 수 없습니다.'];
        }

        $item = ProcurementItem::query()->firstOrNew(['project_code' => $projectCode, 'wbs_code' => $wbsCode]);
        if (! $item->exists) {
            $item->wbs_item_id = $wbs->id;
            $item->site_id = $wbs->site_id;
            $item->created_by_id = $userId;
        }

        if (array_key_exists('status', $patch) && in_array($patch['status'], ProcurementItem::STATUSES, true)) {
            $item->status = $patch['status'];
            // 발주완료로 넘어가는데 발주일이 비어 있으면 오늘로 기록.
            if ($item->status !== '발주대기' && blank($item->ordered_on)) {
                $item->ordered_on = now()->toDateString();
            }
        }
        if (array_key_exists('item_id', $patch)) {
            // 품목 마스터 연결 — 있는 품목만. 없는 id 가 오면 연결을 지우는 것으로 본다.
            $itemId = is_numeric($patch['item_id']) ? (int) $patch['item_id'] : null;
            $item->item_id = ($itemId && Item::query()->whereKey($itemId)->exists()) ? $itemId : null;
        }
        foreach (['vendor', 'po_no', 'currency', 'note', 'document_disk', 'document_path', 'document_name'] as $k) {
            if (array_key_exists($k, $patch)) {
                $item->{$k} = ($patch[$k] === '' ? null : $patch[$k]);
            }
        }
        foreach (['ordered_on', 'eta'] as $k) {
            if (array_key_exists($k, $patch)) {
                $item->{$k} = $this->date($patch[$k]);
            }
        }
        if (array_key_exists('amount', $patch)) {
            $item->amount = is_numeric($patch['amount']) ? (float) $patch['amount'] : null;
        }

        $item->save();

        return ['success' => true, 'wbs_code' => $wbsCode, 'status' => $item->status];
    }

    private function delayState(string $status, ?string $eta, ?int $slack): string
    {
        if ($status === '입고완료') {
            return 'done';
        }
        if ($eta === null || $slack === null) {
            return 'unknown';
        }
        if ($slack < 0) {
            return 'late';
        }
        if ($slack <= 7) {
            return 'risk';
        }

        return 'ok';
    }

    private function alertLevel(string $delay, bool $critical): string
    {
        if ($delay === 'late') {
            return $critical ? 'critical' : 'warning';
        }
        if ($delay === 'risk') {
            return $critical ? 'warning' : 'watch';
        }

        return 'none';
    }

    private function stagePath(WbsItem $sub): string
    {
        $task = $sub->parent()->first();
        $stage = $task?->parent()->first();

        return trim(implode(' › ', array_filter([$stage?->name, $task?->name])));
    }

    private function date(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
