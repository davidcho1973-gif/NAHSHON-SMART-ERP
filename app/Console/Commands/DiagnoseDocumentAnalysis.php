<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntelligentDocument;
use App\Services\Ocr\OcrEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 문서 AI 분석이 «안 돌 때» 어디서 막혔는지 한 번에 말해 준다.
 *
 * 왜 필요한가: 분석이 멈추면 화면에는 «AI 분류 대기» 만 남는다. 그 한 줄로는
 * 열쇠가 없는 것인지, 일꾼(큐 워커)이 안 도는 것인지, 파일이 너무 커서 튕긴 것인지
 * 알 수 없다. 셋은 고치는 방법이 전혀 다른데, 화면은 셋을 구별해 주지 않는다.
 * 클라우드에서는 데이터베이스를 들여다볼 수도 없으므로, 명령 하나가 대신 봐 준다.
 *
 * 막히는 자리는 넷이다 — 이 순서로 확인한다.
 *   1) AI 열쇠      : 없으면 분석이 시작하자마자 실패한다.
 *   2) 큐 일꾼      : 안 돌면 «대기» 에서 영원히 안 움직인다. 가장 흔한 원인이다.
 *   3) 파일 크기·형식: 엔진의 직접 판독 한도를 넘는 원본은 본문 추출에 의존한다.
 *   4) 멈춘 분석    : 일꾼이 물고 죽으면 «분석 중» 에서 굳는다.
 */
class DiagnoseDocumentAnalysis extends Command
{
    protected $signature = 'docs:diagnose {--stuck-minutes=15 : 최소 15분 넘게 «분석 중» 이면 멈춘 것으로 본다}';

    protected $description = '문서 AI 분석이 왜 안 도는지 진단한다 — 열쇠·큐 일꾼·파일 크기·멈춘 분석';

    public function handle(): int
    {
        $bad = [];

        // ── 1. AI 열쇠
        $engine = strtolower(trim((string) config('services.ai_ocr.engine', 'gemini')));
        $engine = in_array($engine, ['claude', 'openai', 'gpt'], true) ? $engine : 'gemini';
        $keyPath = match ($engine) {
            'claude' => 'services.anthropic.api_key',
            'openai', 'gpt' => 'services.openai.api_key',
            default => 'services.gemini.api_key',
        };
        $hasKey = filled(config($keyPath));
        $this->line('');
        $this->line('<options=bold>1. AI 열쇠</>');
        $this->line("   엔진      {$engine}   (AI_OCR_ENGINE)");
        $this->line('   열쇠      '.($hasKey ? '있음' : '<fg=red>없음</>')."   ({$keyPath})");
        if (! $hasKey) {
            $bad[] = 'AI 열쇠가 없습니다. 배포 환경변수에 '.strtoupper($engine).'_API_KEY 를 넣어야 분석이 시작됩니다.';
        }

        // ── 2. 큐 일꾼
        $conn = 'document-analysis';
        $queueDb = DB::connection(config("queue.connections.{$conn}.connection"));
        $queueTable = (string) config("queue.connections.{$conn}.table", 'jobs');
        $jobs = $queueDb->getSchemaBuilder()->hasTable($queueTable)
            ? $queueDb->table($queueTable)->whereIn('queue', ['documents', 'default'])
                ->whereRaw("payload::jsonb->>'displayName' = ?", [AnalyzeIntelligentDocumentJob::class])
            : null;
        $waiting = 0;
        $this->line('');
        $this->line('<options=bold>2. 큐(일감 줄)</>');
        $this->line("   문서 전용 연결  {$conn}   (documents 큐 / 이전 default 큐)");
        $this->line('   실행 명령  php artisan queue:work document-analysis --queue=documents,default --sleep=3 --tries=1 --timeout=600');
        if ($jobs) {
            $waiting = (clone $jobs)->count();
            $reserved = (clone $jobs)->whereNotNull('reserved_at')->count();
            $oldest = (clone $jobs)->min('created_at');
            $this->line("   문서 일감  {$waiting}건 (예약·실행 중 {$reserved}건 포함)");
            if ($oldest) {
                $mins = (int) round((time() - (int) $oldest) / 60);
                $this->line("   가장 오래된 것  {$mins}분 전");
                if ($waiting > 0 && $mins > 10) {
                    $bad[] = "문서 일감 {$waiting}건 중 가장 오래된 요청이 {$mins}분 전입니다. "
                        .'대량 업로드 대기 또는 worker 중단일 수 있으므로 Laravel Cloud의 문서 처리 프로세스와 로그를 확인해 주세요.';
                }
            }
            if ($waiting === 0) {
                $this->line('   <fg=green>줄이 비어 있습니다 — 일꾼이 처리했거나 애초에 안 넣은 것입니다.</>');
            }
        } else {
            $bad[] = '문서 처리용 jobs 테이블이 없습니다. 데이터베이스 연결 및 마이그레이션을 확인해 주세요.';
        }
        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->count();
            $this->line("   실패 일감  {$failed}건");
            if ($failed > 0) {
                $last = DB::table('failed_jobs')->orderByDesc('id')->first();
                $this->line('   마지막 실패 사유  '.mb_substr(preg_replace('/\s+/', ' ', (string) $last->exception), 0, 160));
                $bad[] = "실패한 일감이 {$failed}건 있습니다 — 위 사유를 보십시오.";
            }
        }

        // ── 3. 문서 상태
        $this->line('');
        $this->line('<options=bold>3. 문서 상태</>');
        $rows = IntelligentDocument::query()->select('ai_status', DB::raw('count(*) n'))->groupBy('ai_status')->pluck('n', 'ai_status');
        foreach (['queued' => '대기', 'analyzing' => '분석 중', 'ready' => '정리 완료', 'review_required' => '검토 필요', 'failed' => '분석 실패'] as $k => $label) {
            // str_pad 는 바이트로 세서 한글이 섞이면 칸이 안 맞는다 — 글자 수로 채운다.
            $this->line('   '.$label.str_repeat(' ', max(1, 12 - mb_strwidth($label))).($rows[$k] ?? 0).'건');
        }
        $queued = (int) ($rows['queued'] ?? 0);
        if ($queued > 0 && $jobs && $waiting === 0) {
            $bad[] = "«대기» 가 {$queued}건인데 줄에 선 일감은 0건입니다 — 상태만 대기로 바뀌고 일감이 안 들어갔습니다. "
                .'문서함의 «AI 재분석» 또는 docs:reanalyze --run 으로 다시 넣어야 합니다.';
        }

        // ── 4. 멈춘 분석
        $mins = max(15, (int) $this->option('stuck-minutes'));
        $stuck = IntelligentDocument::query()->where('ai_status', 'analyzing')
            ->where('updated_at', '<', now()->subMinutes($mins))->count();
        $this->line('');
        $this->line('<options=bold>4. 멈춘 분석</>');
        $this->line("   {$mins}분 넘게 «분석 중»  {$stuck}건");
        if ($stuck > 0) {
            $bad[] = "«분석 중» 에서 굳은 문서가 {$stuck}건입니다 — 일꾼이 물고 죽은 것입니다. "
                .'문서함의 «멈춘 분석 재시도» 를 누르거나 docs:reap-stuck 을 돌리십시오.';
        }

        // ── 5. 크기·형식으로 못 읽는 문서
        // 한도는 엔진이 말하는 값을 그대로 쓴다 — 진단이 실제 동작과 다른 숫자를 말하면 안 된다.
        $limit = app(OcrEngine::class)->maxAttachmentBytes();
        $big = IntelligentDocument::query()->where('file_size', '>', $limit)
            ->whereIn('ai_status', ['queued', 'analyzing', 'failed'])->get(['original_file_name', 'file_size', 'ai_status']);
        $this->line('');
        $this->line('<options=bold>5. 크기 한도</>');
        $this->line('   AI 직접 판독 한도  '.round($limit / 1048576, 1).'MB   ('.$engine.' 엔진이 말하는 값)');
        $this->line('   한도 초과 미완료 문서  '.$big->count().'건');
        foreach ($big->take(5) as $d) {
            $this->line('     · '.mb_substr($d->original_file_name, 0, 44).'  '.round($d->file_size / 1048576, 1).'MB  ['.$d->ai_status.']');
        }
        if ($big->isNotEmpty()) {
            $bad[] = '한도를 넘는 문서 '.$big->count().'건은 원본을 AI 에 직접 못 보내고 «서버가 뽑은 글자» 에만 의존합니다. '
                .'스캔본이라 글자가 안 뽑히면 실패합니다 — 나눠서 올리거나 한도를 올리십시오.';
        }

        // ── 6. 오류 메시지 모음
        $errs = IntelligentDocument::query()->whereNotNull('ai_error')->orderByDesc('updated_at')->limit(5)->get(['original_file_name', 'ai_error']);
        if ($errs->isNotEmpty()) {
            $this->line('');
            $this->line('<options=bold>6. 최근 오류</>');
            foreach ($errs as $e) {
                $this->line('   · '.mb_substr($e->original_file_name, 0, 36).' → '.mb_substr(preg_replace('/\s+/', ' ', (string) $e->ai_error), 0, 120));
            }
        }

        // ── 결론
        $this->line('');
        if ($bad === []) {
            $this->info('막힌 곳을 찾지 못했습니다. 분석이 정상으로 보입니다.');

            return self::SUCCESS;
        }
        $this->line('<options=bold;fg=red>막힌 곳</>');
        foreach ($bad as $i => $b) {
            $this->line('   '.($i + 1).') '.$b);
        }
        $this->line('');

        return self::SUCCESS;
    }
}
