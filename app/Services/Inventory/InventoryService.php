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
        $query = Equipment::query()->orderByDesc('id');

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

        $location = fn (Equipment $e): string => $e->site_id
            ? (string) ($siteCodes[$e->site_id] ?? '미지정')
            : '창고';

        // ── 매트릭스 (카테고리 × 위치) ──
        $categories = [];
        $sites = [];
        $cells = [];
        foreach ($items as $e) {
            $cat = $e->equipment_type ?: '기타';
            $loc = $location($e);
            $categories[$cat] = true;
            $sites[$loc] = true;
            $cells[$cat][$loc] = ($cells[$cat][$loc] ?? 0) + $qty($e);
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
            ],
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
        $e = Equipment::query()->where('equipment_code', $assetId)->first();

        if (! $e) {
            return ['success' => false, 'error' => "자산을 찾을 수 없습니다: {$assetId}"];
        }

        $siteCode = $e->site_id ? Site::query()->where('id', $e->site_id)->value('code') : '창고';

        return [
            'success' => true,
            'asset' => [
                'assetId' => $e->equipment_code,
                'category' => $e->equipment_type,
                'name' => $e->model,
                'brand' => $e->vendor,
                'acquisitionType' => $e->acquisition_type,
                'status' => $e->status,
                'location' => $siteCode,
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
            'transactions' => [],
        ];
    }
}
