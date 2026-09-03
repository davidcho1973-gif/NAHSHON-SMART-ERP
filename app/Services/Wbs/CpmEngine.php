<?php

namespace App\Services\Wbs;

use App\Models\WbsItem;
use App\Support\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 사내 CPM 엔진 — 선행관계(preds)로부터 여유(float)·주공정(critical)·예상 준공을 자체 재계산한다.
 *
 * 지금까지 이 값들은 외부 엑셀이 계산한 것을 "복사"만 했다. 그래서 현장에서 날짜 하나를
 * 고치면 여유·주공정이 낡은 채로 남고, 공정표를 바꾸려면 엑셀을 다시 만들어 통째로
 * 갈아끼워야 했다. 이 엔진이 그 의존을 끊는다: 일정이 바뀌면 후속 공정과 준공일이
 * 즉시 다시 계산된다.
 *
 * 규칙 (실제 현장 운영에 맞춘 세 가지 결정):
 *
 *  1. **지연만 전파하고, 버퍼가 먼저 흡수한다.** 후속 작업의 시작은
 *     max(기준선 시작일, 선행 종료 + 최소 간격) 이다. 기준선(처음 저장된 날짜)은
 *     payload 에 한 번 포착해 둔다 — 선행이 밀리면 그 사이의 버퍼(주말·여유)가 먼저
 *     지연을 흡수하고, 버퍼를 다 먹은 만큼만 후속이 밀린다. 선행이 제자리로 돌아오면
 *     후속도 기준선으로 돌아온다(한 번 밀리면 못 돌아오는 톱니 없음). 최소 간격은
 *     보통 1일(선행 종료 다음 날)이지만, 시트가 같은 날 이어받기·겹침으로 계획했으면
 *     그 간격(0 이하)을 그대로 보존한다 — 시트에 적힌 것이 논리다.
 *
 *  2. **완료·진행중 작업의 날짜는 실적이다.** 엔진이 옮기지 않는다. 다만 그 작업의
 *     끝에서 후속이 흘러나오고, 여유·주공정 표시는 함께 다시 계산된다.
 *
 *  3. **선행관계가 하나도 없으면 계산하지 않는다.** 관계 없이 돌리면 모든 작업이
 *     병렬이 되어 여유가 무한대, 주공정이 0개가 된다 — 가짜 계산은 안 하느니만 못하다.
 *     이때는 가져온 값(엑셀의 여유·CP)을 그대로 둔다.
 */
class CpmEngine
{
    /** 날짜를 엔진이 옮기지 않는 상태 — 시작했거나 끝난 작업은 실적이다. */
    private const FIXED_STATUSES = [WbsItem::STATUS_IN_PROGRESS, WbsItem::STATUS_DONE];

    /** 기준 간격이 없을 때(새 행·날짜 미상)의 기본값: 선행 종료 다음 날 시작. */
    private const DEFAULT_GAP = 1;

    /** 기준 간격 허용 범위 — 시트 오타(연도가 다른 날짜 등)로 생긴 괴물 간격을 걸러낸다. */
    private const GAP_MIN = -120;

    private const GAP_MAX = 365;

    /**
     * 프로젝트 전체 재계산. 반환값으로 무엇이 얼마나 움직였는지 알려준다.
     *
     * @return array{success: bool, skipped: bool, reason?: string, projectedEnd?: ?string,
     *               movedCount?: int, moved?: array<int, array{id: string, from: ?string, to: string}>,
     *               criticalCount?: int, warnings?: array<int, string>}
     */
    public function recompute(string $projectCode): array
    {
        $items = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return ['success' => true, 'skipped' => true, 'reason' => '작업이 없습니다.'];
        }

        // 노드 키는 activity_id (없으면 wbs_code) — preds 가 액티비티 ID 를 가리킨다.
        $nodes = $items->keyBy(fn (WbsItem $i): string => $this->keyOf($i));

        [$edges, $warnings] = $this->buildEdges($nodes);

        if ($edges === []) {
            return [
                'success' => true, 'skipped' => true,
                'reason' => '선행관계가 없어 재계산하지 않습니다. 가져온 여유·주공정 값을 그대로 둡니다.',
            ];
        }

        [$order, $cyclic] = $this->topologicalOrder($nodes, $edges);
        if ($cyclic !== []) {
            $warnings[] = '선행관계가 서로 물려 있어 제외: '.implode(', ', array_slice($cyclic, 0, 5))
                .(count($cyclic) > 5 ? ' 외 '.(count($cyclic) - 5).'건' : '');
            // 순환에 낀 노드와 그 간선은 계산에서 뺀다 — 나머지는 정상 계산한다.
            $edges = array_filter($edges, fn (array $e): bool => ! in_array($e[0], $cyclic, true) && ! in_array($e[1], $cyclic, true));
        }

        // 기준선(시작일·선행 간격): 아직 포착된 적 없는 노드는 지금 저장된 날짜(=시트/사람이 정한 원본)로 포착.
        $baselines = $this->captureBaselines($nodes, $edges);

        $result = $this->computePasses($nodes, $edges, $order, $baselines);

        $writeStats = $this->writeBack($nodes, $order, $baselines, $result);

        return [
            'success' => true,
            'skipped' => false,
            'projectedEnd' => $result['projectEnd']?->toDateString(),
            'movedCount' => count($writeStats['moved']),
            'moved' => array_slice($writeStats['moved'], 0, 10),
            'criticalCount' => $writeStats['criticalCount'],
            'warnings' => $warnings,
        ];
    }

    /**
     * What-if 시뮬레이션 — "이 작업이 N일 밀리면 무엇이 밀리는가". 아무것도 저장하지 않는다.
     *
     * 같은 규칙(기준선·최소 간격·고정 상태)으로 지금 상태와 지연 주입 상태를 각각
     * 계산해 차이만 돌려준다. 지연은 "종료가 N일 늦어짐"으로 넣는다 — 현장의 지연은
     * 대부분 공기가 늘어나는 형태다.
     *
     * @return array{success: bool, error?: string, activity?: string, name?: string,
     *               delayDays?: int, projectedEndBefore?: ?string, projectedEndAfter?: ?string,
     *               projectDelayDays?: int, moved?: array<int, array{id: string, name: string, from: ?string, to: ?string}>}
     */
    public function simulate(string $projectCode, string $activityKey, int $delayDays): array
    {
        if ($delayDays === 0) {
            return ['success' => false, 'error' => '지연 일수가 0입니다.'];
        }

        $items = WbsItem::query()
            ->where('project_code', $projectCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        $nodes = $items->keyBy(fn (WbsItem $i): string => $this->keyOf($i));

        $key = $this->resolveKey($nodes, $activityKey);
        if ($key === null) {
            return ['success' => false, 'error' => "공정표에서 '{$activityKey}' 작업을 특정하지 못했습니다."];
        }

        [$edges] = $this->buildEdges($nodes);
        if ($edges === []) {
            return ['success' => false, 'error' => '선행관계가 없어 지연 전파를 계산할 수 없습니다.'];
        }

        [$order, $cyclic] = $this->topologicalOrder($nodes, $edges);
        $edges = array_filter($edges, fn (array $e): bool => ! in_array($e[0], $cyclic, true) && ! in_array($e[1], $cyclic, true));
        $baselines = $this->captureBaselines($nodes, $edges);

        $before = $this->computePasses($nodes, $edges, $order, $baselines);

        // 지연 주입(메모리에서만) — 종료 +N. 날짜가 아예 없으면 시작일 기준으로 만든다.
        $target = $nodes[$key];
        $end = $target->planned_end ?? $target->planned_start;
        if ($end === null) {
            return ['success' => false, 'error' => "'{$target->name}' 에 계획 날짜가 없어 시뮬레이션할 수 없습니다."];
        }
        $target->planned_end = $end->toImmutable()->addDays($delayDays)->toDateString();

        $after = $this->computePasses($nodes, $edges, $order, $baselines);

        $moved = [];
        foreach ($order as $k) {
            if ($k === $key) {
                continue; // 지연시킨 작업 자신은 당연히 밀린다 — 후속만 나열한다.
            }
            $fromEf = $before['ef'][$k] ?? null;
            $toEf = $after['ef'][$k] ?? null;
            if ($fromEf !== null && $toEf !== null && ! $fromEf->equalTo($toEf)) {
                $moved[] = [
                    'id' => $k,
                    'name' => (string) $nodes[$k]->name,
                    'from' => $fromEf->toDateString(),
                    'to' => $toEf->toDateString(),
                ];
            }
        }

        $beforeEnd = $before['projectEnd'];
        $afterEnd = $after['projectEnd'];

        return [
            'success' => true,
            'activity' => $key,
            'name' => (string) $target->name,
            'wbsCode' => (string) $target->wbs_code,
            'delayDays' => $delayDays,
            'projectedEndBefore' => $beforeEnd?->toDateString(),
            'projectedEndAfter' => $afterEnd?->toDateString(),
            'projectDelayDays' => ($beforeEnd && $afterEnd) ? (int) $beforeEnd->diffInDays($afterEnd) : 0,
            'moved' => array_slice($moved, 0, 15),
            'movedCount' => count($moved),
        ];
    }

    /**
     * 액티비티 특정 — ID 정확 일치 → 이름 정확 일치 → 이름 부분 일치(유일할 때만).
     *
     * @param  Collection<string, WbsItem>  $nodes
     */
    private function resolveKey(Collection $nodes, string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($nodes->has($raw)) {
            return $raw;
        }

        $upper = mb_strtoupper($raw);
        foreach ($nodes as $key => $item) {
            if (mb_strtoupper((string) $item->activity_id) === $upper) {
                return (string) $key;
            }
        }

        $exact = $nodes->filter(fn (WbsItem $i): bool => trim((string) $i->name) === $raw);
        if ($exact->count() === 1) {
            return (string) $exact->keys()->first();
        }

        $partial = $nodes->filter(fn (WbsItem $i): bool => mb_stripos((string) $i->name, $raw) !== false);

        return $partial->count() === 1 ? (string) $partial->keys()->first() : null;
    }

    private function keyOf(WbsItem $i): string
    {
        return (string) ($i->activity_id ?: $i->wbs_code);
    }

    /**
     * preds 를 간선 목록 [predKey, nodeKey] 으로 푼다. 없는 선행은 경고하고 건너뛴다.
     *
     * @param  Collection<string, WbsItem>  $nodes
     * @return array{0: array<int, array{0: string, 1: string}>, 1: array<int, string>}
     */
    private function buildEdges(Collection $nodes): array
    {
        $edges = [];
        $missing = [];

        foreach ($nodes as $key => $item) {
            $key = (string) $key; // 숫자형 ID 는 PHP 배열 키에서 int 로 바뀐다 — 비교는 전부 문자열로.
            foreach ((array) $item->preds as $pred) {
                $pred = trim((string) $pred);
                // "A010FS+3d" 같은 표기에서 ID 부분만 취한다(관계 유형·랙 표기는 시트마다 제각각).
                $predId = preg_replace('/(FS|SS|FF|SF).*$/i', '', $pred) ?? $pred;
                $predId = trim($predId);
                if ($predId === '' || $predId === $key) {
                    continue;
                }
                if (! $nodes->has($predId)) {
                    $missing[$predId] = true;

                    continue;
                }
                $edges[] = [$predId, $key];
            }
        }

        $warnings = [];
        if ($missing !== []) {
            $ids = array_keys($missing);
            $warnings[] = '공정표에 없는 선행 ID 무시: '.implode(', ', array_slice($ids, 0, 5))
                .(count($ids) > 5 ? ' 외 '.(count($ids) - 5).'건' : '');
        }

        return [$edges, $warnings];
    }

    /**
     * Kahn 위상 정렬. 순환에 낀 노드 키 목록을 함께 돌려준다.
     *
     * @param  Collection<string, WbsItem>  $nodes
     * @param  array<int, array{0: string, 1: string}>  $edges
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function topologicalOrder(Collection $nodes, array $edges): array
    {
        $indegree = array_fill_keys($nodes->keys()->all(), 0);
        $out = [];
        foreach ($edges as [$from, $to]) {
            $indegree[$to]++;
            $out[$from][] = $to;
        }

        // 입력 순서(sort_order)를 큐 순서로 유지 — 같은 층위에서는 공정표 순서대로.
        $queue = array_keys(array_filter($indegree, fn (int $d): bool => $d === 0));
        $order = [];
        while ($queue !== []) {
            $key = array_shift($queue);
            $order[] = $key;
            foreach ($out[$key] ?? [] as $to) {
                if (--$indegree[$to] === 0) {
                    $queue[] = $to;
                }
            }
        }

        $cyclic = array_map('strval', array_values(array_diff($nodes->keys()->all(), $order)));

        return [array_map('strval', array_values(array_diff($order, $cyclic))), $cyclic];
    }

    /**
     * 노드별 기준선(시작일 + 선행별 간격)을 payload 에서 읽거나 처음 한 번 포착한다.
     *
     * 포착은 "지금 저장된 날짜" 기준이다 — 이 함수는 엔진이 날짜를 고치기 **전에** 불리므로,
     * 그 시점의 날짜는 시트가 준 원본이거나 사람이 마지막으로 정한 값이다. 사람이 이 행의
     * 날짜를 직접 고치면 WbsService 가 포착값을 지워서 새 날짜로 다시 포착된다.
     *
     * 기준선 시작일이 있어야 "선행이 제자리로 오면 후속도 돌아온다"가 성립한다 — 저장된
     * 날짜는 엔진이 밀어 놓은 값일 수 있어 닻으로 못 쓴다.
     *
     * @param  Collection<string, WbsItem>  $nodes
     * @param  array<int, array{0: string, 1: string}>  $edges
     * @return array<string, array{start: ?string, gaps: array<string, int>}>
     */
    private function captureBaselines(Collection $nodes, array $edges): array
    {
        $incoming = [];
        foreach ($edges as [$from, $to]) {
            $incoming[$to][] = $from;
        }

        $baselines = [];
        foreach ($nodes as $key => $node) {
            $key = (string) $key;
            $stored = data_get($node->payload, 'cpm_baseline');

            if (is_array($stored) && array_key_exists('start', $stored)) {
                $baselines[$key] = [
                    'start' => is_string($stored['start']) ? $stored['start'] : null,
                    'gaps' => array_map('intval', array_filter((array) ($stored['gaps'] ?? []), 'is_numeric')),
                ];

                continue;
            }

            $gaps = [];
            foreach ($incoming[$key] ?? [] as $pred) {
                $predEnd = $nodes[$pred]->planned_end;
                $start = $node->planned_start;
                $gap = ($predEnd !== null && $start !== null)
                    ? (int) $predEnd->toImmutable()->startOfDay()->diffInDays($start->toImmutable()->startOfDay())
                    : self::DEFAULT_GAP;
                $gaps[$pred] = max(self::GAP_MIN, min(self::GAP_MAX, $gap));
            }

            $baselines[$key] = [
                'start' => $node->planned_start?->toDateString(),
                'gaps' => $gaps,
            ];
        }

        return $baselines;
    }

    /**
     * 간선의 최소 간격 — 보통 1일(다음 날 시작). 시트가 같은 날 이어받기·겹침(0 이하)으로
     * 계획했으면 그대로 보존한다. 1일을 넘는 간격은 필수가 아니라 버퍼(여유)로 본다.
     */
    private function minGap(array $baselines, string $to, string $from): int
    {
        return min(self::DEFAULT_GAP, $baselines[$to]['gaps'][$from] ?? self::DEFAULT_GAP);
    }

    /**
     * 전진(ES/EF) → 후진(LS/LF) 계산.
     *
     * @param  Collection<string, WbsItem>  $nodes
     * @param  array<int, array{0: string, 1: string}>  $edges
     * @param  array<int, string>  $order
     * @param  array<string, array{start: ?string, gaps: array<string, int>}>  $baselines
     * @return array{es: array<string, CarbonImmutable>, ef: array<string, CarbonImmutable>,
     *               ls: array<string, CarbonImmutable>, lf: array<string, CarbonImmutable>,
     *               projectEnd: ?CarbonImmutable}
     */
    private function computePasses(Collection $nodes, array $edges, array $order, array $baselines): array
    {
        $in = [];
        $outEdges = [];
        foreach ($edges as [$from, $to]) {
            $in[$to][] = $from;
            $outEdges[$from][] = $to;
        }

        // 시작 기준일: 프로젝트에서 가장 이른 계획 시작(아무 날짜도 없으면 오늘).
        $anchor = $nodes->map(fn (WbsItem $i) => $i->planned_start?->toImmutable()->startOfDay())
            ->filter()->sortBy(fn (CarbonImmutable $d) => $d->getTimestamp())->first()
            ?? CarbonImmutable::today();

        $es = [];
        $ef = [];
        $cal = $this->calendar();

        foreach ($order as $key) {
            $node = $nodes[$key];
            $span = $this->spanOf($node);
            $ownStart = $node->planned_start?->toImmutable()->startOfDay();
            $elapsed = $this->isElapsed($node);

            if ($this->isFixed($node) && $ownStart !== null) {
                // 실적 — 저장된 날짜 그대로.
                $es[$key] = $ownStart;
                $ef[$key] = $node->planned_end?->toImmutable()->startOfDay() ?? $ownStart->addDays($span);
            } elseif (($in[$key] ?? []) === []) {
                // 뿌리 — 자기 계획 시작에 닻을 내린다(휴일이면 다음 근무일).
                $es[$key] = $elapsed ? ($ownStart ?? $anchor) : $cal->nextWorkday($ownStart ?? $anchor);
                $ef[$key] = $elapsed ? $es[$key]->addDays($span) : $cal->addWorkdays($es[$key], $span);
            } else {
                // 후속 — max(기준선 시작일, 선행 종료 + 최소 간격). 버퍼가 지연을 흡수한다.
                $base = $baselines[$key]['start'] ?? null;
                $start = $base !== null ? CarbonImmutable::parse($base)->startOfDay() : null;
                foreach ($in[$key] as $pred) {
                    $candidate = $ef[$pred]->addDays($this->minGap($baselines, $key, $pred));
                    if ($start === null || $candidate->greaterThan($start)) {
                        $start = $candidate;
                    }
                }
                // 근무일 활동은 휴일에 시작하지 않고 휴일을 건너뛰어 끝난다.
                // 양생·대기(경과 시간)는 휴일에도 흐른다 — 콘크리트는 추수감사절에도 굳는다.
                $es[$key] = $elapsed ? $start : $cal->nextWorkday($start);
                $ef[$key] = $elapsed ? $es[$key]->addDays($span) : $cal->addWorkdays($es[$key], $span);
            }
        }

        $projectEnd = null;
        foreach ($ef as $d) {
            if ($projectEnd === null || $d->greaterThan($projectEnd)) {
                $projectEnd = $d;
            }
        }

        // 후진: 준공일에서 거꾸로. 후속이 없는 노드의 LF = 준공일.
        $ls = [];
        $lf = [];
        foreach (array_reverse($order) as $key) {
            $limit = $projectEnd;
            foreach ($outEdges[$key] ?? [] as $succ) {
                $candidate = $ls[$succ]->subDays($this->minGap($baselines, $succ, $key));
                if ($candidate->lessThan($limit)) {
                    $limit = $candidate;
                }
            }
            $elapsed = $this->isElapsed($nodes[$key]);
            // 근무일 활동의 가장 늦은 종료는 휴일이 아니어야 하고, 폭도 근무일로 거슬러 센다.
            $lf[$key] = $elapsed ? $limit : $cal->prevWorkday($limit);
            $ls[$key] = $elapsed
                ? $lf[$key]->subDays($this->spanOf($nodes[$key]))
                : $cal->subWorkdays($lf[$key], $this->spanOf($nodes[$key]));
        }

        return ['es' => $es, 'ef' => $ef, 'ls' => $ls, 'lf' => $lf, 'projectEnd' => $projectEnd];
    }

    /**
     * 작업 폭(시작~종료 일수 차). 날짜가 둘 다 있으면 그 폭이 정본이다 — 공기(days) 칸은
     * 근무일 기준이라 달력 폭과 다를 수 있고, 사람이 종료일을 직접 고친 경우도 지켜야 한다.
     */
    private function spanOf(WbsItem $node): int
    {
        $start = $node->planned_start?->toImmutable()->startOfDay();
        $end = $node->planned_end?->toImmutable()->startOfDay();

        if ($start !== null && $end !== null && ! $end->lessThan($start)) {
            if ($this->isElapsed($node)) {
                return (int) $start->diffInDays($end);
            }
            // 근무일 활동의 폭은 근무일로 센다. 달력일로 세면 휴일을 건너뛴 종료일을 다음
            // 재계산이 «폭이 늘었다» 로 읽어 한 번 돌 때마다 하루씩 늘어난다(톱니).
            // 그리고 공기(days) 칸을 바닥으로 둔다 — 7일 달력으로 잡혔던 옛 날짜가 휴일을
            // 끼고 있으면 근무일 폭이 공기보다 짧게 읽혀 작업이 조용히 하루씩 줄어든다.
            // 사람이 날짜를 늘려 잡은 것은 그대로 지킨다(둘 중 큰 쪽).
            $byDates = $this->calendar()->workdaySpan($start, $end);
            $byDays = max(0, (int) $node->days - 1);

            return (int) $node->days > 0 ? max($byDates, $byDays) : $byDates;
        }

        return max(0, ((int) $node->days ?: 1) - 1);
    }

    /** 양생·대기 — 휴일에도 흐르는 «경과 시간» 활동인가. payload.calendar === 'elapsed'. */
    private function isElapsed(WbsItem $node): bool
    {
        return data_get($node->payload, 'calendar') === 'elapsed';
    }

    private ?WorkCalendar $calendar = null;

    private function calendar(): WorkCalendar
    {
        return $this->calendar ??= new WorkCalendar();
    }

    private function isFixed(WbsItem $node): bool
    {
        return in_array($node->status, self::FIXED_STATUSES, true);
    }

    /**
     * 계산 결과를 컬럼에 반영. 바뀐 것만 저장하고, 날짜가 움직인 행을 목록으로 돌려준다.
     *
     * @param  Collection<string, WbsItem>  $nodes
     * @param  array<int, string>  $order
     * @param  array<string, array{start: ?string, gaps: array<string, int>}>  $baselines
     * @param  array{es: array<string, CarbonImmutable>, ef: array<string, CarbonImmutable>,
     *               ls: array<string, CarbonImmutable>, lf: array<string, CarbonImmutable>,
     *               projectEnd: ?CarbonImmutable}  $result
     * @return array{moved: array<int, array{id: string, from: ?string, to: string}>, criticalCount: int}
     */
    private function writeBack(Collection $nodes, array $order, array $baselines, array $result): array
    {
        $moved = [];
        $criticalCount = 0;

        DB::transaction(function () use ($nodes, $order, $baselines, $result, &$moved, &$criticalCount): void {
            foreach ($order as $key) {
                $node = $nodes[$key];
                $es = $result['es'][$key];
                $ef = $result['ef'][$key];
                $float = (int) $result['es'][$key]->diffInDays($result['ls'][$key], false);
                $critical = $float <= 0;
                if ($critical) {
                    $criticalCount++;
                }

                if (! $this->isFixed($node)) {
                    $before = $node->planned_start?->toDateString();
                    if ($before !== $es->toDateString()) {
                        $moved[] = ['id' => $key, 'from' => $before, 'to' => $es->toDateString()];
                    }
                    $node->planned_start = $es->toDateString();
                    $node->planned_end = $ef->toDateString();
                }

                $node->late_start = $result['ls'][$key]->toDateString();
                $node->late_end = $result['lf'][$key]->toDateString();
                $node->float_days = $float;
                $node->is_critical = $critical;

                // 포착한 기준선을 저장 — 다음 계산이 같은 논리를 쓴다.
                $baseline = $baselines[$key] ?? null;
                if ($baseline !== null && data_get($node->payload, 'cpm_baseline') != $baseline) {
                    $payload = (array) $node->payload;
                    $payload['cpm_baseline'] = $baseline;
                    $node->payload = $payload;
                }

                if ($node->isDirty()) {
                    $node->save();
                }
            }
        });

        return ['moved' => $moved, 'criticalCount' => $criticalCount];
    }
}
