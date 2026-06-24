<?php

namespace App\Services\Wbs;

use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 공정관리(WBS) 서버 로직 — 레거시 SPA(renderWbs)의 트리/진척 API 를 실 DB 로 제공.
 *
 * 진척률 집계는 공수(manhours) 가중 평균을 기본으로 하고, 공수가 없으면 단순 평균으로 폴백한다.
 * 안전 작업카드(safety_work_items)와 연결된 SubTask 는 현장 실측 진행률을 끌어와 롤업한다.
 */
class WbsService
{
    /**
     * 프론트(renderWbs)가 기대하는 stages[].tasks[].sub_tasks[] 트리.
     *
     * @return array<string, mixed>
     */
    public function tree(string $projectCode, string $siteId = 'ALL'): array
    {
        $items = $this->itemsFor($projectCode, $siteId);
        $byParent = $items->groupBy('parent_id');

        $stages = $items->where('level', WbsItem::LEVEL_STAGE)->values()->map(function (WbsItem $stage) use ($byParent): array {
            $tasks = ($byParent[$stage->id] ?? collect())->map(function (WbsItem $task) use ($byParent): array {
                $subs = ($byParent[$task->id] ?? collect())->map->toSubtaskArray()->values()->all();

                return [
                    'task_no' => $task->node_no ?? '',
                    'task_name' => $task->name,
                    'sub_tasks' => $subs,
                ];
            })->values()->all();

            return [
                'stage_no' => $stage->node_no ?? (string) $stage->sort_order,
                'stage_name' => $stage->name,
                'tasks' => $tasks,
            ];
        })->values()->all();

        return ['success' => true, 'projectId' => $projectCode, 'stages' => $stages];
    }

    /**
     * KPI/진척 요약 — 전체 진척률(공수 가중), Stage별 진척률, 완료/진행중 카운트.
     *
     * @return array<string, mixed>
     */
    public function progressSummary(string $projectCode, string $siteId = 'ALL'): array
    {
        $items = $this->itemsFor($projectCode, $siteId);
        $byParent = $items->groupBy('parent_id');
        $subtasks = $items->where('level', WbsItem::LEVEL_SUBTASK);

        $stageProgress = $items->where('level', WbsItem::LEVEL_STAGE)->values()->map(function (WbsItem $stage) use ($byParent): array {
            $stageSubs = $this->descendantSubtasks($stage, $byParent);

            return [
                'stage_no' => $stage->node_no ?? (string) $stage->sort_order,
                'progress' => $this->weightedProgress($stageSubs),
            ];
        })->values()->all();

        return [
            'success' => true,
            'projectId' => $projectCode,
            'progress' => $this->weightedProgress($subtasks),
            'totalWbsCount' => $subtasks->count(),
            'completedCount' => $subtasks->where('status', WbsItem::STATUS_DONE)->count(),
            'inProgressCount' => $subtasks->where('status', WbsItem::STATUS_IN_PROGRESS)->count(),
            'stages' => $stageProgress,
        ];
    }

    /**
     * 빠른 상태 토글 (완료 ↔ AI생성 등). 상태에 맞춰 진척률을 동기화.
     *
     * @return array<string, mixed>
     */
    public function markStatus(string $wbsCode, string $status): array
    {
        $item = WbsItem::query()->where('wbs_code', $wbsCode)->first();
        if (! $item) {
            return ['success' => false, 'error' => "WBS 항목을 찾을 수 없습니다: {$wbsCode}"];
        }

        $item->status = $status;
        $this->syncProgressToStatus($item);
        $item->save();

        return ['success' => true, 'wbs_id' => $wbsCode, 'status' => $status, 'progress' => $item->progress];
    }

    /**
     * 상세 편집 — 프론트 모달이 보내는 한국어 키(상태/담당사/시작예정/종료예정 등)를 컬럼에 매핑.
     *
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    public function updateRow(string $wbsCode, array $updates): array
    {
        $item = WbsItem::query()->where('wbs_code', $wbsCode)->first();
        if (! $item) {
            return ['success' => false, 'error' => "WBS 항목을 찾을 수 없습니다: {$wbsCode}"];
        }

        $map = [
            '상태' => 'status', 'status' => 'status',
            '담당사' => 'company', 'company' => 'company',
            '시작예정' => 'planned_start', 'planned_start' => 'planned_start',
            '종료예정' => 'planned_end', 'planned_end' => 'planned_end',
            'EHS' => 'ehs', 'ehs' => 'ehs',
            '공수' => 'manhours', 'manhours' => 'manhours',
            '일수' => 'days', 'days' => 'days',
            '작업명' => 'name', 'name' => 'name',
        ];

        foreach ($updates as $key => $value) {
            $column = $map[$key] ?? null;
            if ($column === null || $value === '' || $value === null) {
                continue;
            }
            $item->{$column} = $value;
        }

        if (array_key_exists('상태', $updates) || array_key_exists('status', $updates)) {
            $this->syncProgressToStatus($item);
        }

        $item->save();

        return ['success' => true, 'wbs_id' => $wbsCode];
    }

    /**
     * AI(또는 import)가 생성한 stages[] 구조를 wbs_items 트리로 영속화.
     * 재생성 시 기존 SubTask 의 진행 상태(완료/진행중 등)와 안전카드 링크를 node_no 로 매칭해 보존한다.
     *
     * @param  array<int, mixed>  $stages
     * @return array{stages: int, tasks: int, subtasks: int}
     */
    public function importGenerated(string $projectCode, array $stages, string $siteId = 'ALL'): array
    {
        $siteRowId = $siteId !== 'ALL' ? Site::query()->where('code', $siteId)->value('id') : null;
        $projectRowId = \App\Models\Project::query()->where('project_code', $projectCode)->value('id');

        $counts = ['stages' => 0, 'tasks' => 0, 'subtasks' => 0];

        DB::transaction(function () use ($projectCode, $stages, $siteRowId, $projectRowId, &$counts): void {
            // 기존 SubTask 진행 상태 스냅샷 (node_no 기준) — 재생성 후 복원.
            $prior = WbsItem::query()
                ->where('project_code', $projectCode)
                ->where('level', WbsItem::LEVEL_SUBTASK)
                ->get()
                ->keyBy(fn (WbsItem $i) => (string) $i->node_no);

            WbsItem::query()->where('project_code', $projectCode)->delete();

            $base = [
                'project_id' => $projectRowId,
                'project_code' => $projectCode,
                'site_id' => $siteRowId,
                'source' => 'ai',
            ];

            $stageOrder = 0;
            foreach ($stages as $stage) {
                if (! is_array($stage)) {
                    continue;
                }
                $stageNo = (string) ($stage['stage_no'] ?? (string) ($stageOrder + 1));

                $stageItem = WbsItem::query()->create($base + [
                    'parent_id' => null,
                    'level' => WbsItem::LEVEL_STAGE,
                    'wbs_code' => "{$projectCode}-S-{$stageNo}",
                    'node_no' => $stageNo,
                    'name' => (string) ($stage['stage_name'] ?? "Stage {$stageNo}"),
                    'sort_order' => $stageOrder++,
                ]);
                $counts['stages']++;

                $taskOrder = 0;
                foreach ((is_array($stage['tasks'] ?? null) ? $stage['tasks'] : []) as $task) {
                    if (! is_array($task)) {
                        continue;
                    }
                    $taskNo = (string) ($task['task_no'] ?? "{$stageNo}.{$taskOrder}");

                    $taskItem = WbsItem::query()->create($base + [
                        'parent_id' => $stageItem->id,
                        'level' => WbsItem::LEVEL_TASK,
                        'wbs_code' => "{$projectCode}-T-{$taskNo}",
                        'node_no' => $taskNo,
                        'name' => (string) ($task['task_name'] ?? "Task {$taskNo}"),
                        'sort_order' => $taskOrder++,
                    ]);
                    $counts['tasks']++;

                    $subOrder = 0;
                    foreach ((is_array($task['sub_tasks'] ?? null) ? $task['sub_tasks'] : []) as $sub) {
                        if (! is_array($sub)) {
                            continue;
                        }
                        $subNo = (string) ($sub['sub_no'] ?? "{$taskNo}.{$subOrder}");
                        $keep = $prior->get($subNo);

                        WbsItem::query()->create($base + [
                            'parent_id' => $taskItem->id,
                            'level' => WbsItem::LEVEL_SUBTASK,
                            'wbs_code' => "{$projectCode}-W-{$subNo}",
                            'node_no' => $subNo,
                            'name' => (string) ($sub['sub_name'] ?? "Sub {$subNo}"),
                            'company' => $sub['company'] ?? null,
                            'manhours' => isset($sub['manhours']) ? (float) $sub['manhours'] : null,
                            'days' => isset($sub['days']) ? (int) $sub['days'] : null,
                            'ehs' => $sub['ehs'] ?? null,
                            // 보존: 기존 현장 진행 상태/진척/안전카드 링크가 있으면 유지.
                            'status' => $keep->status ?? '검수완료',
                            'progress' => $keep->progress ?? 0,
                            'safety_work_code' => $keep->safety_work_code ?? null,
                            'sort_order' => $subOrder++,
                        ]);
                        $counts['subtasks']++;
                    }
                }
            }
        });

        return $counts;
    }

    /**
     * 같은 프로젝트의 WBS 노드 + 안전카드 링크를 한 번에 로드 (scope 적용).
     */
    private function itemsFor(string $projectCode, string $siteId): Collection
    {
        $query = WbsItem::query()
            ->where('project_code', $projectCode)
            ->with('safetyWorkItem')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($siteId !== 'ALL') {
            $resolved = Site::query()->where('code', $siteId)->value('id');
            $query->where(function ($q) use ($resolved): void {
                $q->whereNull('site_id')->orWhere('site_id', $resolved);
            });
        }

        return $query->get();
    }

    /**
     * Stage/Task 하위의 모든 SubTask 를 평탄화.
     */
    private function descendantSubtasks(WbsItem $node, Collection $byParent): Collection
    {
        $result = collect();
        foreach ($byParent[$node->id] ?? collect() as $child) {
            if ($child->level === WbsItem::LEVEL_SUBTASK) {
                $result->push($child);
            } else {
                $result = $result->merge($this->descendantSubtasks($child, $byParent));
            }
        }

        return $result;
    }

    /**
     * 공수 가중 진척률(%). 공수 합이 0이면 단순 평균으로 폴백.
     */
    private function weightedProgress(Collection $subtasks): int
    {
        if ($subtasks->isEmpty()) {
            return 0;
        }

        $totalWeight = $subtasks->sum(fn (WbsItem $s) => max((float) $s->manhours, 0));

        if ($totalWeight <= 0) {
            return (int) round($subtasks->avg(fn (WbsItem $s) => $s->effectiveProgress()));
        }

        $weighted = $subtasks->sum(fn (WbsItem $s) => max((float) $s->manhours, 0) * $s->effectiveProgress());

        return (int) round($weighted / $totalWeight);
    }

    /**
     * 상태 → 진척률 동기화. 완료=100, AI생성/검수완료/보류=0, 진행중은 기존값(최소 10) 유지.
     */
    private function syncProgressToStatus(WbsItem $item): void
    {
        $item->progress = match ($item->status) {
            WbsItem::STATUS_DONE => 100,
            WbsItem::STATUS_IN_PROGRESS => max((int) $item->progress, 10),
            default => 0,
        };
    }
}
