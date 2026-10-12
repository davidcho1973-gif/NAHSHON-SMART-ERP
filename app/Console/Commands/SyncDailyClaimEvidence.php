<?php

namespace App\Console\Commands;

use App\Models\ProjectContract;
use App\Models\User;
use App\Services\Finance\ClaimEvidenceAutomation;
use App\Services\Admin\BillingAdminService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class SyncDailyClaimEvidence extends Command
{
    protected $signature = 'claims:sync-evidence {--user= : Explicit authorized actor for initial activation} {--contract= : Single contract}';
    protected $description = 'Queue daily evidence filing under the recorded billing manager scope';

    public function handle(): int
    {
        $previous = Auth::user();
        $requestedActor = $this->option('user') ? User::find((int) $this->option('user')) : null;
        if ($this->option('user') && (! $requestedActor || ! app(BillingAdminService::class)->canManage($requestedActor))) {
            $this->error('An active billing manager is required.');
            return self::FAILURE;
        }
        $query = ProjectContract::where('direction', 'receivable')->whereIn('id', \App\Models\ContractBoqLine::select('project_contract_id'))
            ->when($this->option('contract'), fn ($q) => $q->whereKey((int) $this->option('contract')));
        if (! $requestedActor) {
            $query->where('payload->dailyEvidenceAutomation->enabled', true);
        }
        $queued = 0;
        try {
            foreach ($query->orderBy('updated_at')->cursor() as $contract) {
                $actor = $requestedActor ?: User::find((int) data_get($contract->payload, 'dailyEvidenceAutomation.actorId'));
                if (! $actor || ! app(BillingAdminService::class)->canManage($actor)) {
                    continue;
                }
                Auth::setUser($actor);
                $result = app(ClaimEvidenceAutomation::class)->enqueue($contract->id);
                $queued += (int) ($result['success'] ?? false);
                if ($queued >= 20) {
                    break;
                }
            }
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
        $this->info('Evidence contracts queued: '.$queued);
        return self::SUCCESS;
    }
}
