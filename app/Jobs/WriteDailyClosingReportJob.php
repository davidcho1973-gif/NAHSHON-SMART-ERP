<?php

namespace App\Jobs;

use App\Models\DailyClosingReport;
use App\Services\Ops\DailyClosingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 일일 마감 보고서 작성을 응답 후로 미룬다 — 집계 + AI 서술이 길어져도 화면이 멈추지 않게.
 */
class WriteDailyClosingReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $reportId) {}

    public function handle(DailyClosingService $service): void
    {
        $service->write($this->reportId);
    }

    public function failed(\Throwable $e): void
    {
        DailyClosingReport::where('id', $this->reportId)
            ->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
