<?php

namespace App\Services\Procurement;

use App\Models\IntegratedDocument;
use App\Models\Item;
use App\Models\ProcurementItem;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Finance\ProcurementExpenseConnector;
use App\Services\Vendors\VendorResolver;
use App\Support\OperationalAccess;
use App\Support\PurchaseAccess;
use App\Support\WorkerDeviceSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

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
        $actor = auth()->user();
        abort_unless($actor && $actor->account_status === 'active', 403);
        abort_if(request()->hasSession() && WorkerDeviceSession::isDeviceOnly(request()), 403);
        $buyer = PurchaseAccess::canBuy($actor);
        $today ??= now()->toDateString();

        $query = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK);
        $this->scopeSites($query, $actor);
        if ($siteId !== 'ALL') {
            $query->where('site_id', Site::query()->where('code', $siteId)->value('id'));
        }

        $subs = $query->get()->filter(fn (WbsItem $i) => $i->looksLikeProcurement());

        $trackingQuery = ProcurementItem::query()
            ->where('project_code', $projectCode)
            ->whereIn('wbs_code', $subs->pluck('wbs_code')->all())
            ->with(['item:id,name,unit,standard_cost', 'contract:id,contract_number,title,current_amount,currency']);
        $this->scopeSites($trackingQuery, $actor);
        $tracking = $trackingQuery->get()->keyBy('wbs_code');

        $rows = $subs->map(function (WbsItem $i) use ($tracking, $today, $buyer): array {
            $t = $tracking->get($i->wbs_code);
            $status = $t?->status ?? '발주대기';
            $eta = $t?->eta?->toDateString();
            $needBy = $i->planned_end?->toDateString();          // 납기 = WBS 종료일(그때까지 있어야 함).
            // 여유(slack) = need_by - eta (양수=ETA가 납기보다 이름=여유 있음, 음수=지연).
            $slack = ($eta !== null && $needBy !== null)
                ? (int) Carbon::parse($eta)->diffInDays(Carbon::parse($needBy), false)
                : null;

            $delay = $this->delayState($status, $eta, $slack, $today);

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
                'siteId' => $i->site_id,
                'vendor' => $buyer ? ($t?->vendor ?? ($i->company ?? '')) : null,
                'vendorId' => $buyer ? $t?->vendor_id : null,
                'contractId' => $buyer ? $t?->contract_id : null,
                'contractLabel' => $buyer && $t?->contract
                    ? trim(($t->contract->contract_number ? $t->contract->contract_number.' · ' : '').$t->contract->title)
                    : null,
                'itemId' => $t?->item_id,
                'itemName' => $t?->item?->name,
                'poNo' => $buyer ? $t?->po_no : null,
                'amount' => $buyer && $t?->amount !== null ? (float) $t->amount : null,
                'currency' => $buyer ? $t?->currency : null,
                'orderedOn' => $t?->ordered_on?->toDateString(),
                'eta' => $eta,
                'needBy' => $needBy,
                'slack' => $slack,
                'delay' => $delay,                                 // done/late/risk/ok/unknown
                'alert' => $this->alertLevel($delay, (bool) $i->is_critical),
                'note' => $buyer ? $t?->note : null,
                'documentName' => $buyer ? $t?->document_name : null,
                'documentUrl' => ($buyer && $t && filled($t->document_path)) ? route('procurement.file', ['item' => $t->id]) : null,
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
            'canBuy' => $buyer,
            'contracts' => $buyer ? $this->contractOptions($projectCode, $actor) : [],
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
        // Every writer (legacy API, manual meeting review and jobs) uses the same buyer gate.
        // An absent web actor must never turn a console call into unrestricted purchasing.
        $actor = auth()->user() ?? ($userId ? User::find($userId) : null);
        abort_if(auth()->check() && $userId !== null && auth()->id() !== $userId, 403);
        $actor = PurchaseAccess::assertBuyer($actor);
        $userId = $actor->id;
        $wbsCode = trim($wbsCode);
        if ($projectCode === '' || $wbsCode === '') {
            return ['success' => false, 'error' => '프로젝트/작업 코드가 없습니다.'];
        }

        $wbs = WbsItem::query()->where('project_code', $projectCode)->where('wbs_code', $wbsCode)->first();
        if (! $wbs) {
            return ['success' => false, 'error' => '해당 조달 공정을 찾을 수 없습니다.'];
        }
        $this->assertSite($actor, $wbs->site_id);
        if ($siteId !== 'ALL') {
            abort_unless((int) Site::where('code', $siteId)->value('id') === (int) $wbs->site_id, 403);
        }

        $item = ProcurementItem::query()->firstOrNew(['project_code' => $projectCode, 'wbs_code' => $wbsCode]);
        if ($item->exists) {
            $this->assertSite($actor, $item->site_id);
            abort_unless($item->site_id === $wbs->site_id, 403);
        }
        $this->assertDocumentPatch($patch, $item, $actor, $wbs->site_id);
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
            $master = $itemId ? Item::find($itemId) : null;
            $companyId = $wbs->site_id ? Site::find($wbs->site_id)?->company_id : null;
            abort_if($master?->company_id !== null && $master->company_id !== $companyId, 403);
            $item->item_id = $master?->id;
        }
        if (array_key_exists('contract_id', $patch)) {
            // 발주는 발주(payable)·상호 계약에만 걸 수 있다. 수주 계약에 발주를 걸면
            // 계약 대비 발주 누계가 원청 계약 금액과 섞여 둘 다 못 믿게 된다.
            $contractId = is_numeric($patch['contract_id']) ? (int) $patch['contract_id'] : null;
            $contract = $contractId ? ProjectContract::query()
                ->whereKey($contractId)
                ->whereIn('direction', ['payable', 'mutual'])
                ->first() : null;
            if ($contract) {
                $this->assertSite($actor, $contract->site_id);
                abort_if($contract->site_id !== null && $contract->site_id !== $wbs->site_id, 403);
                $companyId = $wbs->site_id ? Site::find($wbs->site_id)?->company_id : null;
                abort_if($contract->company_id !== null && $contract->company_id !== $companyId, 403);
            }
            $item->contract_id = $contract?->id;
        }
        if (array_key_exists('vendor', $patch)) {
            // Resolve/create a vendor only after all linked-record permissions have passed.
            // The master row is authoritative; the text column is its display name copy.
            $vendor = app(VendorResolver::class)->resolve((string) $patch['vendor'], $wbs->site_id ? Site::find($wbs->site_id)?->company_id : null);
            $item->vendor_id = $vendor?->id;
            $item->vendor = $vendor?->name;
            unset($patch['vendor']);
        }
        foreach (['po_no', 'currency', 'note', 'document_disk', 'document_path', 'document_name'] as $k) {
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

        // 입고완료면 발주 금액을 원가(경비 원장)로 넘긴다. 실패해도 조달 저장은
        // 살아야 하므로 여기서 삼킨다 — 부가 기능이 주 기능을 막으면 안 된다.
        $financeWarning = null;
        try {
            app(ProcurementExpenseConnector::class)->sync($item);
        } catch (\Throwable $e) {
            report($e);
            $financeWarning = '조달 정보는 저장됐지만 회계 대기 내역 연결에 실패했습니다. 다시 저장하거나 관리자에게 확인하세요.';
        }

        return [
            'success' => true,
            'wbs_code' => $wbsCode,
            'status' => $item->status,
            'vendorId' => $item->vendor_id,
            'vendor' => $item->vendor,
            'contractId' => $item->contract_id,
            'financeWarning' => $financeWarning,
        ];
    }

    /**
     * 이 프로젝트에서 발주를 걸 수 있는 계약 목록 + 계약 대비 발주 누계.
     *
     * 누계가 없으면 계약에 발주를 걸어도 "이 계약으로 얼마나 샀나" 를 아무도 모른다 —
     * 연결의 목적이 바로 그 숫자다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contractOptions(string $projectCode, User $actor): array
    {
        $query = ProjectContract::query()
            ->whereIn('direction', ['payable', 'mutual'])
            ->whereNotIn('status', ['terminated', 'expired'])
            ->with('counterpartyVendor:id,name')
            ->orderBy('title');
        $this->scopeSites($query, $actor);
        $contracts = $query->get();
        if ($contracts->isEmpty()) {
            return [];
        }

        $orderedQuery = ProcurementItem::query()
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->where('project_code', $projectCode)
            ->selectRaw('contract_id, SUM(COALESCE(amount, 0)) as total');
        $this->scopeSites($orderedQuery, $actor);
        $ordered = $orderedQuery->groupBy('contract_id')->pluck('total', 'contract_id');

        return $contracts->map(function (ProjectContract $c) use ($ordered): array {
            $poTotal = (float) ($ordered[$c->id] ?? 0);
            $limit = $c->current_amount !== null ? (float) $c->current_amount : null;

            return [
                'id' => $c->id,
                'label' => trim(($c->contract_number ? $c->contract_number.' · ' : '').$c->title),
                'vendor' => $c->counterpartyVendor?->name ?? $c->counterparty?->name,
                'amount' => $limit,
                'currency' => $c->currency,
                'poTotal' => $poTotal,
                'remaining' => $limit !== null ? round($limit - $poTotal, 2) : null,
            ];
        })->values()->all();
    }

    private function scopeSites($query, User $actor): void
    {
        if ($actor->access_role !== 'super_admin') {
            $siteIds = PurchaseAccess::eligible($actor)
                ? PurchaseAccess::sites($actor)->pluck('id')->all()
                : OperationalAccess::siteIds($actor);
            $query->whereIn('site_id', $siteIds);
        }
    }

    private function assertSite(User $actor, ?int $siteId): void
    {
        // Unassigned historical WBS rows remain available only to the super administrator.
        if ($siteId === null && $actor->access_role === 'super_admin') {
            return;
        }
        PurchaseAccess::assertSite($actor, (int) $siteId);
    }

    private function assertDocumentPatch(array $patch, ProcurementItem $item, User $actor, ?int $siteId): void
    {
        if (! array_intersect(['document_disk', 'document_path', 'document_name'], array_keys($patch))) {
            return;
        }
        $path = $patch['document_path'] ?? $item->document_path;
        $disk = $patch['document_disk'] ?? $item->document_disk;
        if ($item->exists && $path === $item->document_path && $disk === $item->document_disk) {
            return;
        }
        // A raw disk/path supplied by a client is not permission to attach another document.
        $expectedDisk = IntegratedDocument::storageDisk();
        $expectedDisk = $expectedDisk === 'public' ? 'local' : $expectedDisk;
        abort_unless(is_string($path) && $disk === $expectedDisk
            && str_starts_with($path, "procurement-docs/{$actor->id}/{$siteId}/")
            && ! str_contains($path, '..') && ! str_contains($path, '\\')
            && Storage::disk($disk)->exists($path), 403, '본인이 업로드한 해당 현장의 구매 근거만 연결할 수 있습니다.');
    }

    private function delayState(string $status, ?string $eta, ?int $slack, string $today): string
    {
        if ($status === '입고완료') {
            return 'done';
        }
        if ($eta !== null && $eta < $today) {
            return 'late';
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
