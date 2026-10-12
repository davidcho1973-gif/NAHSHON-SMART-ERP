<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Finance\ClaimEvidenceAutomation;
use App\Services\Finance\ClaimEvidenceInboxService;
use App\Services\Admin\BillingAdminService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class MatchDailyClaimEvidence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public int $contractId, public int $actorId, public string $runToken) {}

    public function handle(ClaimEvidenceInboxService $inbox): void
    {
        $previous = Auth::user();
        try {
            $actor = User::find($this->actorId);
            if (! $actor || ! app(BillingAdminService::class)->canManage($actor)) {
                throw new \RuntimeException('Evidence automation actor no longer has billing permission.');
            }
            Auth::setUser($actor);
            if (! app(BillingAdminService::class)->findAccessibleContract($this->contractId)) {
                throw new \RuntimeException('Evidence automation contract scope changed.');
            }
            if (! ClaimEvidenceAutomation::update($this->contractId, $this->runToken, ['state' => 'running', 'startedAt' => now()->toIso8601String()], 'queued')) {
                return;
            }
            $result = $inbox->match($this->contractId);
            if (! ($result['success'] ?? false)) {
                throw new \RuntimeException($result['error'] ?? 'Evidence matching failed.');
            }
            ClaimEvidenceAutomation::update($this->contractId, $this->runToken, [
                'state' => 'done', 'finishedAt' => now()->toIso8601String(), 'error' => null,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $this->failed($e);
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    public function failed(?\Throwable $e): void
    {
        ClaimEvidenceAutomation::update($this->contractId, $this->runToken, [
            'state' => 'failed', 'finishedAt' => now()->toIso8601String(),
            'error' => '자동 연결을 완료하지 못했습니다. 원본은 보존되어 있습니다. 권한과 분석 연결을 확인하세요.',
        ]);
    }
}
