<?php

namespace App\Services\Wbs;

use App\Models\WbsItem;
use Illuminate\Support\Collection;

/**
 * 자사 이력 기반 지연 위험 — 공종별 "약속을 지킨 비율"에서 위험을 읽는다.
 *
 * nPlan 같은 제품은 수백만 개 타사 공정표로 위험을 학습한다. 우리는 우리 데이터로
 * 한다: 주간 리듬(P2)이 쌓는 약속(committed_week)·이행 기록이 곧 "이 공종이 계획을
 * 지키는가"의 실측이다. 표본이 적을 때는 입을 다문다 — 두 건으로 공종을 낙인찍는
 * 경고는 신뢰를 깎아 먹는다.
 */
class DelayRiskService
{
    /** 이 표본 수 미만이면 판단하지 않는다. */
    private const MIN_SAMPLES = 4;

    /** 이 미이행률 이상이면 위험으로 본다. */
    private const RISK_THRESHOLD = 0.5;

    /**
     * 공종별 약속 미이행률 — 전 프로젝트 누적(자사 이력).
     *
     * @return Collection<string, array{samples: int, missed: int, missRate: float, topReason: ?string}>
     */
    public function tradeMissRates(): Collection
    {
        $rows = WbsItem::query()
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->whereNotNull('committed_week')
            ->where('committed_week', '<', WeeklyPlanService::weekKey()) // 지난 주까지의 확정 기록만
            ->whereNotNull('trade')
            ->get(['trade', 'status', 'incomplete_reason']);

        return $rows->groupBy(fn (WbsItem $i): string => trim((string) $i->trade))
            ->map(function (Collection $group): array {
                $missed = $group->filter(fn (WbsItem $i): bool => $i->status !== WbsItem::STATUS_DONE);
                $topReason = $missed->groupBy(fn (WbsItem $i): string => (string) ($i->incomplete_reason ?: ''))
                    ->map->count()->sortDesc()->keys()->first(fn ($k): bool => $k !== '');

                return [
                    'samples' => $group->count(),
                    'missed' => $missed->count(),
                    'missRate' => round($missed->count() / max(1, $group->count()), 2),
                    'topReason' => $topReason ?: null,
                ];
            });
    }

    /**
     * 새 공정표의 위험 경고 — 이력상 약속을 자주 못 지킨 공종의 작업 수를 알려준다.
     *
     * @return array<int, string>
     */
    public function warningsFor(string $projectCode): array
    {
        $rates = $this->tradeMissRates()
            ->filter(fn (array $r): bool => $r['samples'] >= self::MIN_SAMPLES && $r['missRate'] >= self::RISK_THRESHOLD);
        if ($rates->isEmpty()) {
            return [];
        }

        $byTrade = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->whereNotNull('trade')
            ->get(['trade'])
            ->groupBy(fn (WbsItem $i): string => trim((string) $i->trade))
            ->map->count();

        $warnings = [];
        foreach ($rates as $trade => $r) {
            $count = (int) ($byTrade[$trade] ?? 0);
            if ($count === 0) {
                continue;
            }
            $reason = $r['topReason'] ? ' (최다 사유: '.(WeeklyPlanService::REASONS[$r['topReason']] ?? $r['topReason']).')' : '';
            $warnings[] = sprintf(
                '⚠️ 지연 위험: %s 공종은 최근 주간 약속 %d건 중 %d건을 못 지켰습니다%s — 이 공정표에 %d개 작업이 있습니다. 버퍼를 검토하세요.',
                $trade, $r['samples'], $r['missed'], $reason, $count,
            );
        }

        return $warnings;
    }
}
