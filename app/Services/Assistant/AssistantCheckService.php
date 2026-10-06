<?php

namespace App\Services\Assistant;

use App\Mcp\Read\ErpReadContext;
use App\Mcp\Read\ErpReadQuery;
use App\Models\AssistantCheck;
use App\Models\Site;
use App\Models\User;
use App\Support\AiInformationAccess;
use App\Support\SiteClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Deterministic checks: private results only, no AI spend, email, push or shared-room delivery. */
final class AssistantCheckService
{
    public const KINDS = ['overdue_wbs' => '지연 공정', 'overdue_procurement' => '조달 납기 경과', 'expiring_documents' => '30일 내 문서 만료'];

    public function save(User $actor, array $input): AssistantCheck
    {
        $input = Validator::make($input, [
            'site_id' => ['required', 'integer', 'min:1'], 'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'interval_hours' => ['required', Rule::in([1, 6, 24])],
        ])->validate();
        [$actor, $site] = $this->authorize($actor, (int) $input['site_id'], $input['kind']);
        $existing = AssistantCheck::query()->where('user_id', $actor->id)->where('site_id', $site->id)->where('kind', $input['kind'])->first();
        abort_if(! $existing && AssistantCheck::query()->where('user_id', $actor->id)->count() >= 20, 422, '정기 확인은 20개까지 저장할 수 있습니다.');

        // Editing an interval always withdraws prior activation; it needs a new explicit approval.
        return AssistantCheck::updateOrCreate(['user_id' => $actor->id, 'site_id' => $site->id, 'kind' => $input['kind']], [
            'company_id' => $site->company_id, 'interval_hours' => (int) $input['interval_hours'], 'enabled' => false,
            'access_context' => AiInformationAccess::context($actor), 'approval_version' => Str::random(64), 'approved_at' => null, 'next_run_at' => null,
            'last_result' => null, 'last_run_at' => null, 'last_error' => null,
        ]);
    }

    public function activate(User $actor, int $id, bool $confirmed, string $version): AssistantCheck
    {
        abort_unless(config('ai_assistant.checks_enabled', false), 409, '정기 확인은 서버에서 아직 활성화되지 않았습니다.');
        abort_unless($confirmed, 422, '정기 확인을 시작하려면 명시적인 승인이 필요합니다.');

        return DB::transaction(function () use ($actor, $id, $version): AssistantCheck {
            $check = AssistantCheck::query()->where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            [$current, $site] = $this->authorize($actor, $check->site_id, $check->kind);
            abort_unless($current->canAccessCompany($check->company_id) && (int) $site->company_id === (int) $check->company_id, 403);
            abort_unless(hash_equals($check->approval_version, $version), 409, '확인 항목이나 간격이 변경되었습니다. 새로 확인하고 승인하세요.');
            $check->update(['enabled' => true, 'approved_at' => now(), 'next_run_at' => now(),
                'access_context' => AiInformationAccess::context($current), 'last_error' => null]);

            return $check;
        });
    }

    public function disable(User $actor, int $id): AssistantCheck
    {
        $check = AssistantCheck::query()->where('user_id', $actor->id)->findOrFail($id);
        $check->update(['enabled' => false, 'next_run_at' => null]);

        return $check;
    }

    public function list(User $actor): array
    {
        $out = [];
        foreach (AssistantCheck::query()->where('user_id', $actor->id)->latest('id')->limit(20)->get() as $check) {
            try {
                [$current, $site] = $this->authorize($actor, $check->site_id, $check->kind);
                if (! hash_equals($check->access_context, AiInformationAccess::context($current)) || (int) $site->company_id !== (int) $check->company_id) {
                    continue;
                }
                // Recompute authorized facts on read. A private document can be revoked after a stored check ran.
                $result = $check->last_run_at ? $this->evaluate($current, $site, $check->kind) : null;
                $out[] = ['id' => $check->id, 'site_id' => $site->id, 'site_name' => $site->name, 'kind' => $check->kind,
                    'label' => self::KINDS[$check->kind], 'interval_hours' => $check->interval_hours,
                    'enabled' => $check->enabled, 'approval_version' => $check->approval_version, 'last_run_at' => $check->last_run_at?->toIso8601String(),
                    'next_run_at' => $check->next_run_at?->toIso8601String(), 'result' => $result, 'error' => $check->last_error];
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
            }
        }

        return $out;
    }

    public function runDue(): int
    {
        if (! config('ai_assistant.checks_enabled', false)) {
            return 0;
        }
        $count = 0;
        $ids = AssistantCheck::query()->where('enabled', true)->whereNotNull('approved_at')->where('next_run_at', '<=', now()->toIso8601String())->orderBy('next_run_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $ran = DB::transaction(function () use ($id): bool {
                $check = AssistantCheck::query()->lockForUpdate()->find($id);
                if (! $check || ! $check->enabled || ! $check->approved_at || ! $check->next_run_at || $check->next_run_at->isFuture()) {
                    return false;
                }
                $actor = User::find($check->user_id);
                try {
                    abort_unless($actor, 403);
                    [$current, $site] = $this->authorize($actor, $check->site_id, $check->kind);
                    abort_unless(hash_equals($check->access_context, AiInformationAccess::context($current))
                        && (int) $check->company_id === (int) $site->company_id, 403);
                    $result = $this->evaluate($current, $site, $check->kind);
                } catch (HttpException $e) {
                    if ($e->getStatusCode() !== 403) {
                        throw $e;
                    }
                    $check->update(['enabled' => false, 'next_run_at' => null, 'last_result' => null, 'last_error' => '권한이 변경되어 중지되었습니다.']);

                    return false;
                }
                $check->update(['last_run_at' => now(), 'next_run_at' => now()->addHours($check->interval_hours), 'last_result' => $result, 'last_error' => null]);

                return true;
            });
            $count += $ran ? 1 : 0;
        }

        return $count;
    }

    private function authorize(User $actor, int $siteId, string $kind): array
    {
        $actor = $actor->fresh(['employee']);
        $site = Site::find($siteId);
        abort_unless($actor && $site && array_key_exists($kind, self::KINDS), 403);
        $context = new ErpReadContext($actor, (int) $site->company_id, $site->id);
        // Reuse the catalog's module policy, even when no records currently exist.
        app(ErpReadQuery::class)->query($this->dataset($kind), $context);

        return [$actor, $site];
    }

    private function dataset(string $kind): string
    {
        return match ($kind) {
            'overdue_wbs' => 'wbs_items', 'overdue_procurement' => 'procurement', 'expiring_documents' => 'documents',
        };
    }

    private function evaluate(User $actor, Site $site, string $kind): array
    {
        $today = now(SiteClock::zone($site))->toDateString();
        $query = app(ErpReadQuery::class)->query($this->dataset($kind), new ErpReadContext($actor, (int) $site->company_id, $site->id));
        [$columns, $query] = match ($kind) {
            'overdue_wbs' => [['id', 'wbs_code', 'name', 'planned_end', 'progress'], $query->where('progress', '<', 100)->whereDate('planned_end', '<', $today)],
            'overdue_procurement' => [['id', 'po_no', 'wbs_code', 'eta', 'status'], $query->whereNotIn('status', ['입고완료', 'received', 'cancelled'])->whereDate('eta', '<', $today)],
            'expiring_documents' => [['id', 'title', 'expires_on', 'document_type'], $query->whereDate('expires_on', '>=', $today)->whereDate('expires_on', '<=', now(SiteClock::zone($site))->addDays(30)->toDateString())],
        };
        $total = (clone $query)->count();
        $rows = $query->orderBy('id')->limit(25)->get($columns)->map(fn ($row) => $row->only($columns))->all();

        return ['kind' => $kind, 'count' => $total, 'records' => $rows, 'truncated' => $total > 25,
            'as_of' => now()->toIso8601String(), 'site_date' => $today, 'timezone' => SiteClock::zone($site),
            'source' => $this->dataset($kind), 'private' => true];
    }
}
