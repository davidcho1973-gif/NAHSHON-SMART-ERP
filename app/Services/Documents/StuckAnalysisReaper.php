<?php

namespace App\Services\Documents;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntelligentDocument;
use Illuminate\Support\Facades\DB;

/**
 * "AI 분석 중"에 갇힌 문서를 되살리는 규칙 한 곳.
 *
 * 스케줄러(10분마다)와 화면 버튼이 같은 규칙을 쓴다 — 스케줄러가 꺼져 있는 환경에서도
 * 사용자가 직접 되살릴 수 있어야 하고, 두 경로가 다르게 동작하면 안 된다.
 */
class StuckAnalysisReaper
{
    /** 자동 재시도는 한 번만 — 계속 되살리면 죽는 작업을 무한히 반복한다. */
    private const MAX_AUTO_REQUEUE = 1;

    /**
     * @return array{total: int, requeued: int, failed: int}
     */
    public function reap(int $minutes = 15): array
    {
        // Never reclaim a live 600-second analysis, even when a caller requests three minutes.
        $minutes = max(15, $minutes);
        $cutoff = now()->subMinutes($minutes);

        $stuck = IntelligentDocument::query()
            ->whereIn('ai_status', ['analyzing', 'queued'])
            ->where('updated_at', '<', $cutoff)
            ->pluck('id');

        $requeued = 0;
        $failed = 0;

        foreach ($stuck as $id) {
            $result = DB::transaction(function () use ($id, $cutoff, $minutes): ?string {
                $document = IntelligentDocument::query()->whereKey($id)->lockForUpdate()->first();
                if (! $document || ! in_array($document->ai_status, ['analyzing', 'queued'], true)
                    || $document->updated_at->gte($cutoff)) {
                    return null;
                }
                // Waiting behind other documents is not a failed analysis. Only replace a
                // queued request when its durable job is missing (including legacy default jobs).
                if ($document->ai_status === 'queued' && $this->hasPendingJob($document->id)) {
                    return null;
                }

                $payload = (array) ($document->ai_payload ?? []);
                $attempts = (int) ($payload['stuck_requeues'] ?? 0);
                unset($payload['analysis_run_token']);

                if ($attempts < self::MAX_AUTO_REQUEUE) {
                    $payload['stuck_requeues'] = $attempts + 1;
                    $document->update(['ai_status' => 'queued', 'ai_error' => null, 'ai_payload' => $payload]);
                    AnalyzeIntelligentDocumentJob::dispatch($document->id);

                    return 'requeued';
                }

                $document->update([
                    'ai_status' => 'failed',
                    'ai_payload' => $payload,
                    'ai_error' => "분석 요청 뒤 {$minutes}분 넘게 응답이 없어 중단했습니다. "
                    .'파일이 크거나 서버 작업이 중간에 종료됐을 수 있습니다 — "AI 재분석"을 눌러 다시 시도해 주세요.',
                ]);

                return 'failed';
            });
            $requeued += $result === 'requeued' ? 1 : 0;
            $failed += $result === 'failed' ? 1 : 0;
        }

        return ['total' => $requeued + $failed, 'requeued' => $requeued, 'failed' => $failed];
    }

    private function hasPendingJob(int $documentId): bool
    {
        return DB::connection(config('queue.connections.document-analysis.connection'))
            ->table(config('queue.connections.document-analysis.table', 'jobs'))
            ->whereIn('queue', ['documents', 'default'])
            ->whereRaw("payload::jsonb->>'displayName' = ?", [AnalyzeIntelligentDocumentJob::class])
            ->whereRaw("payload::jsonb->'data'->>'command' LIKE ?", ['%"documentId";i:'.$documentId.';%'])
            ->exists();
    }
}
