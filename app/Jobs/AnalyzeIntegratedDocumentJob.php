<?php

namespace App\Jobs;

use App\Models\IntegratedDocument;
use App\Services\IntegratedDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 문서통합관리 AI 자동분석을 "요청 응답 후"에 처리한다(->afterResponse()).
 *
 * WBS 매뉴얼 분석과 같은 이유: AI 문서 분석은 수십 초 걸릴 수 있어 동기 처리 시 게이트웨이 504 위험.
 * 컨트롤러가 즉시 202 를 돌려주고, 실제 분석은 응답 후 같은 프로세스에서 진행(큐 워커 불필요),
 * 프론트는 문서 상태를 폴링한다.
 */
class AnalyzeIntegratedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $documentId)
    {
    }

    public function handle(IntegratedDocumentService $service): void
    {
        $doc = IntegratedDocument::find($this->documentId);
        if (! $doc) {
            return;
        }

        // 백그라운드 경로 — AI 호출 타임아웃을 넉넉히.
        config([
            'services.gemini.timeout' => max(120, (int) config('services.gemini.timeout')),
            'services.anthropic.timeout' => max(180, (int) config('services.anthropic.timeout')),
        ]);

        $service->analyzeAndClassify($doc);
    }

    public function failed(\Throwable $e): void
    {
        IntegratedDocument::where('id', $this->documentId)
            ->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
