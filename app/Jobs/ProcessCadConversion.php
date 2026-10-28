<?php

namespace App\Jobs;

use App\Models\CadConversion;
use App\Services\Drawings\CadConversionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCadConversion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 660;

    public bool $failOnTimeout = true;

    public function __construct(public string $conversionId, public int $attempt = 1)
    {
        $this->onConnection('cad-conversion')->onQueue('cad-conversion')->beforeCommit();
    }

    public function handle(CadConversionService $service): void
    {
        if (! app()->runningInConsole() || config('cad.worker_active') !== true) {
            throw new \RuntimeException('Use cad:work on the isolated CAD worker cluster.');
        }
        $service->process($this->conversionId, $this->attempt);
    }

    public function failed(?\Throwable $exception): void
    {
        CadConversion::whereKey($this->conversionId)->where('attempt', $this->attempt)
            ->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'finished_at' => now(),
                'error' => '전용 변환 작업이 중단됐습니다. 원본은 보존되어 있으며 재시도할 수 있습니다.']);
    }
}
