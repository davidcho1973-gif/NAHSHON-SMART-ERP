<?php

namespace App\Jobs;

use App\Models\DocumentEvidenceBatch;
use App\Models\IntelligentDocument;
use App\Services\Documents\DocumentEvidenceBatchService;
use App\Services\Finance\ClaimEvidenceInboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Match only this explicitly started intake; do not enable contract-wide periodic scans. */
class MatchDocumentEvidenceBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $batchId, public string $runToken, public int $cursor)
    {
        $this->onConnection('document-analysis')->onQueue('documents');
    }

    public function handle(DocumentEvidenceBatchService $batches, ClaimEvidenceInboxService $inbox): void
    {
        $previous = Auth::user();
        try {
            $batch = DocumentEvidenceBatch::find($this->batchId);
            if (! $batch || $batch->status !== 'matching' || $batch->run_token !== $this->runToken || $batch->match_cursor !== $this->cursor || $batch->match_inflight) {
                return;
            }
            Auth::setUser($batches->actorForJob($this->batchId, $this->runToken));
            $batches->accessible(Auth::user(), $this->batchId);
            $claimed = DB::transaction(function () {
                $batch = DocumentEvidenceBatch::whereKey($this->batchId)->lockForUpdate()->firstOrFail();
                if ($batch->status !== 'matching' || $batch->run_token !== $this->runToken || $batch->match_cursor !== $this->cursor || $batch->match_inflight) {
                    return false;
                }
                $batch->update(['match_inflight' => true]);

                return true;
            });
            if (! $claimed) {
                return;
            }
            $ids = $batch->items()->orderBy('id')->skip($this->cursor)->take(20)->pluck('document_id')->map(fn ($id) => (int) $id)->all();
            if (IntelligentDocument::visibleTo(Auth::user())->whereIn('id', $ids)->whereIn('ai_status', ['queued', 'analyzing'])->exists()) {
                // Another explicitly started intake may own the same canonical original.
                // Wait for that durable job without running AI twice or calling it complete.
                DocumentEvidenceBatch::whereKey($this->batchId)->where('run_token', $this->runToken)
                    ->where('match_cursor', $this->cursor)->update(['match_inflight' => false]);
                static::dispatch($this->batchId, $this->runToken, $this->cursor)->delay(now()->addSeconds(30));

                return;
            }
            if ($ids !== []) {
                $result = $inbox->match((int) $batch->project_contract_id, $ids);
                if (! ($result['success'] ?? false)) {
                    throw new \RuntimeException('Evidence batch matching did not complete.');
                }
            }
            DB::transaction(function () use ($ids) {
                $batch = DocumentEvidenceBatch::whereKey($this->batchId)->lockForUpdate()->firstOrFail();
                if ($batch->run_token !== $this->runToken || $batch->status !== 'matching' || $batch->match_cursor !== $this->cursor) {
                    return;
                }
                $cursor = $this->cursor + count($ids);
                $more = $batch->items()->count() > $cursor;
                $batch->update(['match_cursor' => $cursor, 'match_inflight' => false,
                    'status' => $more ? 'matching' : 'done', 'finished_at' => $more ? null : now(), 'error' => null]);
                if ($more) {
                    static::dispatch($this->batchId, $this->runToken, $cursor)->afterCommit();
                }
            });
        } catch (\Throwable $e) {
            report($e);
            $this->failed($e);
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    public function failed(?\Throwable $e): void
    {
        app(DocumentEvidenceBatchService::class)->fail($this->batchId, $this->runToken,
            '공정 연결을 완료하지 못했습니다. 원본과 분석 결과는 보존했습니다. 권한을 확인한 뒤 다시 연결해 주세요.');
    }
}
