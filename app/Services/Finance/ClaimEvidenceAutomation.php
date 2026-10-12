<?php

namespace App\Services\Finance;

use App\Jobs\MatchDailyClaimEvidence;
use App\Models\ProjectContract;
use App\Services\Admin\BillingAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Queued evidence filing never changes work quantities, claims or approvals. */
class ClaimEvidenceAutomation
{
    public function workspace(int $contractId, array $filters = []): array
    {
        $result = app(ClaimEvidenceInboxService::class)->getInbox($contractId, $filters);
        if ($result['success'] ?? false) {
            $state = ProjectContract::find($contractId)?->payload['dailyEvidenceAutomation'] ?? [];
            $result['processing'] = in_array($state['state'] ?? '', ['queued', 'running'], true)
                && ($state['queuedAt'] ?? '') > now()->subMinutes(20)->toIso8601String();
            $result['processingMessage'] = $result['processing'] ? '서버에서 증빙을 연결하고 있습니다.' : ($state['error'] ?? '');
            $result['lastMatchedAt'] = $state['finishedAt'] ?? null;
            $result['automationEnabled'] = (bool) ($state['enabled'] ?? false);
        }
        return $result;
    }

    public function enqueue(int $contractId): array
    {
        $billing = app(BillingAdminService::class);
        $contract = $billing->canManage() ? $billing->findAccessibleContract($contractId) : null;
        if (! $contract || $contract->direction !== 'receivable') {
            return ['success' => false, 'error' => '접근 가능한 수주 계약과 기성 관리 권한이 필요합니다.'];
        }
        $token = DB::transaction(function () use ($contract): ?string {
            $contract = ProjectContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $state = $contract->payload['dailyEvidenceAutomation'] ?? [];
            if (in_array($state['state'] ?? '', ['queued', 'running'], true)
                && ($state['queuedAt'] ?? '') > now()->subMinutes(20)->toIso8601String()) {
                return null;
            }
            $token = (string) Str::uuid();
            $state = array_merge($state, ['enabled' => true, 'actorId' => auth()->id(), 'state' => 'queued',
                'runToken' => $token, 'queuedAt' => now()->toIso8601String(), 'error' => null]);
            $contract->update(['payload' => array_merge($contract->payload ?? [], ['dailyEvidenceAutomation' => $state])]);
            return $token;
        });
        if ($token) {
            try {
                MatchDailyClaimEvidence::dispatch($contractId, (int) auth()->id(), $token)
                    ->onConnection('document-analysis')->onQueue('claim-evidence');
            } catch (\Throwable $e) {
                self::update($contractId, $token, ['state' => 'failed', 'error' => '증빙 연결 작업을 예약하지 못했습니다. 다시 시도하세요.']);
                report($e);
                return ['success' => false, 'error' => '증빙 연결 작업을 예약하지 못했습니다. 원본은 보존되어 있습니다.'];
            }
        }
        return ['success' => true, 'processing' => true, 'message' => '서버에서 증빙을 연결합니다. 화면을 닫아도 계속 처리됩니다.'];
    }

    public static function update(int $contractId, string $token, array $changes, ?string $expectedState = null): bool
    {
        return DB::transaction(function () use ($contractId, $token, $changes, $expectedState): bool {
            $contract = ProjectContract::whereKey($contractId)->lockForUpdate()->first();
            $state = $contract?->payload['dailyEvidenceAutomation'] ?? [];
            if (! $contract || ($state['runToken'] ?? null) !== $token
                || ($expectedState !== null && ($state['state'] ?? null) !== $expectedState)) {
                return false;
            }
            $contract->update(['payload' => array_merge($contract->payload ?? [], [
                'dailyEvidenceAutomation' => array_merge($state, $changes),
            ])]);
            return true;
        });
    }
}
