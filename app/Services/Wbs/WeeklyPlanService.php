<?php

namespace App\Services\Wbs;

use App\Models\ProcurementItem;
use App\Models\WbsItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 주간 리듬(Last Planner System 이식) — 3주 선행 뷰 · 이번 주 약속 · PPC.
 *
 * 세계적으로 실제 현장 운영의 표준은 마스터 공정표가 아니라 "주간 약속"이다:
 * 매주 이번 주에 끝낼 작업을 약속(commit)하고, 주말에 약속 이행률(PPC)을 재고,
 * 못 지킨 사유를 표준 코드로 모은다 — 사유 통계가 곧 "왜 매주 늦는가"의 답이다.
 *
 * 시스템이 하는 일:
 *  - 3주 선행 뷰: 앞으로 3주 안에 걸린 작업을 주 단위로 묶고, 시작 가능(ready)
 *    여부를 제약(선행 미완·자재 미입고·안전계획 없음)과 함께 판정한다.
 *    제약 제거가 관리의 본질이므로 "무엇이 막고 있는가"를 이름으로 말한다.
 *  - 매주 월요일: 지난주 PPC 자동 집계 + 이번 주 약속 자동 제안(사장은 검토만).
 *  - 약속 미이행 사유는 표준 코드 드롭다운 — 자유 텍스트는 통계가 안 된다.
 */
class WeeklyPlanService
{
    /** LCI Reasons for Variance 표준을 현장 어휘로. */
    public const REASONS = [
        'manpower' => '인력 부족',
        'materials' => '자재 지연',
        'predecessor' => '선행작업 미완',
        'change' => '변경지시',
        'weather' => '날씨',
        'inspection' => '검사 대기',
        'equipment' => '장비 문제',
        'other' => '기타',
    ];

    /** ISO 주 표기 — 'YYYY-Www'. 약속·PPC 의 키다. */
    public static function weekKey(?Carbon $date = null): string
    {
        $date ??= Carbon::now();

        return $date->format('o-\WW');
    }

    /**
     * 3주 선행 뷰 — 주별 작업 + 시작 가능 판정.
     *
     * @return array<string, mixed>
     */
    public function lookahead(string $projectCode, string $siteId = 'ALL', int $weeks = 3): array
    {
        $start = Carbon::now()->startOfWeek();
        $end = $start->copy()->addWeeks($weeks)->subDay();

        $items = $this->subtasks($projectCode)
            ->filter(function (WbsItem $i) use ($start, $end): bool {
                if ($i->status === WbsItem::STATUS_DONE || $i->looksLikeProcurement()) {
                    return false;
                }
                $s = $i->planned_start?->toDateString();
                $e = $i->planned_end?->toDateString();

                // 창에 걸치는 작업: 시작이 창 안이거나, 이미 시작해 창까지 이어지는 것.
                return $s !== null && $s <= $end->toDateString() && ($e === null || $e >= $start->toDateString());
            });

        $doneByActivity = $this->doneMap($projectCode);
        $procurementOpen = $this->openProcurement($projectCode);
        $thisWeek = self::weekKey();

        $byWeek = [];
        foreach ($items as $item) {
            $weekOf = self::weekKey(Carbon::parse(max($item->planned_start->toDateString(), $start->toDateString())));
            $constraints = $this->constraintsFor($item, $doneByActivity, $procurementOpen);

            $byWeek[$weekOf][] = [
                'wbs_id' => $item->wbs_code,
                'activity_id' => $item->activity_id,
                'name' => $item->name,
                'trade' => $item->trade,
                'start' => $item->planned_start?->toDateString(),
                'end' => $item->planned_end?->toDateString(),
                'isCritical' => (bool) $item->is_critical,
                'status' => $item->status,
                'committed' => $item->committed_week === $thisWeek,
                'ready' => $constraints === [],
                'constraints' => $constraints,
            ];
        }
        ksort($byWeek);

        return [
            'success' => true,
            'projectId' => $projectCode,
            'thisWeek' => $thisWeek,
            'weeks' => collect($byWeek)->map(fn (array $rows, string $week): array => [
                'week' => $week,
                'items' => $rows,
                'readyCount' => count(array_filter($rows, fn (array $r): bool => $r['ready'])),
                'blockedCount' => count(array_filter($rows, fn (array $r): bool => ! $r['ready'])),
            ])->values()->all(),
            'ppc' => $this->ppc($projectCode, self::weekKey(Carbon::now()->subWeek())),
            'reasons' => self::REASONS,
        ];
    }

    /**
     * 시작을 막는 제약 — 이름으로 말한다. 비어 있으면 시작 가능(ready).
     *
     * @param  array<string, bool>  $doneByActivity
     * @param  Collection<int, ProcurementItem>  $procurementOpen
     * @return array<int, string>
     */
    private function constraintsFor(WbsItem $item, array $doneByActivity, Collection $procurementOpen): array
    {
        $constraints = [];

        foreach ((array) $item->preds as $pred) {
            $pred = trim((string) $pred);
            if ($pred !== '' && array_key_exists($pred, $doneByActivity) && ! $doneByActivity[$pred]) {
                $constraints[] = "선행 {$pred} 미완";
            }
        }

        foreach ($procurementOpen->where('wbs_code', $item->wbs_code) as $po) {
            $constraints[] = '자재 미입고'.($po->po_no ? " (PO {$po->po_no})" : '');
        }

        // 인원이 붙는 작업인데 오늘까지 안전카드가 없으면 — 시작하는 날 TBM 부터 막힌다.
        if ((float) $item->crew_size > 0
            && $item->planned_start?->lte(Carbon::today())
            && ! $item->relationLoaded('safetyWorkItems')) {
            // 관계 미로드 시 판단하지 않는다(과잉 경고 방지).
        } elseif ((float) $item->crew_size > 0
            && $item->planned_start?->lte(Carbon::today())
            && $item->safetyWorkItems->isEmpty()) {
            $constraints[] = '안전계획 없음';
        }

        return $constraints;
    }

    /**
     * 이번 주 약속 자동 제안 — 이번 주 창에 걸린 미완료 작업을 약속으로 표시한다.
     * 이미 사람이 손댄 주(약속을 뺀 작업)는 다시 덮지 않는다: 같은 주에 두 번 돌려도
     * "이미 그 주로 표시된 것"만 남기고 새로 걸린 것만 추가한다.
     */
    public function commitThisWeek(string $projectCode): int
    {
        $week = self::weekKey();
        $start = Carbon::now()->startOfWeek()->toDateString();
        $end = Carbon::now()->endOfWeek()->toDateString();

        $candidates = $this->subtasks($projectCode)
            ->filter(fn (WbsItem $i): bool => $i->status !== WbsItem::STATUS_DONE
                && ! $i->looksLikeProcurement()
                && $i->committed_week === null
                // 사람이 이 주 약속에서 뺀 작업은 다시 제안하지 않는다 — 자동이 검토를 이기면 안 된다.
                && data_get($i->payload, 'week_declined') !== $week
                && $i->planned_start !== null
                && $i->planned_start->toDateString() <= $end
                && ($i->planned_end === null || $i->planned_end->toDateString() >= $start));

        foreach ($candidates as $item) {
            $item->forceFill(['committed_week' => $week, 'incomplete_reason' => null])->save();
        }

        return $candidates->count();
    }

    /** 약속 넣기/빼기 — 사장의 검토 버튼. */
    public function toggleCommit(string $wbsCode): array
    {
        $item = WbsItem::query()->where('wbs_code', $wbsCode)->first();
        if (! $item) {
            return ['success' => false, 'error' => "WBS 항목을 찾을 수 없습니다: {$wbsCode}"];
        }

        $week = self::weekKey();
        $payload = (array) $item->payload;
        if ($item->committed_week === $week) {
            // 약속에서 뺀다 — 이 주에는 자동 제안이 다시 넣지 못하게 기록해 둔다.
            $item->committed_week = null;
            $payload['week_declined'] = $week;
        } else {
            $item->committed_week = $week;
            $item->incomplete_reason = null;
            unset($payload['week_declined']);
        }
        $item->payload = $payload ?: null;
        $item->save();

        return ['success' => true, 'wbs_id' => $wbsCode, 'committed' => $item->committed_week === $week];
    }

    /**
     * 약속 이행률(PPC) — 그 주에 약속한 작업 중 끝난 비율 + 미완료 사유 통계.
     *
     * @return array<string, mixed>|null
     */
    public function ppc(string $projectCode, string $week): ?array
    {
        $committed = $this->subtasks($projectCode)->where('committed_week', $week);
        if ($committed->isEmpty()) {
            return null;
        }

        $done = $committed->filter(fn (WbsItem $i): bool => $i->status === WbsItem::STATUS_DONE);
        $missed = $committed->reject(fn (WbsItem $i): bool => $i->status === WbsItem::STATUS_DONE);

        $reasons = $missed->groupBy(fn (WbsItem $i): string => (string) ($i->incomplete_reason ?: 'unspecified'))
            ->map->count()->sortDesc();

        return [
            'week' => $week,
            'committed' => $committed->count(),
            'done' => $done->count(),
            'ppc' => (int) round($done->count() / max(1, $committed->count()) * 100),
            'reasons' => $reasons->all(),
            'missed' => $missed->map(fn (WbsItem $i): array => [
                'wbs_id' => $i->wbs_code,
                'name' => $i->name,
                'reason' => $i->incomplete_reason,
                'reasonLabel' => self::REASONS[$i->incomplete_reason] ?? ($i->incomplete_reason ?: '사유 미입력'),
            ])->values()->all(),
        ];
    }

    /**
     * 월요일 아침 한 바퀴 — 지난주 PPC 집계 + 이번 주 약속 제안. 방에 보낼 요약문을 돌려준다.
     */
    public function weeklyReview(string $projectCode): array
    {
        $lastWeek = self::weekKey(Carbon::now()->subWeek());
        $ppc = $this->ppc($projectCode, $lastWeek);
        $committed = $this->commitThisWeek($projectCode);

        $lines = ["📅 주간 계획 ({$projectCode})"];
        if ($ppc !== null) {
            $lines[] = "지난주 약속 이행률(PPC) {$ppc['ppc']}% — {$ppc['done']}/{$ppc['committed']}건 완료";
            foreach (array_slice($ppc['missed'], 0, 5) as $m) {
                $lines[] = "  · 미완료: {$m['name']} — {$m['reasonLabel']}";
            }
            if ($ppc['reasons'] !== []) {
                $top = array_key_first($ppc['reasons']);
                $topLabel = self::REASONS[$top] ?? ($top === 'unspecified' ? '사유 미입력' : $top);
                $lines[] = "가장 큰 원인: {$topLabel} ({$ppc['reasons'][$top]}건)";
            }
        } else {
            $lines[] = '지난주 약속이 없었습니다.';
        }
        $lines[] = $committed > 0
            ? "이번 주 약속 {$committed}건을 제안했습니다 — 공정 화면 주간 계획에서 검토해 주세요."
            : '이번 주 창에 새로 걸린 작업이 없습니다.';

        return [
            'success' => true,
            'projectId' => $projectCode,
            'ppc' => $ppc,
            'committedCount' => $committed,
            'summary' => implode("\n", $lines),
        ];
    }

    /** @return Collection<int, WbsItem> */
    private function subtasks(string $projectCode): Collection
    {
        return WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->with('safetyWorkItems')
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /** activity_id → 완료 여부 (선행 제약 판정용). */
    private function doneMap(string $projectCode): array
    {
        return $this->subtasks($projectCode)
            ->filter(fn (WbsItem $i): bool => (string) $i->activity_id !== '')
            ->mapWithKeys(fn (WbsItem $i): array => [(string) $i->activity_id => $i->status === WbsItem::STATUS_DONE])
            ->all();
    }

    /** 아직 입고되지 않은 발주 — 자재 제약 판정용. */
    private function openProcurement(string $projectCode): Collection
    {
        return ProcurementItem::query()
            ->where('project_code', $projectCode)
            ->whereNotNull('wbs_code')
            ->where('status', '!=', '입고완료')
            ->get(['id', 'wbs_code', 'po_no', 'status']);
    }
}
