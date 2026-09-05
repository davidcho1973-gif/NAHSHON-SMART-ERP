<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntelligentDocument;
use Illuminate\Console\Command;

/**
 * 이미 분석이 끝난 문서를 다시 분석한다.
 *
 * 왜 필요한가: 분석 결과는 한 번 만들어지면 그대로 굳는다. 그런데 프롬프트가 바뀌면
 * (예: «영문 뒤에 한국어를 붙여라») 새 문서만 새 규칙을 따르고 옛 문서는 옛 모습으로 남는다.
 * 화면에서 어떤 줄은 한글이 있고 어떤 줄은 없어, 읽는 사람은 «왜 이건 없지» 를 먼저 묻게 된다.
 * 문서함의 «AI 재분석» 단추는 한 건씩이라 수십 건에는 쓸 수 없다.
 *
 * 조심할 것: 분석은 바깥 모델을 부르므로 건당 비용과 시간이 든다. 그래서 기본은 미리보기이고,
 * 실제로 돌리려면 --run 을 준다. 한 번에 쏟아부으면 큐가 막히므로 --limit 로 나눠 돌린다.
 */
class ReanalyzeDocuments extends Command
{
    protected $signature = 'docs:reanalyze
        {--project= : 프로젝트 코드 (예: 703K-KITCHEN)}
        {--status=* : 이 상태인 것만 (ready·review_required·failed). 생략하면 분석이 끝난 것 전부}
        {--missing-korean : 한글이 안 섞인 것만 — 프롬프트를 바꾼 뒤 옛 결과만 고를 때}
        {--limit=50 : 한 번에 보낼 건수}
        {--run : 실제로 큐에 넣는다 (없으면 미리보기)}';

    protected $description = '분석이 끝난 문서를 다시 분석한다 — 프롬프트를 바꾼 뒤 옛 결과를 새 규칙으로 맞출 때';

    public function handle(): int
    {
        $q = IntelligentDocument::query()
            ->whereIn('ai_status', $this->option('status') ?: ['ready', 'review_required', 'failed']);

        if ($code = $this->option('project')) {
            $q->whereHas('project', fn ($p) => $p->where('project_code', $code));
        }

        // 한글이 한 글자도 없는 요약 = 옛 프롬프트로 만들어진 결과다.
        if ($this->option('missing-korean')) {
            $q->where(fn ($w) => $w->whereNull('summary')->orWhere('summary', '!~', '[가-힣]'));
        }

        $total = (clone $q)->count();
        $docs = $q->orderBy('id')->limit((int) $this->option('limit'))->get(['id', 'original_file_name', 'ai_status']);

        if ($docs->isEmpty()) {
            $this->info('대상이 없습니다.');

            return self::SUCCESS;
        }

        $this->line("대상 {$total}건 중 {$docs->count()}건".($this->option('run') ? ' — 큐에 넣습니다' : ' — 미리보기(실행하려면 --run)'));
        foreach ($docs as $d) {
            $this->line(sprintf('  %5d  %-12s %s', $d->id, $d->ai_status, mb_substr($d->original_file_name, 0, 56)));
            if ($this->option('run')) {
                AnalyzeIntelligentDocumentJob::dispatch($d->id);
            }
        }

        if ($this->option('run')) {
            $this->newLine();
            $this->info("{$docs->count()}건을 분석 큐에 넣었습니다. 남은 대상 ".max(0, $total - $docs->count()).'건.');
        }

        return self::SUCCESS;
    }
}
