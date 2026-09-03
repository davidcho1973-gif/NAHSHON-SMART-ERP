<?php

namespace App\Console\Commands;

use App\Models\Submittal;
use App\Models\WbsItem;
use App\Services\Wbs\CpmEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 공정표 구멍 메우기 — 원청 공정표와 대조해 드러난 «우리 표에 없는 일» 을 조작 목록(JSON)대로 넣는다.
 *
 * 왜 화면이 아니라 명령인가: 조작이 수십 건이고(활동 추가·기존 활동 쪼개기·후행 선행 재연결·
 * 검측 멈춤점·이정표) 서로 물려 있다. 화면에서 하나씩 넣으면 중간 상태에서 CPM 이 돌아
 * 엉뚱한 날짜가 저장되고, 순환·고아 선행을 사람이 눈으로 못 잡는다. 목록을 한 번에 검증하고
 * 한 트랜잭션으로 적용한 뒤 한 번만 재계산한다. 로컬과 클라우드에서 같은 목록으로 같은 결과가 난다.
 *
 * 규약:
 *  - add     : activity_id 가 이미 있으면 건너뛴다(멱등). wbs_code 는 {project}-W-{activity_id}.
 *  - modify  : 이름·기간·선행·멈춤점을 바꾼다. 같은 gap 번호로 이미 적용됐으면 건너뛴다.
 *  - hold    : 검측 멈춤점만 켠다(hold_point·hold_note·submittal_seqs).
 *  - repoint : 이 조작 때문에 선행을 옮겨야 하는 다른 활동들.
 *  - 적용 전에 순환·고아 선행을 검사하고, 하나라도 있으면 아무것도 바꾸지 않는다.
 *  - --dry-run 이면 트랜잭션 안에서 전부 적용·재계산·출력한 뒤 되돌린다.
 */
class FillWbsGaps703k extends Command
{
    protected $signature = 'erp:fill-wbs-gaps
                            {file? : 조작 목록 JSON (생략하면 database/data/703k/wbs_gap_fill.json)}
                            {--project=703K-KITCHEN : 프로젝트 코드}
                            {--dry-run : 적용해 보고 되돌린다 (결과만 본다)}
                            {--tag=gapfill : payload.gap_fix.tag — 같은 태그는 두 번 적용하지 않는다}';

    protected $description = '원청 대조에서 드러난 공정표 구멍을 조작 목록대로 WBS 에 넣고 CPM 을 재계산한다';

    /** @var array<string, WbsItem> activity_id → 항목 (기존 + 이번에 추가) */
    private array $byAid = [];

    public function handle(CpmEngine $engine): int
    {
        // 목록은 다른 703K 적재 자료와 같은 자리에 둔다 — 로컬에서 검증한 그 파일이 배포에 실려
        // 클라우드에서도 같은 명령으로 같은 결과를 낸다.
        $file = (string) ($this->argument('file') ?: database_path('data/703k/wbs_gap_fill.json'));
        if (! is_readable($file)) {
            $this->error("파일을 읽을 수 없습니다: {$file}");

            return self::FAILURE;
        }
        $spec = json_decode((string) file_get_contents($file), true);
        $ops = is_array($spec['ops'] ?? null) ? $spec['ops'] : [];
        if ($ops === []) {
            $this->error('조작 목록(ops)이 비어 있습니다.');

            return self::FAILURE;
        }

        $project = (string) $this->option('project');
        $tag = (string) $this->option('tag');
        $dry = (bool) $this->option('dry-run');

        $existing = WbsItem::query()->where('project_code', $project)->where('level', WbsItem::LEVEL_SUBTASK)->get();
        if ($existing->isEmpty()) {
            $this->error("프로젝트 {$project} 에 실행 활동이 없습니다.");

            return self::FAILURE;
        }
        foreach ($existing as $it) {
            $this->byAid[(string) $it->activity_id] = $it;
        }

        // ── 1. 적용 전 검증 — 순환·고아 선행이 하나라도 있으면 아무것도 바꾸지 않는다.
        $problems = $this->validate($ops);
        if ($problems !== []) {
            $this->error('적용하지 않았습니다 — 목록에 문제가 있습니다:');
            foreach ($problems as $p) {
                $this->line('  · '.$p);
            }

            return self::FAILURE;
        }

        $this->info(sprintf('%s · 조작 %d건 · 기존 활동 %d개%s', $project, count($ops), $existing->count(), $dry ? ' · [미리보기 — 되돌립니다]' : ''));

        // ── 2. 한 트랜잭션으로 적용 + 재계산. dry-run 은 예외로 되돌린다.
        $summary = ['added' => [], 'modified' => [], 'held' => [], 'skipped' => [], 'repointed' => []];
        $cpm = null;

        try {
            DB::transaction(function () use ($ops, $project, $tag, $dry, $engine, &$summary, &$cpm): void {
                foreach ($ops as $i => $op) {
                    $kind = (string) ($op['op'] ?? '');
                    $aid = strtoupper(trim((string) ($op['activity_id'] ?? '')));
                    match ($kind) {
                        'add' => $this->applyAdd($op, $aid, $project, $tag, $summary),
                        'modify' => $this->applyModify($op, $aid, $tag, $summary),
                        'hold' => $this->applyHold($op, $aid, $tag, $summary),
                        default => throw new RuntimeException("#{$i}: 알 수 없는 op «{$kind}»"),
                    };
                    foreach ((array) ($op['repoint'] ?? []) as $rp) {
                        $this->applyRepoint($rp, $tag, $summary);
                    }
                }

                $cpm = $engine->recompute($project);

                if ($dry) {
                    throw new DryRunRollback();
                }
            });
        } catch (DryRunRollback) {
            // 의도된 되돌림 — 결과는 아래에서 출력한다.
        }

        // ── 3. 결과
        $this->newLine();
        $this->line('추가 '.count($summary['added']).' · 수정 '.count($summary['modified']).' · 멈춤점 '.count($summary['held']).' · 선행 재연결 '.count($summary['repointed']).' · 건너뜀 '.count($summary['skipped']));
        foreach ($summary['added'] as $s) {
            $this->line('  + '.$s);
        }
        foreach ($summary['modified'] as $s) {
            $this->line('  ~ '.$s);
        }
        foreach ($summary['held'] as $s) {
            $this->line('  ⛔ '.$s);
        }
        foreach ($summary['repointed'] as $s) {
            $this->line('  ↪ '.$s);
        }
        foreach ($summary['skipped'] as $s) {
            $this->line('  · '.$s);
        }

        if (is_array($cpm)) {
            $this->newLine();
            $this->info(($cpm['skipped'] ?? false)
                ? 'CPM 생략 — '.($cpm['reason'] ?? '')
                : sprintf('CPM 재계산 — 이동 %d건 · 주공정 %d건 · 예상 준공 %s',
                    (int) ($cpm['movedCount'] ?? 0), (int) ($cpm['criticalCount'] ?? 0), (string) ($cpm['projectedEnd'] ?? '-')));
            foreach (($cpm['warnings'] ?? []) as $w) {
                $this->warn('  '.$w);
            }
        }

        if ($dry) {
            $this->newLine();
            $this->warn('미리보기였습니다 — 아무것도 저장하지 않았습니다. 실제 적용은 --dry-run 없이 다시 실행하세요.');
        }

        return self::SUCCESS;
    }

    /* ── 검증 ─────────────────────────────────────────────────────── */

    /**
     * 순환·고아 선행·코드 충돌을 적용 전에 잡는다. 엔진도 순환을 걸러 내긴 하지만
     * «걸러 낸 채 계산» 은 조용한 오류다 — 여기서 멈추는 편이 낫다.
     *
     * @param  array<int, array<string, mixed>>  $ops
     * @return array<int, string>
     */
    private function validate(array $ops): array
    {
        $problems = [];
        $preds = [];
        foreach ($this->byAid as $aid => $it) {
            $preds[$aid] = array_map('strtoupper', (array) $it->preds);
        }
        $adding = [];

        foreach ($ops as $i => $op) {
            $kind = (string) ($op['op'] ?? '');
            $aid = strtoupper(trim((string) ($op['activity_id'] ?? '')));
            if ($aid === '' || ! preg_match('/^[A-Z]\d{3}$/', $aid)) {
                $problems[] = "#{$i}: activity_id 형식 오류 «{$aid}» (예: B080)";

                continue;
            }
            if ($kind === 'add') {
                if (isset($this->byAid[$aid])) {
                    // 멱등 — 이미 있으면 건너뛸 것이므로 문제로 치지 않는다.
                } elseif (isset($adding[$aid])) {
                    $problems[] = "#{$i}: 같은 코드를 두 번 추가 «{$aid}»";
                } else {
                    $adding[$aid] = true;
                    if (! is_int($op['parent_task_id'] ?? null) || ! WbsItem::query()->whereKey($op['parent_task_id'])->where('level', WbsItem::LEVEL_TASK)->exists()) {
                        $problems[] = "#{$i} {$aid}: parent_task_id 가 태스크가 아님";
                    }
                }
                $preds[$aid] = array_map('strtoupper', (array) ($op['preds'] ?? []));
            } elseif (in_array($kind, ['modify', 'hold'], true)) {
                if (! isset($this->byAid[$aid]) && ! isset($adding[$aid])) {
                    $problems[] = "#{$i}: {$kind} 대상이 없음 «{$aid}»";
                }
                if ($kind === 'modify' && isset($op['preds'])) {
                    $preds[$aid] = array_map('strtoupper', (array) $op['preds']);
                }
            } else {
                $problems[] = "#{$i}: 알 수 없는 op «{$kind}»";
            }
            foreach ((array) ($op['repoint'] ?? []) as $rp) {
                $t = strtoupper(trim((string) ($rp['activity_id'] ?? '')));
                if (! isset($this->byAid[$t]) && ! isset($adding[$t])) {
                    $problems[] = "#{$i}: repoint 대상이 없음 «{$t}»";
                }
                $preds[$t] = array_map('strtoupper', (array) ($rp['preds'] ?? []));
            }
        }

        // 고아 선행
        foreach ($preds as $aid => $list) {
            foreach ($list as $p) {
                if (! isset($preds[$p])) {
                    $problems[] = "{$aid} 의 선행 «{$p}» 가 존재하지 않음";
                }
                if ($p === $aid) {
                    $problems[] = "{$aid} 가 자기 자신을 선행으로 가리킴";
                }
            }
        }

        // 순환 (DFS)
        $color = [];
        $stack = [];
        $visit = function (string $n) use (&$visit, &$color, &$stack, $preds, &$problems): void {
            $color[$n] = 1;
            $stack[] = $n;
            foreach ($preds[$n] ?? [] as $p) {
                if (! isset($preds[$p])) {
                    continue;
                }
                if (($color[$p] ?? 0) === 1) {
                    $cyc = array_slice($stack, array_search($p, $stack, true));
                    $problems[] = '순환: '.implode(' → ', $cyc).' → '.$p;
                } elseif (($color[$p] ?? 0) === 0) {
                    $visit($p);
                }
            }
            array_pop($stack);
            $color[$n] = 2;
        };
        foreach (array_keys($preds) as $n) {
            if (($color[$n] ?? 0) === 0) {
                $visit((string) $n);
            }
        }

        return array_values(array_unique($problems));
    }

    /* ── 적용 ─────────────────────────────────────────────────────── */

    /** @param array<string, mixed> $op @param array<string, array<int, string>> $summary */
    private function applyAdd(array $op, string $aid, string $project, string $tag, array &$summary): void
    {
        if (isset($this->byAid[$aid])) {
            $summary['skipped'][] = "{$aid} 이미 있음 — 추가 생략";

            return;
        }

        $task = WbsItem::query()->findOrFail((int) $op['parent_task_id']);
        $siblings = WbsItem::query()->where('parent_id', $task->id)->count();
        $days = max(0, (int) ($op['days'] ?? 1));
        $preds = array_values(array_map('strtoupper', (array) ($op['preds'] ?? [])));

        $start = $this->startFor($op, $preds, $project);
        $end = $days > 0 ? $start->copy()->addDays($days - 1) : $start->copy();

        $item = WbsItem::query()->create([
            'project_id' => $task->project_id,
            'project_code' => $project,
            'site_id' => $task->site_id,
            'company_id' => $task->company_id,
            'parent_id' => $task->id,
            'level' => WbsItem::LEVEL_SUBTASK,
            'wbs_code' => "{$project}-W-{$aid}",
            'node_no' => trim((string) $task->node_no).'.'.($siblings + 1),
            'activity_id' => $aid,
            'name' => (string) $op['name'],
            'trade' => (string) ($op['trade'] ?: $task->trade ?: $task->name),
            'days' => $days,
            'planned_start' => $start->toDateString(),
            'planned_end' => $end->toDateString(),
            'status' => '검수완료',
            'progress' => 0,
            'ehs' => 'low',
            'sort_order' => $siblings + 1,
            'source' => 'gap-fill',
            'preds' => $preds,
            'hold_point' => (bool) ($op['hold_point'] ?? false),
            'hold_note' => $op['hold_note'] ?? null,
            'submittal_seqs' => $this->seqsFor($op) ?: null,
            'payload' => [
                'name_en' => $op['name_en'] ?? null,
                'section' => $task->parent?->name,
                // 양생·대기처럼 휴일에도 흐르는 «경과 시간» 활동 표식. 없으면 근무일 활동.
                'calendar' => ! empty($op['elapsed']) ? 'elapsed' : null,
                // 기준선을 여기서 정한다. 엔진은 후속 활동을 max(기준선 시작, 선행 종료+간격)에
                // 놓는데, 기준선 시작을 비워 두면 순수하게 선행이 정하는 날짜에 놓인다 —
                // 옛 날짜가 바닥으로 남아 새 선행이 밀어도 안 움직이는 일을 막는다.
                // 선행이 없는 뿌리 활동만 힌트 날짜에 닻을 내린다.
                'cpm_baseline' => $preds === []
                    ? ['start' => $start->toDateString(), 'gaps' => []]
                    : ['start' => null, 'gaps' => array_fill_keys($preds, 1)],
                'gap_fix' => ['tag' => $tag, 'gap' => (int) ($op['gap'] ?? 0), 'basis' => $op['basis'] ?? null, 'at' => now()->toDateString()],
            ],
        ]);

        $this->byAid[$aid] = $item;
        $summary['added'][] = sprintf('%s %s (%d일, 선행 %s)%s', $aid, $item->name, $days, $preds ? implode(',', $preds) : '없음', $item->hold_point ? ' ⛔' : '');
    }

    /** @param array<string, mixed> $op @param array<string, array<int, string>> $summary */
    private function applyModify(array $op, string $aid, string $tag, array &$summary): void
    {
        $item = $this->byAid[$aid];
        $gap = (int) ($op['gap'] ?? 0);
        $done = collect((array) data_get($item->payload, 'gap_fix.modified', []))->contains(fn ($m) => ($m['tag'] ?? '') === $tag && (int) ($m['gap'] ?? -1) === $gap);
        if ($done) {
            $summary['skipped'][] = "{$aid} 구멍 {$gap} 수정은 이미 적용됨";

            return;
        }

        $changes = [];
        if (! empty($op['rename_to'])) {
            $changes[] = '이름 «'.$item->name.'» → «'.$op['rename_to'].'»';
            $item->name = (string) $op['rename_to'];
        }
        if (isset($op['new_days'])) {
            $d = max(0, (int) $op['new_days']);
            $changes[] = '기간 '.$item->days.' -> '.$d.'일';
            $item->days = $d;
            if ($item->planned_start) {
                $item->planned_end = $d > 0 ? $item->planned_start->copy()->addDays($d - 1)->toDateString() : $item->planned_start->toDateString();
            }
        }
        // 시작 힌트 — 기간과 함께 작업 폭(span)의 근거가 된다. 실제 배치는 엔진이 선행으로 정한다.
        if (! empty($op['start_hint'])) {
            $hint = Carbon::parse((string) $op['start_hint']);
            $d = max(0, (int) ($op['new_days'] ?? $item->days ?? 1));
            $item->planned_start = $hint->toDateString();
            $item->planned_end = ($d > 0 ? $hint->copy()->addDays($d - 1) : $hint)->toDateString();
        }
        if (isset($op['preds'])) {
            $p = array_values(array_map('strtoupper', (array) $op['preds']));
            $changes[] = '선행 '.json_encode($item->preds).' -> '.json_encode($p);
            $item->preds = $p;
            // 선행이 바뀌면 옛 기준선(옛 날짜가 바닥이 되는)은 버리고, 새 선행마다 간격 1 의
            // 순수 CPM 링크로 다시 쓴다. 지워만 두면 엔진이 지금(옛) 날짜로 다시 포착해 버린다.
            $payload = (array) $item->payload;
            $payload['cpm_baseline'] = $p === []
                ? ['start' => $item->planned_start?->toDateString(), 'gaps' => []]
                : ['start' => null, 'gaps' => array_fill_keys($p, 1)];
            $item->payload = $payload;
        }
        if (array_key_exists('hold_point', $op)) {
            $item->hold_point = (bool) $op['hold_point'];
            $item->hold_note = $op['hold_note'] ?? $item->hold_note;
            $changes[] = '멈춤점 '.($item->hold_point ? '켬' : '끔');
        }
        if ($seqs = $this->seqsFor($op)) {
            $item->submittal_seqs = array_values(array_unique(array_merge((array) $item->submittal_seqs, $seqs)));
        }

        $payload = (array) $item->payload;
        // «경과 시간» 활동(양생·대기)은 휴일에도 흐른다 — 달력 엔진이 이 표식을 본다.
        if (array_key_exists('elapsed', $op)) {
            $payload['calendar'] = $op['elapsed'] ? 'elapsed' : 'work';
            $changes[] = '달력 '.($op['elapsed'] ? '경과시간' : '근무일');
        }
        if (! empty($op['name_en'])) {
            $payload['name_en'] = (string) $op['name_en'];
        }
        $payload['gap_fix']['modified'][] = ['tag' => $tag, 'gap' => $gap, 'basis' => $op['basis'] ?? null, 'at' => now()->toDateString(), 'changes' => $changes];
        $item->payload = $payload;
        $item->save();

        $summary['modified'][] = "{$aid} ".implode(' · ', $changes);
    }

    /** @param array<string, mixed> $op @param array<string, array<int, string>> $summary */
    private function applyHold(array $op, string $aid, string $tag, array &$summary): void
    {
        $item = $this->byAid[$aid];
        $item->hold_point = true;
        if (! empty($op['hold_note'])) {
            $item->hold_note = (string) $op['hold_note'];
        }
        if ($seqs = $this->seqsFor($op)) {
            $item->submittal_seqs = array_values(array_unique(array_merge((array) $item->submittal_seqs, $seqs)));
        }
        $payload = (array) $item->payload;
        $payload['gap_fix']['held'][] = ['tag' => $tag, 'gap' => (int) ($op['gap'] ?? 0), 'basis' => $op['basis'] ?? null, 'at' => now()->toDateString()];
        $item->payload = $payload;
        $item->save();

        $summary['held'][] = "{$aid} {$item->name} — ".(string) ($op['hold_note'] ?? '');
    }

    /** @param array<string, mixed> $rp @param array<string, array<int, string>> $summary */
    private function applyRepoint(array $rp, string $tag, array &$summary): void
    {
        $t = strtoupper(trim((string) ($rp['activity_id'] ?? '')));
        $item = $this->byAid[$t];
        $p = array_values(array_map('strtoupper', (array) ($rp['preds'] ?? [])));
        if ($p === array_map('strtoupper', (array) $item->preds)) {
            return;
        }
        $summary['repointed'][] = "{$t} 선행 ".json_encode($item->preds).' → '.json_encode($p);
        $item->preds = $p;
        $payload = (array) $item->payload;
        unset($payload['cpm_baseline']);
        $payload['gap_fix']['repointed'][] = ['tag' => $tag, 'at' => now()->toDateString(), 'preds' => $p];
        $item->payload = $payload;
        $item->save();
    }

    /* ── 보조 ─────────────────────────────────────────────────────── */

    /**
     * 시작일 — 힌트가 있으면 그것, 없으면 선행들의 가장 늦은 종료 다음 날, 그것도 없으면 프로젝트 첫 활동일.
     * 어차피 엔진이 재배치하지만 첫 기준선 포착에 쓰이므로 터무니없는 값을 주면 안 된다.
     *
     * @param  array<string, mixed>  $op  @param  list<string>  $preds
     */
    private function startFor(array $op, array $preds, string $project): Carbon
    {
        if (! empty($op['start_hint'])) {
            return Carbon::parse((string) $op['start_hint']);
        }
        $latest = null;
        foreach ($preds as $p) {
            // 목록 뒤쪽에서 추가될 선행은 아직 없을 수 있다 — 검증은 전체 목록으로 이미 통과했으므로
            // 여기서는 건너뛰고 엔진의 재배치에 맡긴다.
            $end = isset($this->byAid[$p]) ? $this->byAid[$p]->planned_end : null;
            if ($end && (! $latest || $end->gt($latest))) {
                $latest = $end;
            }
        }
        if ($latest) {
            return $latest->copy()->addDay();
        }
        $first = WbsItem::query()->where('project_code', $project)->where('level', WbsItem::LEVEL_SUBTASK)->min('planned_start');

        return Carbon::parse($first ?: now()->toDateString());
    }

    /**
     * 시방 섹션 → 제출물 대장 seq. «10 2813» 처럼 앞부분만 줘도 그 섹션 전체를 잇는다.
     *
     * @param  array<string, mixed>  $op
     * @return list<int>
     */
    private function seqsFor(array $op): array
    {
        $sections = array_filter(array_map('trim', (array) ($op['submittal_sections'] ?? [])));
        if ($sections === []) {
            return [];
        }
        $seqs = [];
        foreach ($sections as $sec) {
            $seqs = array_merge($seqs, Submittal::query()->where('csi', 'like', $sec.'%')->pluck('seq')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique(array_filter($seqs)));
    }
}

/** dry-run 되돌림용 — 예외가 트랜잭션을 롤백한다. */
final class DryRunRollback extends RuntimeException {}
