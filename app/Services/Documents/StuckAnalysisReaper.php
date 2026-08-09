<?php

namespace App\Services\Documents;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntelligentDocument;

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
        $minutes = max(3, $minutes);

        $stuck = IntelligentDocument::query()
            ->whereIn('ai_status', ['analyzing', 'queued'])
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        $requeued = 0;
        $failed = 0;

        foreach ($stuck as $document) {
            $payload = (array) ($document->ai_payload ?? []);
            $attempts = (int) ($payload['stuck_requeues'] ?? 0);

            if ($attempts < self::MAX_AUTO_REQUEUE) {
                $payload['stuck_requeues'] = $attempts + 1;
                $document->update(['ai_status' => 'queued', 'ai_error' => null, 'ai_payload' => $payload]);
                AnalyzeIntelligentDocumentJob::dispatch($document->id);
                $requeued++;

                continue;
            }

            $document->update([
                'ai_status' => 'failed',
                'ai_error' => "분석이 시작된 뒤 {$minutes}분 넘게 응답이 없어 중단했습니다. "
                    .'파일이 크거나 서버 작업이 중간에 종료됐을 수 있습니다 — "AI 재분석"을 눌러 다시 시도해 주세요.',
            ]);
            $failed++;
        }

        return ['total' => $stuck->count(), 'requeued' => $requeued, 'failed' => $failed];
    }
}
