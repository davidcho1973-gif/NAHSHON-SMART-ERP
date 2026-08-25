<?php

namespace App\Services\Wbs;

use App\Models\Project;
use App\Models\SafetyWorkItem;
use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 공정관리(WBS) 서버 로직 — 레거시 SPA(renderWbs)의 트리/진척 API 를 실 DB 로 제공.
 *
 * 진척률 집계는 공수(manhours) 가중 평균을 기본으로 하고, 공수가 없으면 공기(days), 그것도 없으면
 * 단순 평균으로 폴백한다(weightedProgress 참고).
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

        // 현장(site) 스코프로 걸러져 비어 보이는 경우를 프론트가 구분할 수 있게,
        // 스코프 없이 존재하는 SubTask 총수를 함께 내려준다.
        $unscopedTotal = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->count();

        return ['success' => true, 'projectId' => $projectCode, 'stages' => $stages, 'unscopedTotal' => $unscopedTotal];
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
            // 예상 준공 = 마지막 작업의 계획 종료일. CPM 엔진이 일정을 최신으로 유지하므로
            // 이 최대값이 곧 "지금 이대로 가면 언제 끝나는가"다.
            'projectedEnd' => $subtasks->map(fn (WbsItem $i) => $i->planned_end?->toDateString())->filter()->max(),
            // 프로젝트 원가 대조 — 계획(배분원가 합) vs 실적(원장의 project_id 귀속 지출).
            // 원가 귀속 칸을 읽는 집계가 0건이라 프로젝트 수지를 엑셀로 내던 구멍(점검 H).
            'cost' => $this->costSummary($projectCode, $subtasks),
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
        $item = WbsItem::query()->where('wbs_code', $wbsCode)->with('safetyWorkItems.signatures')->first();
        if (! $item) {
            return ['success' => false, 'error' => "WBS 항목을 찾을 수 없습니다: {$wbsCode}"];
        }

        if ($gate = $this->tbmGate($item, $status)) {
            return ['success' => false, 'error' => $gate, 'gated' => true];
        }

        $item->status = $status;
        $this->syncProgressToStatus($item);
        $item->save();

        // 상태 변화는 CPM 의 고정/이동 판정을 바꾼다(진행중·완료는 날짜가 실적으로 굳는다).
        $cpm = $item->wasChanged('status') ? $this->recomputeCpm($item) : null;

        return ['success' => true, 'wbs_id' => $wbsCode, 'status' => $status, 'progress' => $item->progress, 'cpm' => $cpm];
    }

    /**
     * 상세 편집 — 프론트 모달이 보내는 한국어 키(상태/담당사/시작예정/종료예정 등)를 컬럼에 매핑.
     *
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    public function updateRow(string $wbsCode, array $updates): array
    {
        $item = WbsItem::query()->where('wbs_code', $wbsCode)->with('safetyWorkItems.signatures')->first();
        if (! $item) {
            return ['success' => false, 'error' => "WBS 항목을 찾을 수 없습니다: {$wbsCode}"];
        }

        // TBM 게이트는 상태가 실제로 "바뀔" 때만 적용 — 이미 진행중인 행의 공수/일정 편집을 막지 않는다.
        $targetStatus = (string) ($updates['상태'] ?? $updates['status'] ?? '');
        if ($targetStatus !== '' && $targetStatus !== $item->status && ($gate = $this->tbmGate($item, $targetStatus))) {
            return ['success' => false, 'error' => $gate, 'gated' => true];
        }

        $map = [
            '상태' => 'status', 'status' => 'status',
            '담당사' => 'company', 'company' => 'company',
            '공종' => 'trade', 'trade' => 'trade',
            '시작예정' => 'planned_start', 'planned_start' => 'planned_start',
            '종료예정' => 'planned_end', 'planned_end' => 'planned_end',
            'EHS' => 'ehs', 'ehs' => 'ehs',
            '공수' => 'manhours', 'manhours' => 'manhours',
            '일수' => 'days', 'days' => 'days',
            '작업명' => 'name', 'name' => 'name',
            '투입조' => 'crew_text', 'crew_text' => 'crew_text',
            '장비' => 'equipment', 'equipment' => 'equipment',
            '진척률' => 'progress', 'progress' => 'progress',
        ];

        // 빈 값은 기본적으로 "변경 없음"이지만, 배정 해제가 의미 있는 컬럼은 명시적 클리어로 처리.
        // (HTTP 경로에서는 ConvertEmptyStringsToNull 미들웨어가 '' 를 null 로 바꿔 도달하므로 둘 다 클리어로 본다.)
        $clearable = ['company', 'planned_start', 'planned_end'];

        foreach ($updates as $key => $value) {
            $column = $map[$key] ?? null;
            if ($column === null) {
                continue;
            }

            // 투입조: 자유 텍스트를 인원 수·역할로 재파싱해 함께 갱신(안전카드 서명란이 이 값을 쓴다).
            if ($column === 'crew_text') {
                $crew = app(CrewParser::class)->parse((string) $value);
                $item->crew_text = $crew['raw'] !== '' ? $crew['raw'] : null;
                $item->crew_size = $crew['size'] ?: null;
                $item->crew_roles = $crew['roles'] ?: null;

                continue;
            }
            // 장비: 쉼표 구분 문자열 → 배열(캐스트).
            if ($column === 'equipment') {
                $arr = is_array($value)
                    ? $value
                    : array_values(array_filter(array_map('trim', explode(',', (string) $value))));
                $item->equipment = $arr ?: null;

                continue;
            }
            // 진척률: 0~100 정수. 빈 값이면 변경 없음.
            if ($column === 'progress') {
                if ($value === '' || $value === null) {
                    continue;
                }
                $item->progress = max(0, min(100, (int) $value));

                continue;
            }

            if ($value === '' || $value === null) {
                if (in_array($column, $clearable, true)) {
                    $item->{$column} = null;
                }

                continue;
            }
            $item->{$column} = $value;
        }

        if (array_key_exists('상태', $updates) || array_key_exists('status', $updates)) {
            $this->syncProgressToStatus($item);
        }

        $item->save();

        $datesEdited = $item->wasChanged(['planned_start', 'planned_end']);
        $needsCpm = $datesEdited || $item->wasChanged(['days', 'status']);

        // 사람이 이 행의 날짜를 직접 정했다 — CPM 기준선(시작일·선행 간격)을 새 날짜로 다시 포착하게 한다.
        if ($datesEdited) {
            $payload = (array) $item->payload;
            if (array_key_exists('cpm_baseline', $payload)) {
                unset($payload['cpm_baseline']);
                $item->payload = $payload ?: null;
                $item->save();
            }
        }

        // 일정·공기·상태가 바뀌면 후속 공정과 준공일을 즉시 다시 계산한다.
        $cpm = $needsCpm ? $this->recomputeCpm($item) : null;

        return ['success' => true, 'wbs_id' => $wbsCode, 'cpm' => $cpm];
    }

    /**
     * "오늘 해야 할 작업" — 공정표에서 파생한 그날의 작업 목록.
     *
     * 일별 계획을 따로 저장하지 않고 계획일정(ES~EF)에서 파생한다. 일정이 바뀌면 목록도 같이
     * 바뀌어야 하는데, 별도 테이블로 떠두면 곧 어긋나기 때문이다.
     *
     * 정렬은 현장이 실제로 판단하는 순서를 따른다: 임계경로 먼저 → 여유 적은 것 → 시작일 순.
     *
     * @return array<string, mixed>
     */
    public function todayWork(string $projectCode, string $siteId = 'ALL', ?string $date = null): array
    {
        $date ??= now()->toDateString();

        $items = $this->itemsFor($projectCode, $siteId)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->filter(function (WbsItem $i) use ($date): bool {
                // 오늘 창에 걸린 작업: 시작 <= 오늘 <= 종료. 일정이 없으면 진행중인 것만.
                $start = $i->planned_start?->toDateString();
                $end = $i->planned_end?->toDateString();

                if ($start === null || $end === null) {
                    return $i->status === WbsItem::STATUS_IN_PROGRESS;
                }

                return $start <= $date && $date <= $end;
            })
            ->reject(fn (WbsItem $i) => $i->status === WbsItem::STATUS_DONE)
            // 조달성 공정(발주·조달·구매)은 '조달 관리'에서 별도로 다룬다 — 현장 노무 목록에서 제외.
            ->reject(fn (WbsItem $i) => $i->looksLikeProcurement());

        $rows = $items->map(function (WbsItem $i) use ($date): array {
            $card = $i->safetyCardFor($date);
            $needsPlan = (float) $i->crew_size > 0 && $card === null;

            return $i->toSubtaskArray($date) + [
                'stagePath' => $this->stagePath($i),
                // 현장이 지금 무엇을 해야 하는지 한 단어로: 안전계획 → TBM → 진행 → 마감
                'nextAction' => match (true) {
                    $needsPlan => '안전계획',
                    $card !== null && ! $card->isTbmCleared() => 'TBM',
                    $i->status !== WbsItem::STATUS_IN_PROGRESS => '시작',
                    default => '진행중',
                },
                'blocked' => $needsPlan || ($card !== null && ! $card->isTbmCleared()),
            ];
        })->values();

        // 임계경로 → 여유 적은 순 → 시작일 순.
        $sorted = $rows->sortBy([
            fn ($a, $b) => ($b['isCritical'] <=> $a['isCritical']),
            fn ($a, $b) => (($a['floatDays'] ?? 9999) <=> ($b['floatDays'] ?? 9999)),
            fn ($a, $b) => (($a['plannedStart'] ?? '') <=> ($b['plannedStart'] ?? '')),
        ])->values()->all();

        return [
            'success' => true,
            'projectId' => $projectCode,
            'date' => $date,
            'items' => $sorted,
            'total' => count($sorted),
            'criticalCount' => $rows->where('isCritical', true)->count(),
            'needsPlanCount' => $rows->where('nextAction', '안전계획')->count(),
            'tbmWaitCount' => $rows->where('nextAction', 'TBM')->count(),
            'plannedCrew' => round((float) $rows->sum(fn ($r) => (float) ($r['crewSize'] ?? 0)), 1),
        ];
    }

    /**
     * "Stage > Task" 경로 — 오늘 목록에서 작업의 위치를 알려준다.
     */
    private function stagePath(WbsItem $sub): string
    {
        $task = $sub->parent()->first();
        $stage = $task?->parent()->first();

        return trim(implode(' › ', array_filter([$stage?->name, $task?->name])));
    }

    /**
     * 공정 → 안전 작업카드 생성. "작업을 시작한다"의 실제 구현체.
     *
     * 계획(인원·장비·물량)을 그날의 안전카드로 옮긴다. 여기서부터 TBM/서명 → 출퇴근 → 급여로 이어진다.
     * 같은 날 카드가 이미 있으면 새로 만들지 않고 그 카드를 돌려준다(중복 방지).
     *
     * @return array<string, mixed>
     */
    /**
     * "오늘 작업 등록" 피커용 — 프로젝트의 실행 단위(SubTask) 목록 + 그날 카드 유무.
     * 안전관리에서 이 목록을 보고 오늘 할 작업을 골라 안전카드를 만든다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pickList(string $projectCode, string $siteId = 'ALL', ?string $date = null): array
    {
        $date ??= now()->toDateString();

        $query = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->with(['safetyWorkItems' => fn ($q) => $q->where('work_date', $date)])
            ->orderByRaw('planned_start is null, planned_start')
            ->orderBy('node_no')
            ->orderBy('id');

        if ($siteId !== 'ALL') {
            $query->where('site_id', Site::query()->where('code', $siteId)->value('id'));
        }

        return $query->get()->map(fn (WbsItem $it): array => [
            'wbs_code' => $it->wbs_code,
            'activity_id' => $it->activity_id,
            'name' => $it->name,
            'trade' => $it->trade,
            'company' => $it->company,
            'crewText' => $it->crew_text,
            'crewSize' => $it->crew_size !== null ? (float) $it->crew_size : null,
            'equipment' => $it->equipment ?? [],
            'ehs' => $it->ehs,
            'plannedStart' => $it->planned_start?->toDateString(),
            'plannedEnd' => $it->planned_end?->toDateString(),
            'isCritical' => (bool) $it->is_critical,
            'status' => $it->status,
            'hasCardToday' => $it->safetyWorkItems->isNotEmpty(),
        ])->values()->all();
    }

    /**
     * 공정관리에 없는 작업을 현장에서 직접 등록 — "현장 추가(비계획)" Stage 아래 SubTask 로 삽입하고
     * 곧바로 그날 안전 작업카드를 생성한다. 계획 외 작업이 공정표·집계에서 누락되지 않게.
     *
     * @param  array<string, mixed>  $data  name(필수), trade, location, crew_text, equipment[], days, ehs, date
     * @return array<string, mixed>
     */
    public function addManualActivity(string $projectCode, array $data, string $siteId = 'ALL', ?int $userId = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => '작업명을 입력하세요.'];
        }

        $trade = trim((string) ($data['trade'] ?? '')) ?: '미지정';
        $date = trim((string) ($data['date'] ?? '')) ?: now()->toDateString();
        $siteRowId = $siteId !== 'ALL' ? Site::query()->where('code', $siteId)->value('id') : null;
        $projectRowId = Project::query()->where('project_code', $projectCode)->value('id');
        $crew = app(CrewParser::class)->parse((string) ($data['crew_text'] ?? ''));

        $wbsCode = DB::transaction(function () use ($projectCode, $projectRowId, $siteRowId, $trade, $name, $date, $data, $crew): string {
            $base = ['project_id' => $projectRowId, 'project_code' => $projectCode, 'site_id' => $siteRowId, 'source' => 'manual'];

            // "현장 추가(비계획)" Stage (프로젝트당 하나) → 공종별 Task → SubTask.
            $stage = WbsItem::query()->firstOrCreate(
                ['project_code' => $projectCode, 'level' => WbsItem::LEVEL_STAGE, 'node_no' => 'M'],
                $base + ['wbs_code' => "{$projectCode}-S-M", 'name' => '현장 추가(비계획)', 'sort_order' => 999]
            );

            $task = WbsItem::query()->firstOrCreate(
                ['project_code' => $projectCode, 'level' => WbsItem::LEVEL_TASK, 'parent_id' => $stage->id, 'name' => $trade],
                $base + ['wbs_code' => "{$projectCode}-T-M-{$trade}", 'node_no' => "M.{$trade}", 'trade' => $trade, 'sort_order' => 0]
            );

            $seq = WbsItem::query()->where('parent_id', $task->id)->count() + 1;
            $stamp = now()->format('ymdHis');
            // 표시용 activity_id 는 짧게(M01, M02…) — 길면 간트/목록 라벨칸을 넘쳐 작업명과 겹친다.
            // 프로젝트 전체 수동 작업 순번 기준. wbs_code 는 타임스탬프로 유일성 보장.
            $manualNo = WbsItem::query()->where('project_code', $projectCode)->where('source', 'manual')
                ->where('level', WbsItem::LEVEL_SUBTASK)->count() + 1;

            $sub = WbsItem::query()->create($base + [
                'parent_id' => $task->id,
                'level' => WbsItem::LEVEL_SUBTASK,
                'wbs_code' => "{$projectCode}-W-M-{$stamp}-{$seq}",
                'node_no' => "M.{$trade}.{$seq}",
                'activity_id' => 'M'.str_pad((string) $manualNo, 2, '0', STR_PAD_LEFT),
                'name' => $name,
                'trade' => $trade,
                'crew_text' => $crew['raw'] !== '' ? $crew['raw'] : null,
                'crew_size' => $crew['size'] ?: null,
                'crew_roles' => $crew['roles'] ?: null,
                'equipment' => is_array($data['equipment'] ?? null) && $data['equipment'] !== [] ? $data['equipment'] : ($crew['equipment'] ?: null),
                'days' => isset($data['days']) ? (int) $data['days'] : null,
                'ehs' => $data['ehs'] ?? null,
                'planned_start' => $date,
                'planned_end' => $date,
                'status' => '검수완료',
                'progress' => 0,
                'sort_order' => $seq,
                'payload' => ['manual' => true, 'location' => $data['location'] ?? null],
            ]);

            return $sub->wbs_code;
        });

        // 곧바로 그날 안전 작업카드 생성(계획 인원 → 서명란).
        $card = $this->createSafetyCard($wbsCode, $date, $userId);

        return ['success' => true, 'wbs_code' => $wbsCode, 'card' => $card];
    }

    /**
     * 기준 행 바로 다음에 같은 레벨의 행을 끼워 넣는다.
     *
     * addManualActivity() 는 "현장 추가(비계획)" 라는 별도 구간의 맨 끝에만 붙는다. 계획된
     * 공정 흐름 중간에 빠진 작업을 넣을 수가 없어서, 실제로는 엑셀을 다시 만들어 통째로
     * 갈아끼우는 수밖에 없었다.
     *
     * 표시 순서는 sort_order 로만 옮기고 node_no(1.2.3 같은 번호)는 건드리지 않는다.
     * 번호를 다시 매기면 도면·서류·안전카드에 이미 적힌 번호와 어긋난다. 대신 새 행에는
     * 기준 행 번호 뒤에 a, b, c 를 붙인다 — 1.2.3 과 1.2.4 사이는 1.2.3a 가 된다.
     * "순서"와 "이름"은 다른 것이고, 바뀌면 안 되는 쪽은 이름이다.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function insertAfter(string $afterWbsCode, array $data, ?int $userId = null): array
    {
        $anchor = WbsItem::query()->where('wbs_code', $afterWbsCode)->first();
        if (! $anchor) {
            return ['success' => false, 'error' => '기준이 될 행을 찾지 못했습니다.'];
        }

        if (! $anchor->parent_id) {
            return ['success' => false, 'error' => '최상위 단계 사이에는 넣을 수 없습니다. 하위 작업을 기준으로 선택하세요.'];
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => '작업명을 입력하세요.'];
        }

        $crew = app(CrewParser::class)->parse((string) ($data['crew_text'] ?? ''));

        $created = DB::transaction(function () use ($anchor, $data, $name, $crew): WbsItem {
            // 기준 행 뒤의 형제들을 한 칸씩 민다. 같은 sort_order 가 겹치면 순서가 들쭉날쭉해진다.
            WbsItem::query()
                ->where('parent_id', $anchor->parent_id)
                ->where('sort_order', '>', $anchor->sort_order)
                ->increment('sort_order');

            return WbsItem::query()->create([
                'project_id' => $anchor->project_id,
                'project_code' => $anchor->project_code,
                'site_id' => $anchor->site_id,
                'parent_id' => $anchor->parent_id,
                'level' => $anchor->level,
                'wbs_code' => $anchor->project_code.'-I-'.now()->format('ymdHis').'-'.random_int(100, 999),
                'node_no' => $this->nextInsertedNodeNo($anchor),
                'name' => $name,
                'trade' => trim((string) ($data['trade'] ?? '')) ?: $anchor->trade,
                'company' => $data['company'] ?? $anchor->company,
                'crew_text' => $crew['raw'] !== '' ? $crew['raw'] : null,
                'crew_size' => $crew['size'] ?: null,
                'crew_roles' => $crew['roles'] ?: null,
                'equipment' => $crew['equipment'] ?: null,
                'days' => isset($data['days']) ? (int) $data['days'] : null,
                'manhours' => isset($data['manhours']) ? (float) $data['manhours'] : null,
                'ehs' => $data['ehs'] ?? null,
                // 날짜를 안 주면 기준 행의 종료일에 붙인다 — 사이에 끼운 작업은 보통 그 다음에 한다.
                'planned_start' => $data['planned_start'] ?? $anchor->planned_end ?? $anchor->planned_start,
                'planned_end' => $data['planned_end'] ?? $anchor->planned_end ?? $anchor->planned_start,
                'status' => '검수완료',
                'progress' => 0,
                'sort_order' => $anchor->sort_order + 1,
                'source' => 'manual',
                'payload' => ['inserted_after' => $anchor->wbs_code],
            ]);
        });

        // 새 행이 끼어들었다 — 여유·주공정을 최신으로 맞춘다(선행이 없으면 엔진이 알아서 생략).
        $cpm = $this->recomputeCpm($created);

        return ['success' => true, 'wbs_code' => $created->wbs_code, 'node_no' => $created->node_no, 'cpm' => $cpm];
    }

    /**
     * 1.2.3 다음에 넣으면 1.2.3a, 또 넣으면 1.2.3b. 기존 번호는 그대로 둔다.
     */
    private function nextInsertedNodeNo(WbsItem $anchor): ?string
    {
        $base = (string) ($anchor->node_no ?? '');
        if ($base === '') {
            return null;
        }

        // 이미 1.2.3a 뒤에 넣는 경우에도 뿌리 번호(1.2.3)를 기준으로 다음 글자를 찾는다.
        $root = rtrim($base, 'abcdefghijklmnopqrstuvwxyz');
        $taken = WbsItem::query()
            ->where('parent_id', $anchor->parent_id)
            ->where('node_no', 'like', $root.'%')
            ->pluck('node_no')->all();

        foreach (range('a', 'z') as $suffix) {
            if (! in_array($root.$suffix, $taken, true)) {
                return $root.$suffix;
            }
        }

        return $root.'+';
    }

    public function createSafetyCard(string $wbsCode, ?string $date = null, ?int $userId = null): array
    {
        $date ??= now()->toDateString();

        $item = WbsItem::query()->where('wbs_code', $wbsCode)->with('safetyWorkItems.signatures')->first();
        if (! $item) {
            return ['success' => false, 'error' => "WBS 항목을 찾을 수 없습니다: {$wbsCode}"];
        }
        if ($item->level !== WbsItem::LEVEL_SUBTASK) {
            return ['success' => false, 'error' => '안전 작업카드는 SubTask(실행 단위)에만 만들 수 있습니다.'];
        }

        if ($existing = $item->safetyCardFor($date)) {
            return ['success' => true, 'work_code' => $existing->work_code, 'created' => false, 'date' => $date];
        }

        $card = DB::transaction(function () use ($item, $date, $userId): SafetyWorkItem {
            $card = SafetyWorkItem::query()->create([
                'work_code' => $this->nextWorkCode($date),
                'wbs_code' => $item->wbs_code,
                'work_date' => $date,
                'site_id' => $item->site_id,
                'project' => $item->project_code,
                'title' => $item->name,
                'crew' => (int) ceil((float) $item->crew_size),
                'due_label' => $date,
                'plan_status' => '미생성',
                'tbm_status' => '대기',
                'close_status' => '시작전',
                'progress_status' => '미분석',
                // 계획된 인원·장비를 안전계획 입력값으로 넘긴다 — 이 둘이 안전계획을 결정한다.
                'work_text' => $this->planSeedText($item),
                'plan_payload' => [
                    'from_wbs' => $item->wbs_code,
                    'activity_id' => $item->activity_id,
                    'trade' => $item->trade,
                    'crew_text' => $item->crew_text,
                    'crew_roles' => $item->crew_roles,
                    'equipment' => $item->equipment,
                    'ehs' => $item->ehs,
                ],
                'created_by_id' => $userId,
            ]);

            // 계획 인원만큼 서명란을 미리 깔아둔다 — TBM 때 이름만 채우면 되게.
            $order = 0;
            foreach ((array) $item->crew_roles as $role) {
                if (($role['external'] ?? false) || ($role['count'] ?? null) === null) {
                    continue;
                }
                for ($i = 0; $i < (int) ceil((float) $role['count']); $i++) {
                    $card->signatures()->create([
                        'name' => '', 'role' => (string) ($role['role'] ?? ''), 'signed' => false, 'sort_order' => $order++,
                    ]);
                }
            }

            return $card;
        });

        return ['success' => true, 'work_code' => $card->work_code, 'created' => true, 'date' => $date];
    }

    /**
     * 안전계획 입력값 초안 — "인원 몇 명 · 어떤 장비"가 안전계획을 결정한다는 원칙을 문장으로 옮긴다.
     */
    private function planSeedText(WbsItem $item): string
    {
        $parts = [$item->name.'.'];

        if ($item->crew_text) {
            $parts[] = '투입: '.$item->crew_text.($item->crew_size ? " (약 {$item->crew_size}명)" : '').'.';
        }
        if (! empty($item->equipment)) {
            $parts[] = '장비: '.implode(', ', (array) $item->equipment).'.';
        }
        if ($item->days) {
            $parts[] = '공기 '.$item->days.'일.';
        }

        return implode(' ', $parts);
    }

    private function nextWorkCode(string $date): string
    {
        $prefix = 'WRK-'.str_replace('-', '', mb_substr($date, 2, 8));

        do {
            $code = $prefix.'-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
        } while (SafetyWorkItem::query()->where('work_code', $code)->exists());

        return $code;
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
        $projectRowId = Project::query()->where('project_code', $projectCode)->value('id');

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
                            // AI 는 공종(trade)만 분류한다. 협력사(company)는 사람이 실제 계약사에서 배정하므로
                            // 재생성 시 기존 배정을 보존하고, 새 항목은 비워둔다(미배정).
                            'trade' => $sub['trade'] ?? ($sub['company'] ?? null),
                            'company' => $keep->company ?? null,
                            'manhours' => isset($sub['manhours']) ? (float) $sub['manhours'] : null,
                            'days' => isset($sub['days']) ? (int) $sub['days'] : null,
                            'ehs' => $sub['ehs'] ?? null,
                            // 보존: 기존 현장 진행 상태/진척/안전카드 링크가 있으면 유지.
                            'status' => $keep->status ?? '검수완료',
                            'progress' => $keep->progress ?? 0,
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
     * TBM 게이트 — "안전 없이 공정 없다".
     *
     * 안전은 하루 단위 행위이므로 "그날의 안전카드"를 본다. 날짜는 서버 시간대(현장 시간대)로만 정한다 —
     * 클라이언트가 계산한 날짜를 믿으면 시간대가 어긋나 게이트가 통째로 무력화된다.
     *
     *  - 현장 인원이 투입되는 작업(crew_size > 0)인데 그날 카드가 없다 → 막는다. 안전계획이 먼저다.
     *  - 그날 카드가 있고 TBM 미완료 → 막는다.
     *  - 인원 투입이 없는 작업(조달/발주 등, crew "-") → 게이트 없음. TBM 이 성립하지 않는다.
     */
    private function tbmGate(WbsItem $item, string $targetStatus, ?string $date = null): ?string
    {
        if ($item->level !== WbsItem::LEVEL_SUBTASK) {
            return null;
        }

        if (! in_array($targetStatus, [WbsItem::STATUS_IN_PROGRESS, WbsItem::STATUS_DONE], true)) {
            return null;
        }

        // 현장 인원이 없는 작업(조달·리드타임 등)은 안전계획 대상이 아니다.
        if ((float) $item->crew_size <= 0) {
            return null;
        }

        $date ??= now()->toDateString();

        if (! $item->relationLoaded('safetyWorkItems')) {
            $item->load('safetyWorkItems.signatures');
        }

        $card = $item->safetyCardFor($date);

        if (! $card) {
            return "안전계획 미수립 — {$date} 안전 작업카드를 먼저 만들고 TBM 을 완료해야 공정을 진행할 수 있습니다.";
        }

        if ($card->isTbmCleared()) {
            return null;
        }

        return "TBM 미완료 — {$date} 안전 작업({$card->work_code})의 TBM/서명이 완료되어야 공정을 진행할 수 있습니다.";
    }

    /**
     * 같은 프로젝트의 WBS 노드 + 안전카드 링크를 한 번에 로드 (scope 적용).
     */
    private function itemsFor(string $projectCode, string $siteId): Collection
    {
        $query = WbsItem::query()
            ->where('project_code', $projectCode)
            ->with('safetyWorkItems.signatures')
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
     * 가중 진척률(%) — 공수(MH) → 공기(일) → 균등 순으로 무게를 잡는다.
     *
     * 공정표가 공수를 주는 일은 드물다. 실제로 현장에서 올라온 간트에는 투입조 칸이 없어
     * 81개 작업 전부 공수가 비어 있었고, 그러면 예전 코드는 단순 평균으로 떨어졌다.
     * 단순 평균에서는 17일짜리 배관공사 한 건과 발주서 한 장이 같은 1/81 이 된다.
     *
     * 그래서 공수가 없으면 공기(일)를 무게로 쓴다. 이 한 줄이 두 가지를 동시에 고친다:
     *
     *   1) 큰 작업이 큰 몫을 갖는다 — 17일 공사를 끝내면 1% 가 아니라 4% 가 오른다
     *   2) 조달 항목이 공정률을 부풀리지 않는다 — 발주 행은 공기가 없어 무게 0 이라
     *      계산에서 저절로 빠진다. 별도 플래그도, 스키마 변경도 필요 없다
     *
     * 실측: 조달 23건(공기합 0일) · 공사 58건(공기합 379일) 인 실제 공정표에서
     * 조달만 전부 발주완료했을 때 예전 28% → 지금 0%, 공사를 전부 끝냈을 때 예전 72% → 지금 100%.
     *
     * 공수도 공기도 없으면 그때는 균등(단순 평균)으로 간다.
     */
    private function weightedProgress(Collection $subtasks): int
    {
        if ($subtasks->isEmpty()) {
            return 0;
        }

        $weight = fn (WbsItem $s) => max((float) ($s->manhours ?: $s->days), 0);

        $totalWeight = $subtasks->sum($weight);

        if ($totalWeight <= 0) {
            return (int) round($subtasks->avg(fn (WbsItem $s) => $s->effectiveProgress()));
        }

        $weighted = $subtasks->sum(fn (WbsItem $s) => $weight($s) * $s->effectiveProgress());

        return (int) round($weighted / $totalWeight);
    }

    /**
     * 계획원가 vs 원장 실적. 실적은 경비 원장(mobile_expenses)의 project_id 귀속분 —
     * 자재(조달 커넥터)·노무(급여 커넥터)·기타가 전부 이 한 칸으로 모인다.
     *
     * @return array{planned: float, actual: float, pending: float}|null
     */
    private function costSummary(string $projectCode, Collection $subtasks): ?array
    {
        try {
            $planned = round((float) $subtasks->sum(fn (WbsItem $i) => (float) $i->planned_cost), 2);
            $projectId = Project::query()->where('project_code', $projectCode)->value('id');
            if ($projectId === null) {
                return $planned > 0 ? ['planned' => $planned, 'actual' => 0.0, 'pending' => 0.0] : null;
            }

            $byStatus = \App\Models\MobileExpense::query()
                ->where('project_id', $projectId)
                ->whereIn('status', ['pending', 'approved', 'paid'])
                ->selectRaw("case when status = 'pending' then 'pending' else 'actual' end as bucket, sum(amount) as total")
                ->groupBy('bucket')
                ->pluck('total', 'bucket');

            $actual = round((float) ($byStatus['actual'] ?? 0), 2);
            $pending = round((float) ($byStatus['pending'] ?? 0), 2);

            return ($planned > 0 || $actual > 0 || $pending > 0)
                ? ['planned' => $planned, 'actual' => $actual, 'pending' => $pending]
                : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * 프로젝트 CPM 재계산. 실패해도 편집 자체를 막지 않는다 — 다음 편집 때 다시 돈다.
     *
     * @return array<string, mixed>|null
     */
    private function recomputeCpm(WbsItem $item): ?array
    {
        try {
            return app(CpmEngine::class)->recompute((string) $item->project_code);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
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
