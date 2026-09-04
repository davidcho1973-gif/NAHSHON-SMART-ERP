<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Submittal;
use App\Models\WbsItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 제출물 대장에 기한을 넣고 빠진 줄을 더한다 — 계획 목록(JSON)대로.
 *
 * 왜 필요한가: 시방에서 뽑아 온 대장은 «무엇을 내야 하는가» 는 알지만 «언제까지» 를 모른다.
 * 기한이 비어 있는 대장은 화면에 떠 있어도 아무도 움직이지 못한다 — 늦은 줄이 붉게 서지
 * 않으니, 승인 없이는 발주가 안 되는 장납기 자재가 조용히 공정을 밀어낸다.
 *
 * 왜 화면이 아니라 명령인가: 기한은 하나하나가 공정 활동에서 역산한 값이고, 활동이 밀리면
 * 수십 건이 함께 밀린다. 손으로 넣으면 근거가 사라져 다음에 왜 그 날짜인지 아무도 모른다.
 * 목록에 근거(why)를 함께 적어 두면 공정이 바뀔 때 같은 규칙으로 다시 돌릴 수 있다.
 *
 * 규약:
 *  - dates : 기존 줄(seq)에 planned_on 을 넣는다. 이미 같은 날짜면 건너뛴다(멱등).
 *            사람이 손으로 바꾼 날짜는 --force 없이는 덮지 않는다.
 *  - add   : 대장에 없는 줄을 더한다. 같은 제목이 이미 있으면 건너뛴다(멱등).
 *  - 적용 전에 목록을 검증하고, 하나라도 문제가 있으면 아무것도 바꾸지 않는다.
 *  - --dry-run 이면 트랜잭션 안에서 적용·출력한 뒤 되돌린다.
 */
class PlanSubmittals extends Command
{
    protected $signature = 'erp:plan-submittals
                            {file? : 계획 목록 JSON (생략하면 database/data/703k/submittal_plan.json)}
                            {--project= : 프로젝트 코드 (생략하면 목록의 project)}
                            {--dry-run : 적용해 보고 되돌린다 (결과만 본다)}
                            {--force : 사람이 이미 넣은 기한도 덮어쓴다}';

    protected $description = '제출물 대장에 공정에서 역산한 기한을 넣고, 시방에 없어 빠진 제출물을 더한다';

    public function handle(): int
    {
        $file = (string) ($this->argument('file') ?: database_path('data/703k/submittal_plan.json'));
        if (! is_readable($file)) {
            $this->error("파일을 읽을 수 없습니다: {$file}");

            return self::FAILURE;
        }

        $plan = json_decode((string) file_get_contents($file), true);
        if (! is_array($plan)) {
            $this->error('계획 목록을 읽지 못했습니다 (JSON 아님).');

            return self::FAILURE;
        }

        $projectCode = (string) ($this->option('project') ?: ($plan['project'] ?? ''));
        if ($projectCode === '') {
            $this->error('프로젝트 코드가 없습니다 — 목록의 project 나 --project 를 주십시오.');

            return self::FAILURE;
        }

        $project = Project::query()->where('project_code', $projectCode)->first();
        if (! $project) {
            $this->error("프로젝트를 찾을 수 없습니다: {$projectCode}");

            return self::FAILURE;
        }

        $dates = is_array($plan['dates'] ?? null) ? $plan['dates'] : [];
        $adds = is_array($plan['add'] ?? null) ? $plan['add'] : [];
        if ($dates === [] && $adds === []) {
            $this->error('계획이 비어 있습니다 (dates·add 둘 다 없음).');

            return self::FAILURE;
        }

        // ── 검증 — 하나라도 어긋나면 아무것도 바꾸지 않는다.
        $problems = $this->validate($project->id, $dates, $adds);
        if ($problems !== []) {
            $this->error('적용하지 않았습니다 — 계획에 문제가 있습니다:');
            foreach ($problems as $p) {
                $this->line('  · '.$p);
            }

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $stats = ['기한' => 0, '기한 유지' => 0, '사람이 넣은 값 보존' => 0, '신규' => 0, '신규 중복' => 0];

        $snapshot = [];

        try {
            DB::transaction(function () use ($project, $dates, $adds, $force, &$stats, &$snapshot, $dry) {
                // 1. 기존 줄에 기한
                foreach ($dates as $row) {
                    $s = Submittal::query()->where('project_id', $project->id)->where('seq', (int) $row['seq'])->first();
                    if (! $s) {
                        continue;
                    }
                    $want = (string) $row['planned_on'];
                    $have = $s->planned_on?->toDateString();
                    if ($have === $want) {
                        $stats['기한 유지']++;

                        continue;
                    }
                    // 이미 사람이 넣어 둔 날짜는 조용히 덮지 않는다 — 현장이 원청과 합의한 값일 수 있다.
                    if ($have !== null && ! $force && ! $this->wasPlacedByThisCommand($s)) {
                        $stats['사람이 넣은 값 보존']++;
                        $this->line("  · seq {$s->seq}: 기존 {$have} 유지 (계획 {$want}) — 덮으려면 --force");

                        continue;
                    }
                    $s->planned_on = $want;
                    $s->notes = $this->stampWhy($s->notes, (string) ($row['why'] ?? ''));
                    $s->save();
                    $stats['기한']++;
                }

                // 2. 빠진 줄 더하기
                //
                // 번호(seq)는 장식이 아니다 — 공정 활동이 submittal_seqs 로 이 번호를 붙잡고 있다.
                // 그래서 목록이 번호를 지정하면 비어 있는 한 그 번호로 세운다. 지웠다 다시 세운
                // 줄이 옛 번호를 되찾아야 «이 검측은 저 제출물을 기다린다» 는 연결이 안 끊긴다.
                // 번호를 지정한 줄을 먼저 세운다. 뒤로 미루면 그 사이 자동 번호가 그 자리를
                // 차지해 버려서, 지정한 번호는 «이미 쓰이고 있음» 으로 늘 밀려난다.
                usort($adds, fn ($a, $b) => (isset($b['seq']) ? 1 : 0) <=> (isset($a['seq']) ? 1 : 0));

                // 이 실행에서 이미 쓴 번호까지 세어야 한다 — 지정 번호(277 같은)가 자동 번호의
                // 다음 자리와 겹치면, DB 만 보고 고른 번호가 같은 트랜잭션 안에서 충돌한다.
                $taken = Submittal::query()->where('project_id', $project->id)->pluck('seq')->flip()->all();
                $seq = $taken === [] ? 0 : max(array_keys($taken));
                foreach ($adds as $row) {
                    $title = (string) ($row['title'] ?? '');
                    $exists = Submittal::query()->where('project_id', $project->id)
                        ->where('title', $title)->exists();
                    if ($exists) {
                        $stats['신규 중복']++;

                        continue;
                    }
                    $want = isset($row['seq']) ? (int) $row['seq'] : 0;
                    $free = $want > 0 && ! isset($taken[$want]);
                    if ($free) {
                        $use = $want;
                    } else {
                        do {
                            $seq++;
                        } while (isset($taken[$seq]));
                        $use = $seq;
                        if ($want > 0) {
                            $this->line("  · seq {$want} 는 이미 쓰이고 있어 {$use} 로 세웁니다 — ".mb_substr($title, 0, 36));
                        }
                    }
                    $taken[$use] = true;
                    Submittal::query()->create([
                        'company_id' => $project->company_id,
                        'site_id' => $project->site_id,
                        'project_id' => $project->id,
                        'seq' => $use,
                        'csi' => $row['csi'] ?? null,
                        'section' => $row['section'] ?? null,
                        'category' => $row['category'] ?? 'Action 제출물',
                        'title' => $title,
                        'gate' => (bool) ($row['gate'] ?? false),
                        'status' => $row['status'] ?? '미착수',
                        'planned_on' => $row['planned_on'] ?? null,
                        'notes' => $row['notes'] ?? null,
                        'extracted_by' => self::STAMP,
                    ]);
                    $stats['신규']++;
                }

                // 결과 집계는 되돌리기 전에 해 둔다 — 되돌린 뒤에 세면 바꾸기 전 숫자가 나와
                // 미리보기가 «아무 일도 안 일어난다» 고 거짓말을 한다.
                $snapshot = $this->snapshot($project->id);

                if ($dry) {
                    throw new DryRunRollback;
                }
            });
        } catch (DryRunRollback) {
            // 의도된 되돌림 — 위에서 떠 둔 집계로 결과를 낸다.
        }

        return $this->report($snapshot, $stats, $dry);
    }

    /** notes 에 남기는 표식 — 이 명령이 넣은 기한인지 사람이 넣은 기한인지 나중에 구별한다. */
    private const STAMP = 'plan-submittals';

    private function wasPlacedByThisCommand(Submittal $s): bool
    {
        return str_contains((string) $s->notes, '[기한 근거]');
    }

    private function stampWhy(?string $notes, string $why): ?string
    {
        if ($why === '') {
            return $notes;
        }
        $line = "[기한 근거] {$why}";
        $keep = trim(preg_replace('/^\[기한 근거\].*$/mu', '', (string) $notes) ?? '');

        return trim($keep === '' ? $line : $keep."\n".$line);
    }

    /** @return list<string> */
    private function validate(int $projectId, array $dates, array $adds): array
    {
        $out = [];
        $seen = [];
        foreach ($dates as $i => $row) {
            $seq = (int) ($row['seq'] ?? 0);
            if ($seq <= 0) {
                $out[] = "dates[{$i}]: seq 가 없습니다.";

                continue;
            }
            if (isset($seen[$seq])) {
                $out[] = "dates: seq {$seq} 가 두 번 나옵니다.";
            }
            $seen[$seq] = true;
            if (! Submittal::query()->where('project_id', $projectId)->where('seq', $seq)->exists()) {
                $out[] = "dates: seq {$seq} 가 대장에 없습니다.";
            }
            if (! $this->isDate($row['planned_on'] ?? null)) {
                $out[] = "dates: seq {$seq} 의 planned_on 이 날짜가 아닙니다.";
            }
        }

        $titles = [];
        foreach ($adds as $i => $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $out[] = "add[{$i}]: title 이 없습니다.";

                continue;
            }
            if (isset($titles[$title])) {
                $out[] = 'add: 같은 제목이 두 번 나옵니다 — '.mb_substr($title, 0, 40);
            }
            $titles[$title] = true;

            $cat = (string) ($row['category'] ?? 'Action 제출물');
            if (! isset(Submittal::CATEGORY_OPTIONS[$cat])) {
                $out[] = "add[{$i}]: 카테고리 «{$cat}» 는 허용값이 아닙니다.";
            }
            $st = (string) ($row['status'] ?? '미착수');
            if (! isset(Submittal::STATUS_OPTIONS[$st])) {
                $out[] = "add[{$i}]: 상태 «{$st}» 는 허용값이 아닙니다.";
            }
            if (($row['planned_on'] ?? null) !== null && ! $this->isDate($row['planned_on'])) {
                $out[] = "add[{$i}]: planned_on 이 날짜가 아닙니다.";
            }
        }

        return $out;
    }

    private function isDate(mixed $v): bool
    {
        if (! is_string($v) || $v === '') {
            return false;
        }
        try {
            Carbon::parse($v);

            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 되돌리기 전에 떠 두는 결과 — 대장 상태와 공정 대조.
     *
     * 미리보기는 트랜잭션을 되돌리므로, 되돌린 뒤에 세면 «바꾸기 전» 숫자가 나온다.
     * 그래서 트랜잭션 안에서 여기 한 번 담고, 밖에서는 담긴 것만 찍는다.
     *
     * @return array{total:int,noDate:int,soon:array<int,array<string,mixed>>,late:array<int,string>}
     */
    private function snapshot(int $projectId): array
    {
        $q = fn () => Submittal::query()->where('project_id', $projectId);

        $soon = $q()->whereNotNull('planned_on')
            ->whereIn('status', ['미착수', '작성중', '반려', '재제출'])
            ->orderBy('planned_on')->orderByDesc('gate')->limit(14)
            ->get(['seq', 'planned_on', 'gate', 'csi', 'title'])
            ->map(fn (Submittal $s) => [
                'seq' => $s->seq,
                'on' => $s->planned_on->toDateString(),
                'gate' => (bool) $s->gate,
                'csi' => (string) $s->csi,
                'title' => (string) $s->title,
            ])->all();

        return [
            'total' => $q()->count(),
            'noDate' => $q()->whereNull('planned_on')->count(),
            'soon' => $soon,
            'late' => $this->lateAgainstWbs($projectId),
        ];
    }

    private function report(array $snap, array $stats, bool $dry): int
    {
        $this->newLine();
        $this->info($dry ? '─── 미리보기 (되돌렸습니다)' : '─── 적용했습니다');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-16s %d', $k, $v));
        }

        $this->newLine();
        $this->line(sprintf('  대장 %d건 중 기한 없는 줄 %d건', $snap['total'] ?? 0, $snap['noDate'] ?? 0));

        if (($snap['soon'] ?? []) !== []) {
            $this->newLine();
            $this->line('  ── 가장 급한 14건');
            foreach ($snap['soon'] as $s) {
                $this->line(sprintf(
                    '  %s %s %-11s %s',
                    $s['on'],
                    $s['gate'] ? '⛔' : '  ',
                    $s['csi'],
                    mb_substr($s['title'], 0, 62),
                ));
            }
        }

        // 제출물이 그것을 기다리는 공정 활동보다 늦으면 대장이 거짓말을 한다 — 반드시 보여 준다.
        if (($snap['late'] ?? []) !== []) {
            $this->newLine();
            $this->warn('  ── 공정보다 늦은 기한');
            foreach ($snap['late'] as $l) {
                $this->line('  · '.$l);
            }
        }

        if ($dry) {
            $this->newLine();
            $this->warn('  미리보기였습니다 — 아무것도 저장하지 않았습니다. 실제 적용은 --dry-run 없이 다시 실행하세요.');
        }

        return self::SUCCESS;
    }

    /**
     * 제출물 기한이 그 제출물을 기다리는 공정 활동보다 늦지 않은지 본다.
     * 활동의 submittal_seqs 에 걸린 줄만 볼 수 있다 — 그것이 대장과 공정을 잇는 유일한 끈이다.
     *
     * @return list<string>
     */
    private function lateAgainstWbs(int $projectId): array
    {
        $project = Project::query()->find($projectId);
        $items = WbsItem::query()
            ->where('project_code', $project?->project_code)
            ->whereNotNull('submittal_seqs')
            ->get(['activity_id', 'name', 'planned_start', 'submittal_seqs']);
        if ($items->isEmpty()) {
            return [];
        }

        // «착수 전 승인» 성격의 줄만 본다. 준공 서류(O&M·보증·여분자재)와 시험·검사는 그 활동
        // 뒤에 오는 것이 정상이라, 전부 비교하면 정상인 줄이 경고로 쏟아져 진짜 늦은 줄을 덮는다.
        $bySeq = Submittal::query()->where('project_id', $projectId)
            ->whereNotNull('planned_on')
            ->where('category', 'Action 제출물')
            ->pluck('planned_on', 'seq');

        $out = [];
        foreach ($items as $it) {
            $start = $it->planned_start ? Carbon::parse($it->planned_start) : null;
            if (! $start) {
                continue;
            }
            foreach ((array) $it->submittal_seqs as $seq) {
                $due = $bySeq[(int) $seq] ?? null;
                if ($due && Carbon::parse($due)->greaterThan($start)) {
                    $out[] = sprintf(
                        '%s %s 시작 %s < 제출물 seq %d 기한 %s',
                        $it->activity_id,
                        mb_substr((string) $it->name, 0, 22),
                        $start->toDateString(),
                        (int) $seq,
                        Carbon::parse($due)->toDateString(),
                    );
                }
            }
        }

        return $out;
    }
}
