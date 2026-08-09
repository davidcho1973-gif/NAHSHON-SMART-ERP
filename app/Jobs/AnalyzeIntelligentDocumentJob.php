<?php

namespace App\Jobs;

use App\Models\IntelligentDocument;
use App\Services\Alerts\UnifiedAlertService;
use App\Services\Documents\DocumentIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeIntelligentDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * 시간 초과도 "실패"로 기록되게 한다.
     *
     * 이게 없으면 제한 시간을 넘긴 작업이 조용히 죽고, 문서는 'analyzing'(AI 분석 중)에
     * 영원히 머문다 — 화면에서는 계속 도는 것처럼 보이는데 실제로는 아무도 일하지 않는다.
     */
    public bool $failOnTimeout = true;

    public function __construct(public int $documentId) {}

    public function handle(DocumentIntelligenceService $service, UnifiedAlertService $alerts): void
    {
        $document = IntelligentDocument::query()->find($this->documentId);
        if (! $document) {
            return;
        }

        config([
            'services.gemini.timeout' => max((int) config('document-intelligence.analysis_timeout', 180), (int) config('services.gemini.timeout')),
            'services.anthropic.timeout' => max(240, (int) config('services.anthropic.timeout')),
        ]);

        try {
            $service->process($document);
        } catch (\Throwable $e) {
            report($e);
            $document->update([
                'ai_status' => 'failed',
                'ai_error' => $e->getMessage(),
            ]);

            $alerts->emit('document-analysis-failed:'.$document->id, [
                'company_id' => $document->company_id,
                'site_id' => $document->site_id,
                'project_id' => $document->project_id,
                'user_id' => $document->uploaded_by,
                'source_module' => 'DOC',
                'source_type' => IntelligentDocument::class,
                'source_id' => (string) $document->id,
                'event_type' => 'analysis_failed',
                'severity' => 'warning',
                'title' => '문서 AI 분석 실패: '.$document->original_file_name,
                'content' => $e->getMessage(),
                'action_url' => '/document-hub?document='.$document->id,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        IntelligentDocument::query()->whereKey($this->documentId)->update([
            'ai_status' => 'failed',
            'ai_error' => $e->getMessage(),
        ]);
    }
}
