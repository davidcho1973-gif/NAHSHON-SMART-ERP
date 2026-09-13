<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntelligentDocument;
use App\Support\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 아무도 듣지 않는 줄에 남은 옛 문서 작업을 일꾼이 듣는 줄로 옮긴다.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 2026-09-05 이전 코드는 문서 작업에 큐 이름을 지정하지 않아서 전부 공용 default
 * 로 들어갔다. 그 뒤 코드는 documents 로 보내고, 일꾼도 documents 만 듣는다
 * (공용 줄을 통째로 맡기면 앞으로 어떤 업무 작업이 실행될지 아무도 보장 못 한다).
 *
 * 그래서 나손의 옛 작업 85건은 <b>일꾼을 제대로 세워도 영원히 처리되지 않는다.</b>
 * 줄 이름 하나 때문에 서로를 못 보고 있다.
 *
 * ── 왜 손으로 UPDATE 하면 안 되나 ──────────────────────────────────────
 * docs/document-analysis-operations.md 가 옮기기를 허용하되 조건을 달아 두었다:
 * 검토한 작업 ID, 정확한 작업 클래스, 손대지 않은 상태(예약·시도 이력 없음),
 * 연결된 문서의 상태 확인, 그리고 <b>바꾸기 직전 트랜잭션 안에서 다시 확인</b>,
 * 바꾸는 것은 queue 칸 하나뿐.
 *
 * 조건이 여섯 개다. 손으로 SQL 을 쓰면 하나를 빠뜨리고, 빠뜨린 줄은 눈에 안 띈다.
 * 그래서 규칙을 코드 한 곳에 적어 두고 시험으로 묶는다.
 *
 * ── 기본은 시늉만 ──────────────────────────────────────────────────────
 * --apply 없이는 아무것도 바꾸지 않는다. 먼저 무엇이 옮겨지고 무엇이 빠지는지
 * 읽고 나서 결정한다. 여기서 «과금 대상 문서 수» 도 같이 세어 준다 — 작업 85건이
 * 문서 85건이라는 뜻이 아니고, 실제로 AI 를 부르는 것은 문서 단위다.
 */
class MoveLegacyDocumentJobs extends Command
{
    protected $signature = 'docs:move-legacy-jobs
        {--apply : 실제로 옮긴다. 없으면 무엇이 옮겨질지 보여주기만 한다}
        {--from=default : 어느 줄에서 가져올지}
        {--to= : 어느 줄로 보낼지 (기본값은 일꾼이 듣는 줄)}
        {--limit=0 : 한 번에 옮길 최대 건수. 0 이면 전부}
        {--record= : 옮긴 작업 ID 를 적어 둘 파일 (되돌릴 때 쓴다)}';

    protected $description = '옛 문서 분석 작업을 일꾼이 듣는 큐로 옮긴다 (기본은 시늉만).';

    /** 옮겨도 되는 작업은 이 클래스 하나뿐이다. */
    private const MOVABLE_CLASS = AnalyzeIntelligentDocumentJob::class;

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: QueueHealth::DOCUMENT_QUEUE);
        $limit = (int) $this->option('limit');
        $apply = (bool) $this->option('apply');

        if ($from === $to) {
            $this->error("보내는 줄과 받는 줄이 같습니다 ({$from}). 옮길 것이 없습니다.");

            return self::FAILURE;
        }

        if (! in_array($to, QueueHealth::SERVED_QUEUES, true)) {
            // 일꾼이 안 듣는 줄로 옮기면 지금과 똑같은 상태가 된다. 이름만 바뀐다.
            $this->error("«{$to}» 는 일꾼이 듣는 줄이 아닙니다. 옮겨도 아무도 처리하지 않습니다.");
            $this->line('  일꾼이 듣는 줄: '.implode(', ', QueueHealth::SERVED_QUEUES));

            return self::FAILURE;
        }

        $rows = DB::table('jobs')->where('queue', $from)->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->info("«{$from}» 줄이 비어 있습니다. 옮길 것이 없습니다.");

            return self::SUCCESS;
        }

        $buckets = ['movable' => [], 'not_document_job' => [], 'in_flight' => [], 'no_document' => [], 'document_not_queued' => []];

        foreach ($rows as $row) {
            $buckets[$this->classify($row)][] = $row;
        }

        $movable = $buckets['movable'];
        if ($limit > 0) {
            $movable = array_slice($movable, 0, $limit);
        }

        $this->report($from, $to, $buckets, $movable);

        if (! $apply) {
            $this->newLine();
            $this->warn('시늉만 했습니다 — 아무것도 바꾸지 않았습니다.');
            $this->line('  실제로 옮기려면 같은 명령에 --apply 를 붙이세요.');

            return self::SUCCESS;
        }

        if ($movable === []) {
            $this->newLine();
            $this->info('옮길 수 있는 작업이 없습니다.');

            return self::SUCCESS;
        }

        $moved = $this->move($movable, $from, $to);

        $this->newLine();
        $this->info("{$moved}건을 «{$from}» 에서 «{$to}» 로 옮겼습니다.");

        if ($moved !== count($movable)) {
            // 읽은 뒤 바꾸기 전까지 사이에 누군가 집어갔다는 뜻이다. 정상이다.
            $this->line('  ('.(count($movable) - $moved).'건은 그사이 상태가 바뀌어 건너뛰었습니다 — 다시 실행하면 됩니다.)');
        }

        $this->line('  일꾼이 돌고 있으면 곧 처리되기 시작합니다.');

        return self::SUCCESS;
    }

    /**
     * 옮겨도 되는가. 하나라도 어긋나면 옮기지 않는다.
     */
    private function classify(object $row): string
    {
        if ($this->displayName($row->payload) !== self::MOVABLE_CLASS) {
            // 문서 분석이 아닌 작업은 손대지 않는다. 공용 줄에는 다른 업무가 들어온다.
            return 'not_document_job';
        }

        // 예약됐거나 이미 시도한 적이 있으면 손대지 않는다 — 누군가 집어간 것일 수 있다.
        if ($row->reserved_at !== null || (int) $row->attempts !== 0) {
            return 'in_flight';
        }

        $documentId = $this->documentId($row->payload);
        if ($documentId === null) {
            return 'no_document';
        }

        $status = IntelligentDocument::query()->whereKey($documentId)->value('ai_status');
        if ($status === null) {
            // 문서가 지워졌는데 작업만 남은 경우. 옮겨 봐야 헛돈다.
            return 'no_document';
        }

        if ($status !== 'queued') {
            // 이미 끝났거나 실패했거나 처리 중이다. 이 작업은 옮겨도 그냥 끝난다
            // (AnalyzeIntelligentDocumentJob::claim() 이 queued 가 아니면 바로 나온다).
            return 'document_not_queued';
        }

        return 'movable';
    }

    /**
     * @param  array<string, list<object>>  $buckets
     * @param  list<object>  $movable
     */
    private function report(string $from, string $to, array $buckets, array $movable): void
    {
        $this->newLine();
        $this->line("«{$from}» → «{$to}»");
        $this->newLine();

        $this->table(['상태', '건수', '뜻'], [
            ['옮길 수 있음', count($movable), '문서가 아직 분석을 기다리고 있습니다'],
            ['문서 작업 아님', count($buckets['not_document_job']), '손대지 않습니다 — 다른 업무 작업입니다'],
            ['처리 중·시도함', count($buckets['in_flight']), '손대지 않습니다 — 누가 집어갔을 수 있습니다'],
            ['문서 없음', count($buckets['no_document']), '문서가 지워졌습니다. 옮겨도 헛돕니다'],
            ['이미 끝남·실패', count($buckets['document_not_queued']), '옮겨도 그냥 종료됩니다'],
        ]);

        // 돈이 나가는 단위는 작업이 아니라 «문서» 다. 같은 문서에 작업이 여러 개
        // 겹쳐 있어도 AI 는 한 번만 부른다 — 이 숫자를 보고 비용을 가늠하시라.
        $documents = [];
        foreach ($movable as $row) {
            $id = $this->documentId($row->payload);
            if ($id !== null) {
                $documents[$id] = true;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '  실제로 AI 를 부르게 될 <options=bold>문서</> 수: %d건 (작업 %d건)',
            count($documents),
            count($movable),
        ));

        if (count($movable) > count($documents)) {
            $this->line(sprintf(
                '  같은 문서에 겹쳐 있는 작업 %d건은 순서만 기다리다 그냥 끝납니다.',
                count($movable) - count($documents),
            ));
        }
    }

    /**
     * 바꾸기 직전에 트랜잭션 안에서 조건을 다시 확인한다. 읽은 시점과 바꾸는
     * 시점 사이에 일꾼이 집어갔을 수 있고, 그때 큐 이름을 바꾸면 같은 문서가
     * 두 번 분석된다 — 돈이 두 번 나간다.
     *
     * @param  list<object>  $movable
     */
    private function move(array $movable, string $from, string $to): int
    {
        $moved = [];

        foreach ($movable as $candidate) {
            DB::transaction(function () use ($candidate, $from, $to, &$moved): void {
                $row = DB::table('jobs')->where('id', $candidate->id)->lockForUpdate()->first();

                if ($row === null || $row->queue !== $from || $this->classify($row) !== 'movable') {
                    return;
                }

                // 바꾸는 것은 queue 칸 하나뿐이다. payload 도, 시각도 건드리지 않는다.
                DB::table('jobs')->where('id', $row->id)->update(['queue' => $to]);
                $moved[] = (int) $row->id;
            });
        }

        $record = (string) ($this->option('record') ?? '');
        if ($record !== '' && $moved !== []) {
            @file_put_contents($record, json_encode([
                'moved_at' => now()->toIso8601String(),
                'from' => $from,
                'to' => $to,
                'job_ids' => $moved,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("  옮긴 작업 ID 를 {$record} 에 적어 두었습니다.");
        }

        return count($moved);
    }

    /**
     * payload 를 JSON 으로 해석하지 않고 글자로 뽑는다.
     *
     * 라라벨은 PHP 객체를 직렬화해 넣는데, 비공개 속성이 있으면 널바이트( )가
     * 섞인다. PostgreSQL 의 json/jsonb 는 그 값을 글자로 못 바꿔서 <b>쿼리 전체가
     * 에러</b>로 죽는다. 한 줄만 그래도 전부 죽는다.
     */
    private function displayName(string $payload): ?string
    {
        if (preg_match('/"displayName":"([^"]*)"/', $payload, $m) !== 1) {
            return null;
        }

        return str_replace('\\\\', '\\', $m[1]);
    }

    private function documentId(string $payload): ?int
    {
        // 직렬화한 객체는 payload JSON 안에 <b>문자열로</b> 들어가므로 그 안의 따옴표가
        // 한 번 더 이스케이프된다 — 실제 값은 \"documentId\";i:42; 이다. 백슬래시 없는
        // 정규식으로 쓰면 시험 데이터는 통과하고 진짜 데이터에서만 조용히 빗나간다.
        if (preg_match('/documentId\\\\?";i:(\d+);/', $payload, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
