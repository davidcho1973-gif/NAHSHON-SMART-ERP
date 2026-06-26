<?php

namespace App\Services\Inventory;

use App\Models\Equipment;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * 자재/장비(Inventory) 현황판 — 같은 `equipments` 레지스트리에서 소유+임대 전체를 집계한다.
 * 렌탈 관리에서 등록된 임대 장비도 여기 현황에 자동으로 포함된다("임대" 구분).
 *
 * renderInventory(SPA)가 기대하는 계약: { totals, matrix, recent, upcomingInspections }.
 */
class InventoryService
{
    private const REPAIR_STATUSES = ['정비중', '수리중'];
    private const STORAGE_STATUSES = ['대기중', '창고보관'];
    private const INSPECTION_WINDOW_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function dashboard(string $siteId = 'ALL'): array
    {
        $query = Equipment::query()
            ->with(['project', 'purchasedForSite', 'employee', 'activeRental.employee', 'activeRental.site'])
            ->orderByDesc('id');

        // Respect the existing access-control scope when available.
        if (method_exists(Equipment::class, 'scopeVisibleTo')) {
            $query->visibleTo(auth()->user());
        }

        if ($siteId !== 'ALL') {
            $query->where('site_id', Site::query()->where('code', $siteId)->value('id'));
        }

        $items = $query->get();
        $siteCodes = Site::query()->pluck('code', 'id');
        $today = Carbon::today();

        // Bulk materials carry a quantity; individual assets count as 1.
        $qty = fn (Equipment $e): int => max(1, (int) ($e->quantity ?? 1));

        // 현 위치 = 활성 배정(반납 안 됨) 우선 → 없으면 등록 현장 → 둘 다 없으면 창고.
        $currentSite = function (Equipment $e) use ($siteCodes): string {
            $sid = $e->activeRental?->site_id ?? $e->site_id;

            return $sid ? (string) ($siteCodes[$sid] ?? '미지정') : '창고';
        };

        // 현 사용자 = 활성 배정 담당자 → 등록 담당자 → 커스텀 → 미배정.
        $holder = fn (Equipment $e): string => $e->activeRental?->employee?->name
            ?: ($e->employee?->name ?: (is_array($e->payload) ? ($e->payload['custom_operator'] ?? '') : '') ?: '미배정');

        // ── 매트릭스 (카테고리 × 위치) — 위치는 '현 위치' 기준 ──
        $categories = [];
        $sites = [];
        $cells = [];
        $categoryMeta = [];
        $groupCounts = [];
        foreach ($items as $e) {
            $cat = $e->equipment_type ?: '기타';
            $loc = $currentSite($e);
            $categories[$cat] = true;
            $sites[$loc] = true;
            $cells[$cat][$loc] = ($cells[$cat][$loc] ?? 0) + $qty($e);

            // 기능 분류 메타 — UI가 행을 대분류로 묶고 색/라벨을 칠하도록.
            $gk = $e->resolvedGroup();
            $tk = $e->resolvedTrade();
            $categoryMeta[$cat] = [
                'group' => $gk,
                'groupLabel' => Equipment::CATEGORY_GROUPS[$gk] ?? $gk,
                'trade' => $tk,
                'tradeLabel' => Equipment::TRADES[$tk] ?? $tk,
            ];
            $groupCounts[$gk] = ($groupCounts[$gk] ?? 0) + $qty($e);
        }

        $groups = [];
        foreach (Equipment::CATEGORY_GROUPS as $key => $label) {
            if (! empty($groupCounts[$key])) {
                $groups[] = ['key' => $key, 'label' => $label, 'count' => $groupCounts[$key]];
            }
        }

        // ── 점검 임박 (오늘+30일 이내, 지연분 포함) ──
        $inspections = $items
            ->filter(fn (Equipment $e) => $e->inspection_due_on !== null
                && $e->inspection_due_on->lte($today->copy()->addDays(self::INSPECTION_WINDOW_DAYS)))
            ->sortBy(fn (Equipment $e) => $e->inspection_due_on)
            ->map(fn (Equipment $e): array => [
                'assetId' => $e->equipment_code,
                'category' => $e->equipment_type ?: '기타',
                'name' => $e->model,
                'nextInspect' => $e->inspection_due_on->format('Y-m-d'),
                'dDay' => (int) $today->diffInDays($e->inspection_due_on, false),
            ])->values()->all();

        return [
            'success' => true,
            'totals' => [
                'count' => $items->sum($qty),
                'value' => (float) $items->sum(fn (Equipment $e) => (float) $e->asset_value),
                'inUse' => $items->where('status', '사용중')->sum($qty),
                'inStorage' => $items->whereIn('status', self::STORAGE_STATUSES)->sum($qty),
                'repair' => $items->whereIn('status', self::REPAIR_STATUSES)->sum($qty),
                'inspectionDue' => count($inspections),
                // Ownership split so the screen can distinguish owned vs rented at a glance.
                'owned' => $items->where('acquisition_type', '소유')->sum($qty),
                'rented' => $items->where('acquisition_type', '임대')->sum($qty),
                // Backward-compatible aliases (older callers/tests use these keys).
                'assets' => $items->sum($qty),
                'available' => $items->whereIn('status', self::STORAGE_STATUSES)->sum($qty),
                'checkedOut' => $items->where('status', '사용중')->sum($qty),
            ],
            'matrix' => [
                'categories' => array_keys($categories),
                'sites' => array_keys($sites),
                'cells' => $cells,
                'categoryMeta' => $categoryMeta,
            ],
            // 대분류 요약(필터 칩/그룹 헤더용).
            'groups' => $groups,
            // 자산 추적 리스트 — 취득 목적(프로젝트/현장) + 현 위치/현 사용자 + 구매·임대.
            'assets' => $items->map(fn (Equipment $e): array => [
                'assetId' => $e->equipment_code,
                'realId' => $e->id,
                'name' => $e->model,
                'category' => $e->equipment_type ?: '기타',
                'group' => $e->resolvedGroup(),
                'groupLabel' => Equipment::CATEGORY_GROUPS[$e->resolvedGroup()] ?? $e->resolvedGroup(),
                'trade' => $e->resolvedTrade(),
                'tradeLabel' => Equipment::TRADES[$e->resolvedTrade()] ?? $e->resolvedTrade(),
                'acquisitionType' => $e->acquisition_type ?: '임대',
                'vendor' => $e->vendor ?: '-',
                // 취득 목적 — 왜 샀나.
                'purposeProject' => $e->project?->project_code ?: '-',
                'purposeProjectName' => $e->project?->name ?: '',
                'purposeSite' => $e->purchased_for_site_id
                    ? (string) ($siteCodes[$e->purchased_for_site_id] ?? '-')
                    : '-',
                // 현 상태 — 지금 어디서 누가.
                'currentSite' => $currentSite($e),
                'holder' => $holder($e),
                'status' => $e->status ?: '대기중',
            ])->values()->all(),
            // 하위호환: 기존 갤러리/테스트가 참조하던 recent(화면 갤러리는 제거됨).
            'recent' => $items->take(10)->map(fn (Equipment $e): array => [
                'assetId' => $e->equipment_code,
                'category' => $e->equipment_type ?: '기타',
                'name' => $e->model,
                'brand' => $e->vendor ?: ($e->acquisition_type === '소유' ? '자사 보유' : '-'),
                'photoUrl' => $e->photo_front,
                'acquisitionType' => $e->acquisition_type,
            ])->values()->all(),
            'upcomingInspections' => $inspections,
        ];
    }

    /**
     * 자산 상세 — 모달용 실데이터.
     *
     * @return array<string, mixed>
     */
    public function assetDetail(string $assetId): array
    {
        $e = Equipment::query()
            ->with(['project', 'purchasedForSite', 'site', 'employee', 'activeRental.employee', 'activeRental.site', 'rentals.employee', 'rentals.site'])
            ->where('equipment_code', $assetId)
            ->first();

        if (! $e) {
            return ['success' => false, 'error' => "자산을 찾을 수 없습니다: {$assetId}"];
        }

        $currentSite = $e->activeRental?->site?->code
            ?: ($e->site?->code ?: '창고');
        $holder = $e->activeRental?->employee?->name
            ?: ($e->employee?->name ?: (is_array($e->payload) ? ($e->payload['custom_operator'] ?? '') : '') ?: '미배정');

        return [
            'success' => true,
            'asset' => [
                'assetId' => $e->equipment_code,
                'category' => $e->equipment_type,
                'group' => $e->resolvedGroup(),
                'groupLabel' => Equipment::CATEGORY_GROUPS[$e->resolvedGroup()] ?? $e->resolvedGroup(),
                'trade' => $e->resolvedTrade(),
                'tradeLabel' => Equipment::TRADES[$e->resolvedTrade()] ?? $e->resolvedTrade(),
                'name' => $e->model,
                'brand' => $e->vendor,
                'acquisitionType' => $e->acquisition_type,
                'status' => $e->status,
                // 취득 목적(왜 샀나) vs 현 상태(지금 어디서 누가).
                'purposeProject' => $e->project?->project_code,
                'purposeProjectName' => $e->project?->name,
                'purposeSite' => $e->purchasedForSite?->code,
                'currentSite' => $currentSite,
                'holder' => $holder,
                'location' => $currentSite,
                'assetValue' => (float) $e->asset_value,
                'inspectionDue' => $e->inspection_due_on?->format('Y-m-d'),
                'rentStart' => $e->rent_start?->format('Y-m-d'),
                'rentEnd' => $e->rent_end?->format('Y-m-d'),
                'dailyRate' => (int) $e->daily_rate,
            ],
            'photos' => array_values(array_filter([
                $e->photo_front, $e->photo_rear, $e->photo_left, $e->photo_right,
            ])),
            'contract' => is_array($e->payload) ? ($e->payload['details'] ?? null) : null,
            // 배정 이력 타임라인 — 누가·어느 현장·언제 가져갔고 반납했는지.
            'transactions' => $e->rentals->map(fn ($r): array => [
                'site' => $r->site?->code ?: '-',
                'holder' => $r->employee?->name ?: '-',
                'rentedAt' => $r->rented_at?->format('Y-m-d'),
                'returnedAt' => $r->returned_at?->format('Y-m-d'),
                'status' => $r->status,
            ])->values()->all(),
        ];
    }
}
