<?php

namespace App\Console\Commands;

use App\Services\Documents\StuckAnalysisReaper;
use Illuminate\Console\Command;

/**
 * "AI 분석 중"에서 멈춘 문서를 되살린다.
 *
 * 분석은 시작할 때 상태를 'analyzing' 으로 바꾼다. 그런데 작업 프로세스가 메모리 초과나
 * 배포 재시작으로 죽으면 그 상태를 되돌릴 사람이 없다 — 문서는 영원히 "AI 분석 중"에
 * 머물고, 화면에서는 계속 도는 것처럼 보이는데 실제로는 아무도 일하지 않는다.
 * 사용자가 할 수 있는 일은 하나씩 열어 "재분석"을 누르는 것뿐이었다.
 *
 * 규칙:
 *   - 멈춘 지 오래된 문서는 한 번 자동으로 다시 큐에 넣는다(일시적 사고일 수 있으므로)
 *   - 그래도 또 멈추면 '실패'로 표시하고 이유를 남긴다 — 도는 척하는 것보다 낫다
 */
class ReapStuckDocumentAnalyses extends Command
{
    protected $signature = 'docs:reap-stuck {--minutes=15 : 이 시간(분) 넘게 진행이 없으면 멈춘 것으로 본다}';

    protected $description = 'AI 분석 중/대기에서 멈춘 문서를 재시도하거나 실패로 정리';

    public function handle(StuckAnalysisReaper $reaper): int
    {
        $r = $reaper->reap((int) $this->option('minutes'));

        $this->info("멈춘 문서 {$r['total']}건: 재시도 {$r['requeued']} · 실패 처리 {$r['failed']}");

        return self::SUCCESS;
    }
}
