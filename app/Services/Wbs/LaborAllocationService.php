<?php

namespace App\Services\Wbs;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Support\Carbon;

/**
 * 공정별 인력 배치 현황 — 인사/출퇴근을 공정관리(WBS)와 연동한다.
 *
 * 오늘의 WBS 작업별 계획인원(crew_size) vs 실투입(안전카드 서명 인원)을 대조해, 인력이 모자란
 * 임계경로 작업을 먼저 띄운다. "오늘 어디에 사람을 더 넣어야 하나"를 한 화면에서 답한다.
 */
class LaborAllocationService
{
    /** 공종 코드 → 한글 라벨(표시용). */
    private const TRADE_LABEL = [
        'GC' => '공통', 'ELEC' => '전기', 'PLUMB' => '배관', 'MECH' => '기계', 'FIRE' => '방화',
        'FRAME' => '골조', 'DEMO' => '해체', 'DOOR' => '도어', 'PAINT' => '도장', 'CEIL' => '천장',
        'TILE' => '타일', 'MILL' => '밀워크', 'FLOOR' => '바닥', 'WELD' => '용접', 'INSP' => '검사',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forSite(string $siteId, ?string $date = null): array
    {
        $date ??= Carbon::today()->toDateString();
        $siteRowId = $this->resolveSiteId($siteId);

        $subs = WbsItem::query()->where('level', WbsItem::LEVEL_SUBTASK)
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))
            ->with(['safetyWorkItems' => fn ($q) => $q->where('work_date', $date)->with('signatures')])
            ->get()
            ->filter(function (WbsItem $i) use ($date): bool {
                if ($i->status === WbsItem::STATUS_DONE || $i->looksLikeProcurement()) {
                    return false;
                }
                $s = $i->planned_start?->toDateString();
                $e = $i->planned_end?->toDateString();
                if ($s === null || $e === null) {
                    return $i->status === WbsItem::STATUS_IN_PROGRESS;
                }

                return $s <= $date && $date <= $e;
            });

        $rows = $subs->map(function (WbsItem $i): array {
            $planned = (int) round((float) ($i->crew_size ?? 0));
            $actual = $i->actualCrewCount();
            $short = max(0, $planned - $actual);
            $critical = (bool) $i->is_critical;
            $status = $planned === 0 ? 'na'
                : ($actual > $planned ? 'surplus'
                    : ($actual === $planned ? 'ok'
                        : ($critical ? 'critical_short' : 'short')));

            return [
                'activityId' => $i->activity_id,
                'wbsId' => $i->wbs_code,
                'name' => $i->name,
                'trade' => $i->trade ?: '',
                'tradeLabel' => self::TRADE_LABEL[strtoupper((string) $i->trade)] ?? '',
                'planned' => $planned,
                'actual' => $actual,
                'shortBy' => $short,
                'fillPct' => $planned > 0 ? min(100, (int) round($actual / $planned * 100)) : ($actual > 0 ? 100 : 0),
                'isCritical' => $critical,
                'ehsHigh' => $i->ehs === 'high',
                'status' => $status,
            ];
        })->sort(function (array $a, array $b): int {
            $rank = fn (array $r) => $r['status'] === 'critical_short' ? 0 : ($r['status'] === 'short' ? 1 : 3);
            return [$rank($a), -$a['shortBy']] <=> [$rank($b), -$b['shortBy']];
        })->values();

        // 출역(오늘 clock_in) — 현장 스코프.
        $present = AttendanceLog::query()->where('attendance_date', $date)->where('event_type', 'clock_in')
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))->distinct()->count('employee_id');

        $demand = (int) $rows->sum('planned');
        $assigned = (int) $rows->sum('actual');
        $criticalShort = $rows->where('status', 'critical_short')->count();
        $activeCount = Employee::query()->where('employment_status', 'active')
            ->when($siteRowId, fn ($q) => $q->where('site_id', $siteRowId))->count();

        return [
            'success' => true,
            'date' => $date,
            'site' => $siteRowId ? (Site::query()->whereKey($siteRowId)->value('code') ?: $siteId) : '전 현장',
            'items' => $rows->all(),
            'kpi' => [
                'present' => $present,
                'active' => $activeCount,
                'demand' => $demand,                          // 오늘 필요한 총 인력(계획 crew 합)
                'assigned' => $assigned,                       // 공정에 배치된 인원(실투입 합)
                'unassigned' => max(0, $present - $assigned),  // 출근했으나 미배치
                'criticalShort' => $criticalShort,
                'plannedMH' => $demand * 8,
                'actualMH' => $assigned * 8,
            ],
        ];
    }

    private function resolveSiteId(string $siteId): ?int
    {
        $siteId = trim($siteId);
        if ($siteId === '' || in_array(strtoupper($siteId), ['ALL', 'GLOBAL'], true)) {
            return null;
        }
        if (is_numeric($siteId)) {
            return (int) $siteId;
        }
        $code = str_contains($siteId, ' - ') ? trim(strstr($siteId, ' - ', true)) : $siteId;

        return Site::query()->where('code', $siteId)->orWhere('code', $code)->orWhere('name', $siteId)->value('id');
    }
}
