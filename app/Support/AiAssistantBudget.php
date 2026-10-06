<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * One fail-closed budget for private Ask and queued room replies.
 *
 * Root cause: an after-the-call, fail-open meter cannot enforce spend limits, and
 * ambient auth belongs to the HTTP request, not necessarily to a queue's actor.
 * Reserve under the company's database row lock before one bounded provider call.
 * Failed/uncertain calls retain their slot, so timeouts and retries cannot erase spend.
 */
final class AiAssistantBudget
{
    private const LIMITS = [
        'company_day' => 'company_daily_requests',
        'company_month' => 'company_monthly_requests',
        'user_day' => 'user_daily_requests',
        'user_month' => 'user_monthly_requests',
    ];

    public function enabled(): bool
    {
        return (bool) config('ai_assistant.enabled', true);
    }

    /** Resolve from the supplied actor and explicit scope, never auth()/session(). */
    public function companyId(User $actor, ?int $requested = null): ?int
    {
        if (! $actor->exists || $actor->account_status !== 'active' || ! array_key_exists($actor->access_role, User::ROLE_OPTIONS)) {
            return null;
        }

        $id = $requested ?? $actor->allowed_company_id ?? $actor->employee?->company_id;
        if (! $id) {
            $memberships = $actor->companies()->where('companies.status', 'active')->get();
            $id = $memberships->firstWhere('pivot.is_default', true)?->id
                ?? ($memberships->count() === 1 ? $memberships->first()->id : null);
        }
        if (! $id && AccessPolicy::canManageSystem($actor)) {
            $ids = Company::query()->where('status', 'active')->limit(2)->pluck('id');
            $id = $ids->count() === 1 ? $ids->first() : null;
        }
        if (! $id || ! Company::query()->whereKey($id)->where('status', 'active')->exists()
            || ! AccessPolicy::canSeeCompany($actor, (int) $id)) {
            return null;
        }

        $belongs = AccessPolicy::canManageSystem($actor)
            || (int) $actor->allowed_company_id === (int) $id
            || (int) $actor->employee?->company_id === (int) $id
            || $actor->companies()->where('companies.id', $id)->where('companies.status', 'active')->exists();

        return $belongs ? (int) $id : null;
    }

    /**
     * Read-only UI status. Reservations are counted regardless of completion status.
     * User limits are per company; company limits include every actor and both surfaces.
     *
     * @return array<string, mixed>
     */
    public function status(User $actor, ?int $companyId = null): array
    {
        try {
            return $this->snapshot($actor, $this->companyId($actor, $companyId));
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /**
     * The callback must make exactly one provider call and use the supplied payload.
     * Do not retry or issue a fallback call inside it. Every retry needs a new reservation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function run(User $actor, ?int $companyId, string $feature, array $payload, callable $call): mixed
    {
        if (! in_array($feature, ['document_ask', 'chat_ask', 'draft_suggestion'], true)) {
            throw new DomainException('지원하지 않는 AI 요청입니다.');
        }
        if (! $this->enabled()) {
            throw new DomainException('AI 도우미가 이 서버에 켜져 있지 않습니다.');
        }

        $maxInput = $this->bound('max_input_bytes', 64000);
        $maxOutput = $this->bound('max_output_tokens', 1200);
        $payload['max_tokens'] = min(max(0, (int) ($payload['max_tokens'] ?? 0)), $maxOutput);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $inputBytes = is_string($encoded) ? strlen($encoded) : 0;
        if ($maxInput === 0 || $payload['max_tokens'] === 0 || $inputBytes === 0 || $inputBytes > $maxInput) {
            throw new DomainException('질문과 참고 자료가 AI 요청 한도를 넘었습니다. 현장이나 질문 범위를 줄여 주세요.');
        }

        try {
            // Re-read the persisted actor. A queued model may predate a suspension or reassignment.
            $currentActor = $actor->fresh(['employee']);
            $companyId = $currentActor ? $this->companyId($currentActor, $companyId) : null;
            if (! $currentActor || ! $companyId
                || AiInformationAccess::context($currentActor) !== AiInformationAccess::context($actor)) {
                throw new DomainException('AI를 사용할 회사 또는 계정 권한을 확인할 수 없습니다.');
            }

            $id = DB::transaction(function () use ($currentActor, $companyId, $feature, $inputBytes, $payload): int {
                // All reservations for this company serialize here; count + insert is atomic.
                $company = DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
                if (! $company || $company->status !== 'active') {
                    throw new DomainException('AI를 사용할 회사가 비활성 상태입니다.');
                }
                $status = $this->snapshot($currentActor, $companyId);
                if (! $status['allowed']) {
                    throw new DomainException($status['message']);
                }

                return DB::table('ai_assistant_requests')->insertGetId([
                    'company_id' => $companyId,
                    'user_id' => $currentActor->id,
                    'feature' => $feature,
                    'input_bytes' => $inputBytes,
                    'max_output_tokens' => $payload['max_tokens'],
                    'status' => 'reserved',
                    'reserved_at' => Carbon::now('UTC')->toIso8601String(),
                ]);
            }, 3);
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable) {
            // Storage failure must never become an unmetered call or leak SQL/bindings.
            throw new DomainException($this->unavailable()['message']);
        }

        try {
            $result = $call($payload);
        } catch (Throwable) {
            $this->complete($id, 'failed');
            // Provider exception bodies can echo private prompts or credentials.
            throw new RuntimeException('AI provider request failed.');
        }
        $this->complete($id, 'succeeded');

        return $result;
    }

    /** @return array<string, mixed> */
    private function snapshot(User $actor, ?int $companyId): array
    {
        $now = Carbon::now('UTC');
        $enabled = $this->enabled() && (bool) config('ai_assistant.companies.'.$companyId.'.enabled', true);
        $status = [
            'enabled' => $enabled,
            'allowed' => false,
            'reason' => $enabled ? 'company_unavailable' : 'disabled',
            'message' => $enabled ? 'AI를 사용할 회사 또는 계정 권한을 확인할 수 없습니다.' : 'AI 도우미가 이 회사에 켜져 있지 않습니다.',
            'company_id' => $companyId,
            'requests' => [],
            'resets_at' => [
                'day' => $now->copy()->addDay()->startOfDay()->toIso8601String(),
                'month' => $now->copy()->addMonthNoOverflow()->startOfMonth()->toIso8601String(),
            ],
            'max_input_bytes' => $this->bound('max_input_bytes', 64000),
            'max_output_tokens' => $this->bound('max_output_tokens', 1200),
        ];
        if (! $enabled || ! $companyId) {
            return $status;
        }

        if (! Schema::hasTable('ai_assistant_requests')) {
            return $this->unavailable();
        }

        $base = DB::table('ai_assistant_requests')->where('company_id', $companyId)
            ->where('reserved_at', '>=', $now->copy()->startOfMonth()->toIso8601String());
        $used = [
            'company_day' => (clone $base)->where('reserved_at', '>=', $now->copy()->startOfDay()->toIso8601String())->count(),
            'company_month' => (clone $base)->count(),
            'user_day' => (clone $base)->where('user_id', $actor->id)->where('reserved_at', '>=', $now->copy()->startOfDay()->toIso8601String())->count(),
            'user_month' => (clone $base)->where('user_id', $actor->id)->count(),
        ];
        $status['allowed'] = true;
        $status['reason'] = null;
        $status['message'] = null;
        foreach (self::LIMITS as $key => $config) {
            $limit = max(0, (int) config('ai_assistant.companies.'.$companyId.'.'.$config, config('ai_assistant.'.$config, 0)));
            $status['requests'][$key] = ['used' => $used[$key], 'limit' => $limit, 'remaining' => max(0, $limit - $used[$key])];
            if ($used[$key] >= $limit && $status['allowed']) {
                $status['allowed'] = false;
                $status['reason'] = $key.'_limit';
                $owner = str_starts_with($key, 'company') ? '회사' : '개인';
                $period = str_ends_with($key, 'day') ? '오늘' : '이번 달';
                $status['message'] = $period.' '.$owner.' AI 질문 한도를 모두 사용했습니다. 관리자에게 문의해 주세요.';
            }
        }

        return $status;
    }

    private function bound(string $key, int $default): int
    {
        return max(0, (int) config('ai_assistant.'.$key, $default));
    }

    private function complete(int $id, string $status): void
    {
        try {
            DB::table('ai_assistant_requests')->where('id', $id)->where('status', 'reserved')
                ->update(['status' => $status, 'completed_at' => Carbon::now('UTC')->toIso8601String()]);
        } catch (Throwable) {
            // The durable reservation still counts. Never refund an uncertain request.
        }
    }

    /** @return array<string, mixed> */
    private function unavailable(): array
    {
        return ['enabled' => $this->enabled(), 'allowed' => false, 'reason' => 'storage_unavailable',
            'message' => 'AI 사용 한도를 확인할 수 없습니다. 관리자에게 알려 주세요.', 'company_id' => null,
            'requests' => [], 'resets_at' => [], 'max_input_bytes' => $this->bound('max_input_bytes', 64000),
            'max_output_tokens' => $this->bound('max_output_tokens', 1200)];
    }
}
