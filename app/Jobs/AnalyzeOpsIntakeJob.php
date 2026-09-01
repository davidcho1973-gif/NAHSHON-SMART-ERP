<?php

namespace App\Jobs;

use App\Models\OpsIntakeBatch;
use App\Services\Ops\OpsIntakeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 현장 상황실 판독을 "요청 응답 후"에 처리한다(->afterResponse()).
 *
 * 왜 비동기인가: 사진 여러 장을 비전 AI 로 읽으면 수십 초~수 분이 걸린다. 이걸 HTTP 요청
 * 안에서 동기로 돌리면 게이트웨이가 먼저 끊어 504 가 난다. 응답을 먼저 보내고 나면
 * 게이트웨이 시간 제한이 적용되지 않으므로, 판독은 걸리는 만큼 돌 수 있다.
 * (별도 큐 워커 불필요 — 같은 프로세스에서 응답 전송 후 실행된다.)
 */
class AnalyzeOpsIntakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 잡 자체의 최대 실행 시간(초) — 사진 20장까지 여유 있게. */
    public int $timeout = 1800;

    /** 재시도 없음 — 판독은 비멱등(같은 원문에서 항목이 중복 생성됨)이라 실패는 실패로 기록. */
    public int $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(OpsIntakeService $service): void
    {
        $service->analyze($this->batchId);
    }

    public function failed(\Throwable $e): void
    {
        OpsIntakeBatch::where('id', $this->batchId)->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'analyzed_at' => now(),
        ]);

        // 이 기록이 이미 제출된 보고에 묶여 있으면 그 보고의 결과 줄을 다시 센다.
        // 그러지 않으면 「사진 판독 중 — 끝나면 이어서 반영됩니다」가 영원히 남는다.
        $batch = OpsIntakeBatch::find($this->batchId);
        if ($batch) {
            app(OpsIntakeService::class)->reflectIfSubmitted($batch, restampOnly: true);
        }
    }
}
