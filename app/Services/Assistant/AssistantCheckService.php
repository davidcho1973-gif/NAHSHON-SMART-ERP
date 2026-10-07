<?php

namespace App\Services\Assistant;

use App\Mcp\Read\ErpReadContext;
use App\Mcp\Read\ErpReadQuery;
use App\Models\AssistantCheck;
use App\Models\DailyTradeReport;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\TradeReportService;
use App\Support\AiInformationAccess;
use App\Support\ReportSlot;
use App\Support\SiteClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Deterministic checks: private results only, no AI spend, email, push or shared-room delivery. */
final class AssistantCheckService
{
    public const KINDS = [
        'overdue_wbs' => '지연 공정', 'overdue_procurement' => '조달 납기 경과', 'expiring_documents' => '30일 내 문서 만료',
        'pending_expense_approvals' => '비용·사전승인 대기', 'unreceived_procurement' => '발주 후 입고 미완료',
        'missing_trade_reports' => '오늘 공종·부서 일일보고 미제출',
    ];

    public const DESCRIPTIONS = [
        'pending_expense_approvals' => '선택한 현장의 비용과 사전승인 중 승인 대기인 처리 항목을 확인합니다. 금액은 합산하지 않습니다.',
        'unreceived_procurement' => '발주완료·생산중·선적중·통관중으로 등록된 조달을 확인합니다. 실제 재고나 입고 수량을 뜻하지 않습니다.',
        'missing_trade_reports' => '현장 마감 시각 이후, 오늘 출근기록이 있는 공종·부서의 제출 여부를 매시간 확인합니다. 과거 날짜는 확인하지 않습니다.',
    ];

    public static function intervals(string $kind): array
    {
        // A morning 24-hour schedule never observes today's reporting deadline.
        return $kind === 'missing_trade_reports' ? [1] : [1, 6, 24];
    }

    public function save(User $actor, array $input): AssistantCheck
    {
        $input = Validator::make($input, [
            'site_id' => ['required', 'integer', 'min:1'], 'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'interval_hours' => ['required', Rule::in(self::intervals(is_string($input['kind'] ?? null) ? $input['kind'] : ''))],
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
            abort_unless(in_array($check->interval_hours, self::intervals($check->kind), true), 422, '이 확인 항목은 매시간 실행해야 합니다. 다시 저장하세요.');
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
                    'description' => self::DESCRIPTIONS[$check->kind] ?? null,
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
                    abort_unless(in_array($check->interval_hours, self::intervals($check->kind), true), 403);
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
        foreach ($this->datasets($kind) as $dataset) {
            app(ErpReadQuery::class)->query($dataset, $context);
        }
        abort_if($kind === 'missing_trade_reports' && $site->status !== 'active', 403);

        return [$actor, $site];
    }

    private function datasets(string $kind): array
    {
        return match ($kind) {
            'overdue_wbs' => ['wbs_items'], 'overdue_procurement', 'unreceived_procurement' => ['procurement'], 'expiring_documents' => ['documents'],
            'pending_expense_approvals' => ['expense_preapprovals', 'expenses'],
            'missing_trade_reports' => ['daily_trade_reports', 'attendance_logs', 'employees'],
        };
    }

    private function evaluate(User $actor, Site $site, string $kind): array
    {
        $today = now(SiteClock::zone($site))->toDateString();
        $context = new ErpReadContext($actor, (int) $site->company_id, $site->id);
        $base = ['kind' => $kind, 'as_of' => now()->toIso8601String(), 'site_date' => $today, 'timezone' => SiteClock::zone($site),
            'source' => implode(' + ', $this->datasets($kind)), 'private' => true];
        if ($kind === 'pending_expense_approvals') {
            return $base + $this->pendingExpenses($context);
        }
        if ($kind === 'missing_trade_reports') {
            return $base + $this->missingReports($context, $site, $today);
        }
        $query = app(ErpReadQuery::class)->query($this->datasets($kind)[0], $context);
        [$columns, $query] = match ($kind) {
            'overdue_wbs' => [['id', 'wbs_code', 'name', 'planned_end', 'progress'], $query->where('progress', '<', 100)->whereDate('planned_end', '<', $today)],
            'overdue_procurement' => [['id', 'po_no', 'wbs_code', 'eta', 'status'], $query->whereNotIn('status', ['입고완료', 'received', 'cancelled'])->whereDate('eta', '<', $today)],
            'unreceived_procurement' => [['id', 'po_no', 'wbs_code', 'ordered_on', 'eta', 'status'], $query->whereIn('status', ProcurementItem::AWAITING_RECEIPT_STATUSES)],
            'expiring_documents' => [['id', 'title', 'expires_on', 'document_type'], $query->whereDate('expires_on', '>=', $today)->whereDate('expires_on', '<=', now(SiteClock::zone($site))->addDays(30)->toDateString())],
        };
        $total = (clone $query)->count();
        $rows = $query->orderBy('id')->limit(25)->get($columns)->map(fn ($row) => $row->only($columns))->all();

        return $base + ['count' => $total, 'records' => $rows, 'truncated' => $total > 25,
            'message' => self::DESCRIPTIONS[$kind] ?? null];
    }

    private function pendingExpenses(ErpReadContext $context): array
    {
        $rows = [];
        $counts = [];
        // Stable source/id order and a shared display budget; count both full queries first.
        foreach (['expense_preapprovals' => ['title', 'planned_date'], 'expenses' => ['description', 'expense_date']] as $source => [$label, $date]) {
            $query = app(ErpReadQuery::class)->query($source, $context)->where('status', 'pending');
            $counts[$source] = (clone $query)->count();
            if (count($rows) < 25) {
                foreach ($query->orderBy('id')->limit(25 - count($rows))->get(['id', $label, $date, 'status']) as $row) {
                    $rows[] = ['id' => $source.':'.$row->id, 'source' => $source, 'record_id' => $row->id,
                        'title' => Str::limit((string) $row->{$label}, 240), 'date' => $row->{$date}?->toDateString(), 'status' => $row->status];
                }
            }
        }

        return ['count' => array_sum($counts), 'subcounts' => $counts, 'records' => $rows, 'truncated' => array_sum($counts) > 25,
            'message' => self::DESCRIPTIONS['pending_expense_approvals']];
    }

    private function missingReports(ErpReadContext $context, Site $site, string $today): array
    {
        $local = now(SiteClock::zone($site));
        // Reuse only the existing deadline getter and pure ReportSlot rules, never reminder execution.
        $due = $local->copy()->setTime(app(TradeReportService::class)->dueHour(), 0, 0);
        $base = ['due_at' => $due->toIso8601String(), 'count' => 0, 'records' => [], 'truncated' => false];
        if ($local->lessThan($due)) {
            return $base + ['state' => 'not_due', 'message' => '아직 오늘 보고 마감 시각 전입니다. 제출 완료 여부를 판정하지 않았습니다.'];
        }
        $reads = app(ErpReadQuery::class);
        $clockIns = $reads->query('attendance_logs', $context)->where('attendance_date', $today)
            ->where('event_type', 'clock_in')->where('status', '!=', 'rejected')->select('employee_id');
        $employees = $reads->query('employees', $context)->whereIn('id', $clockIns)->get(['id', 'role', 'position', 'employment_type']);
        $expected = ReportSlot::keysOf($employees);
        if ($expected === []) {
            return $base + ['state' => 'no_expectation', 'message' => '오늘 조회 가능한 출근기록에서 보고 대상 공종·부서를 찾지 못했습니다. 전체 보고 완료를 뜻하지 않습니다.'];
        }
        $reports = $reads->query('daily_trade_reports', $context)->where('work_date', $today)->whereIn('trade', $expected)
            ->get(['id', 'trade', 'status'])->keyBy('trade');
        $missing = array_values(array_filter($expected, fn (string $trade): bool => $reports->get($trade)?->status !== DailyTradeReport::STATUS_SUBMITTED));
        $rows = array_map(fn (string $trade): array => ['work_date' => $today, 'trade' => $trade, 'kind' => ReportSlot::kindOf($trade),
            'report_id' => $reports->get($trade)?->id, 'status' => $reports->get($trade)?->status ?? 'missing'], array_slice($missing, 0, 25));

        return array_replace($base, ['count' => count($missing), 'records' => $rows, 'truncated' => count($missing) > 25,
            'expected_count' => count($expected), 'state' => $missing === [] ? 'complete' : 'missing',
            'message' => $missing === [] ? '오늘 조회 가능한 출근기록에 해당하는 공종·부서는 모두 제출했습니다.' : self::DESCRIPTIONS['missing_trade_reports']]);
    }
}
