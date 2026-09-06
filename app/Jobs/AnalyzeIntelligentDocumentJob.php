<?php

namespace App\Jobs;

use App\Models\IntelligentDocument;
use App\Services\Alerts\UnifiedAlertService;
use App\Services\Documents\DocumentIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    // Serialized before execution, so a freshly unserialized timeout hook owns the same run.
    // The default also lets document jobs queued before this property existed deserialize.
    public ?string $analysisToken = null;

    public function __construct(public int $documentId)
    {
        $this->analysisToken = (string) Str::uuid();
        $this->onConnection('document-analysis')->onQueue('documents');
    }

    public static function requestReanalysis(int $documentId): bool
    {
        return DB::transaction(function () use ($documentId): bool {
            $document = IntelligentDocument::query()->whereKey($documentId)->lockForUpdate()->first();
            if (! $document || ! in_array($document->ai_status, ['ready', 'review_required', 'failed'], true)
                || ! empty($document->ai_payload['duplicate_document_id'])) {
                return false;
            }

            // Check and change under the same row lock. A second click must not turn a
            // worker's active run back into queued or erase that run's ownership token.
            $payload = (array) $document->ai_payload;
            unset($payload['stuck_requeues'], $payload['analysis_run_token']);
            $document->update(['ai_status' => 'queued', 'ai_error' => null, 'ai_payload' => $payload]);
            static::dispatch($documentId)->afterCommit();

            return true;
        });
    }

    public function handle(DocumentIntelligenceService $service, UnifiedAlertService $alerts): void
    {
        $document = $this->claim();
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
            if (! $this->markFailed($e)) {
                return;
            }

            // The failed save may have left project/title attributes dirty on the old instance.
            $document->refresh();
            try {
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
                    'content' => $this->errorMessage($e),
                    'action_url' => '/document-hub?document='.$document->id,
                ]);
            } catch (\Throwable $alertError) {
                // Alert delivery must never replace the actual analysis failure or escape the job.
                report($alertError);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->markFailed($e);
    }

    private function claim(): ?IntelligentDocument
    {
        return DB::transaction(function (): ?IntelligentDocument {
            $document = IntelligentDocument::query()->whereKey($this->documentId)->lockForUpdate()->first();
            // Old/duplicate queued jobs must not reprocess completed documents or another worker's run.
            if (! $document || $document->ai_status !== 'queued' || ! $this->runToken()) {
                return null;
            }

            $payload = (array) ($document->ai_payload ?? []);
            $payload['analysis_run_token'] = $this->runToken();
            $document->update(['ai_status' => 'analyzing', 'ai_error' => null, 'ai_payload' => $payload]);

            return $document;
        });
    }

    private function runToken(): ?string
    {
        return $this->analysisToken ?? $this->job?->uuid();
    }

    private function markFailed(\Throwable $e): bool
    {
        if (! $token = $this->runToken()) {
            return false;
        }

        // A rolled-back model can still contain dirty conflicting attributes. Update only status,
        // and only if this run still owns the row (a reaper/new run may already have replaced it).
        return IntelligentDocument::query()->whereKey($this->documentId)
            ->where('ai_status', 'analyzing')
            ->where('ai_payload->analysis_run_token', $token)
            ->update([
                'ai_status' => 'failed',
                'ai_error' => $this->errorMessage($e),
            ]) === 1;
    }

    private function errorMessage(\Throwable $e): string
    {
        // Full provider/SQL errors are reported to server logs, never copied into documents/alerts.
        if ($e instanceof QueryException) {
            return (string) $e->getCode() === '23505'
                ? '[DUPLICATE_SCOPE] 같은 프로젝트에 동일한 파일이 있어 분석 결과를 저장하지 못했습니다. 기존 문서와 프로젝트 귀속을 확인해 주세요.'
                : '[SAVE_FAILED] 분석 결과 저장에 실패했습니다. 서버 오류 기록을 확인한 뒤 다시 시도해 주세요.';
        }

        $message = mb_strtolower($e->getMessage());
        if ($e instanceof TimeoutExceededException || str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return '[ANALYSIS_TIMEOUT] 문서 분석 시간이 제한을 초과했습니다. 파일 상태를 확인한 뒤 AI 재분석을 실행해 주세요.';
        }
        if (str_starts_with($e->getMessage(), '업로드된 원본 파일을 찾을 수 없습니다')) {
            return '[SOURCE_MISSING] 업로드된 원본 파일을 찾을 수 없습니다. 같은 파일을 다시 올려 원본을 복원해 주세요.';
        }
        if (str_starts_with($e->getMessage(), '이 파일은 서버에서 본문을 추출할 수 없')) {
            return '[UNREADABLE_SOURCE] 파일의 본문을 읽을 수 없습니다. 파일 형식과 AI 판독 용량 한도를 확인하거나 PDF로 변환해 주세요.';
        }
        if (str_contains($message, '429') || str_contains($message, 'quota') || str_contains($message, 'rate limit')) {
            return '[AI_RATE_LIMIT] AI 서비스의 처리 한도에 도달했습니다. 사용 한도를 확인한 뒤 다시 시도해 주세요.';
        }

        return '[ANALYSIS_FAILED] 문서 분석을 완료하지 못했습니다. 서버 오류 기록에서 원인을 확인한 뒤 다시 시도해 주세요.';
    }
}
