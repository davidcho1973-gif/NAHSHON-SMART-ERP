<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\User;
use App\Services\Procurement\PurchaseDraftAnalyzer;
use App\Support\PurchaseAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RunPurchaseAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $jobId) {}

    public function handle(PurchaseDraftAnalyzer $analyzer): void
    {
        $job = AiJob::find($this->jobId);
        if (! $job || $job->kind !== 'purchase_draft'
            || ! AiJob::whereKey($job->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()])) {
            return;
        }
        $previous = Auth::user();
        try {
            $user = User::find($job->user_id);
            abort_unless($user && (PurchaseAccess::canRequest($user) || PurchaseAccess::canBuy($user)), 403);
            PurchaseAccess::assertSite($user, (int) ($job->params['site_id'] ?? 0));
            if (($job->params['mode'] ?? '') === 'order') {
                abort_unless(PurchaseAccess::canBuy($user), 403);
            }
            Auth::setUser($user);
            $result = $analyzer->analyze($job);
            if (! ($result['success'] ?? false)) {
                throw new \RuntimeException('Purchase analysis did not complete successfully.');
            }
            AiJob::whereKey($job->id)->where('status', 'running')->update([
                'status' => 'done', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'finished_at' => now(), 'error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->failed($e);
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    public function failed(?Throwable $e): void
    {
        AiJob::whereKey($this->jobId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error' => '분석을 완료하지 못했습니다. 권한과 AI 연결을 확인한 후 다시 분석하세요. 원본은 보존되어 있습니다.', 'finished_at' => now(),
        ]);
    }
}
