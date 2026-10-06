<?php

namespace App\Services\Assistant;

use App\Models\AssistantProposal;
use App\Models\Company;
use App\Models\DailyClosingReport;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\OpsActionItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Finance\ExpenseRegistrationService;
use App\Services\Ops\DailyFieldReportService;
use App\Services\Ops\DailyPlanService;
use App\Services\Ops\OpsActionService;
use App\Support\AccessPolicy;
use App\Support\AiInformationAccess;
use App\Support\FinanceChartOfAccounts;
use App\Support\ReceiptFilePayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Cause: model-generated arguments are neither an authorization nor a reviewed change.
 * A fixed, immutable proposal and a separate explicit confirmation own all assistant writes.
 * This service is never registered as a model/MCP execution tool.
 */
final class AssistantProposalService
{
    public const CREATE_TODO = 'ops.todo.create';

    public const UPDATE_TODO = 'ops.todo.update';

    public const UPDATE_DAILY_PLAN = 'daily_plan.draft.update';

    public const CREATE_DAILY_REPORT = 'daily_report.draft.create';

    public const CREATE_EXPENSE = 'expense.pending.create';

    public const UPDATE_DOCUMENT_CATEGORY = 'document.category.update';

    public const DOCUMENT_CATEGORIES = ['correspondence', 'rfi_submittal', 'drawing_spec', 'schedule', 'quality', 'safety', 'procurement', 'closeout', 'general'];

    private const RECEIPT_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly OpsActionService $actions) {}

    /** The entire mutation allowlist; there is deliberately no generic tool dispatcher. */
    public function capabilities(): array
    {
        return [self::CREATE_TODO, self::UPDATE_TODO, self::UPDATE_DAILY_PLAN, self::CREATE_DAILY_REPORT, self::CREATE_EXPENSE, self::UPDATE_DOCUMENT_CATEGORY];
    }

    /**
     * Persist an approval envelope, never a business record. Optional fields are replacements:
     * omitted detail/due_on become null. The displayed after snapshot makes this explicit.
     */
    public function create(User $actor, string $operation, int $siteId, array $payload, ?int $recordId = null): array
    {
        $this->assertEnabled();
        abort_unless(in_array($operation, $this->capabilities(), true), 422, '지원하지 않는 변경 작업입니다.');
        abort_unless((in_array($operation, [self::CREATE_TODO, self::CREATE_DAILY_REPORT, self::CREATE_EXPENSE], true) && $recordId === null)
            || (in_array($operation, [self::UPDATE_TODO, self::UPDATE_DAILY_PLAN, self::UPDATE_DOCUMENT_CATEGORY], true) && $recordId !== null && $recordId > 0), 422, '변경 대상이 올바르지 않습니다.');
        $payload = $this->validatePayload($operation, $payload);

        return DB::transaction(function () use ($actor, $operation, $siteId, $payload, $recordId): array {
            [$actor, $site] = $this->scope($actor, $siteId);
            $this->authorizeOperation($actor, $site, $operation, $payload);
            if ($operation === self::CREATE_EXPENSE && $payload['source_document_id'] === null) {
                abort_if($this->matchingExpense($site, $payload, $actor), 422,
                    '같은 현장·날짜·금액·내용의 경비가 이미 있습니다. 기존 경비를 확인하세요.');
            }
            $record = $recordId === null ? null : $this->record($operation, $recordId, $site->id, $actor);
            if ($operation === self::CREATE_DAILY_REPORT) {
                $record = $this->dailyReport($site->id, $payload['report_date']);
                if ($record) {
                    $this->assertEditable($record, $operation, $actor);
                }
            }
            $before = $record ? $this->snapshot($record, $operation) : null;
            if ($operation === self::CREATE_EXPENSE && $payload['source_document_id'] !== null) {
                [$source] = $this->receiptSource($actor, $site, $payload['source_document_id']);
                abort_if(MobileExpense::query()->where('source_ref', 'document:'.$source->id)->exists(), 422, '이미 경비로 등록된 영수증입니다.');
                $before = ['source_receipt' => $this->receiptSourceSnapshot($source)];
            }
            abort_if($operation === self::UPDATE_DOCUMENT_CATEGORY && $record->category === $payload['category'], 422, '변경된 분류가 없습니다.');
            $after = $this->plannedSnapshot($operation, $payload, $before, $actor);
            abort_if($before === $after, 422, '변경된 내용이 없습니다.');

            $proposal = new AssistantProposal([
                'created_by_id' => $actor->id,
                'actor_id' => $actor->id,
                'company_id' => $site->company_id,
                'site_id' => $site->id,
                'operation' => $operation,
                'record_id' => $record?->id,
                'payload' => $payload,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'record_version' => $record ? $this->recordVersion($record) : null,
                'actor_context' => AiInformationAccess::context($actor),
                'preview_token' => Str::random(64),
                'status' => AssistantProposal::STATUS_PENDING,
                'expires_at' => now()->addMinutes(15)->startOfSecond(),
                'audit_events' => [],
            ]);
            $proposal->id = (string) Str::uuid();
            $proposal->version = $this->proposalVersion($proposal);
            $this->audit($proposal, 'created', $actor);
            $proposal->save();

            return $this->serialize($proposal);
        });
    }

    /** Read only the original actor's preview, with current account/company/site authorization. */
    public function preview(User $actor, string $proposalId): array
    {
        return DB::transaction(function () use ($actor, $proposalId): array {
            $proposal = $this->ownedProposal($actor, $proposalId);
            [$actor, $site] = $this->scope($actor, $proposal->site_id, $proposal->company_id);
            $this->authorizeOperation($actor, $site, $proposal->operation, $proposal->payload);
            $this->assertPreviewSourceAccess($proposal, $actor, $site);
            $this->expire($proposal, $actor);

            return $this->serialize($proposal);
        });
    }

    /**
     * Only an explicit human confirmation endpoint calls this. No payload/target is accepted here.
     * The locked proposal ID is the idempotency key; repeat confirmation returns its saved result.
     */
    public function confirm(User $actor, string $proposalId, string $previewToken, string $version, mixed $confirmed): array
    {
        $this->assertEnabled();
        abort_unless($confirmed === true, 422, '미리보기를 확인한 뒤 명시적으로 승인해야 합니다.');

        $outcome = DB::transaction(function () use ($actor, $proposalId, $previewToken, $version): array {
            $proposal = $this->ownedProposal($actor, $proposalId);
            [$actor, $site] = $this->scope($actor, $proposal->site_id, $proposal->company_id);
            $this->authorizeOperation($actor, $site, $proposal->operation, $proposal->payload);
            $this->assertPreviewSourceAccess($proposal, $actor, $site);
            abort_unless(hash_equals($proposal->preview_token, $previewToken)
                && hash_equals($proposal->version, $version)
                && hash_equals($proposal->version, $this->proposalVersion($proposal)), 409, '승인할 미리보기가 일치하지 않습니다. 다시 확인하세요.');
            if ($proposal->status === AssistantProposal::STATUS_APPLIED) {
                return ['result' => $proposal->result];
            }
            $this->expire($proposal, $actor);
            if ($proposal->status !== AssistantProposal::STATUS_PENDING) {
                return ['error' => '이 변경 제안은 더 이상 승인할 수 없습니다.', 'status' => 409];
            }
            if (! hash_equals($proposal->actor_context, AiInformationAccess::context($actor))) {
                return $this->stale($proposal, $actor, 'actor_scope_changed');
            }

            // Reject tampered persisted arguments as well as unknown arguments at the API boundary.
            $payload = $this->validatePayload($proposal->operation, $proposal->payload);
            abort_unless(in_array($proposal->operation, $this->capabilities(), true)
                && $this->plannedSnapshot($proposal->operation, $payload, $proposal->before_snapshot, $actor) === $proposal->after_snapshot, 409);
            $record = null;
            if ($proposal->record_id !== null) {
                $record = $this->recordQuery($proposal->operation)->whereKey($proposal->record_id)->lockForUpdate()->first();
                if (! $record || (int) $record->site_id !== (int) $proposal->site_id
                    || ! hash_equals((string) $proposal->record_version, $this->recordVersion($record))) {
                    return $this->stale($proposal, $actor, 'record_changed');
                }
                $this->assertEditable($record, $proposal->operation, $actor);
            } else {
                abort_unless($proposal->record_id === null && $proposal->record_version === null, 409);
            }

            if ($proposal->operation === self::CREATE_DAILY_REPORT && $record === null
                && $this->dailyReport($site->id, $payload['report_date'])) {
                return $this->stale($proposal, $actor, 'daily_report_created');
            }
            if ($proposal->operation === self::CREATE_EXPENSE && $payload['source_document_id'] === null
                && $this->matchingExpense($site, $payload, $actor)) {
                return $this->stale($proposal, $actor, 'matching_expense_registered');
            }
            $receipt = null;
            if ($proposal->operation === self::CREATE_EXPENSE && $payload['source_document_id'] !== null) {
                try {
                    [$source, $receipt] = $this->receiptSource($actor, $site, $payload['source_document_id']);
                } catch (HttpExceptionInterface $e) {
                    if ($e->getStatusCode() !== 409) {
                        throw $e;
                    }

                    return $this->stale($proposal, $actor, 'receipt_evidence_changed');
                }
                if ($this->receiptSourceSnapshot($source) !== ($proposal->before_snapshot['source_receipt'] ?? null)
                    || MobileExpense::query()->where('source_ref', 'document:'.$source->id)->exists()) {
                    return $this->stale($proposal, $actor, 'receipt_source_changed');
                }
            }
            try {
                // A savepoint keeps a concurrently won unique key from poisoning PostgreSQL's
                // enclosing proposal transaction, so stale evidence can still be committed.
                $savedRecord = DB::transaction(fn () => match ($proposal->operation) {
                    self::UPDATE_DAILY_PLAN => $this->applyDailyPlan($record, $payload, $actor),
                    self::CREATE_DAILY_REPORT => $this->applyDailyReport($proposal, $record, $payload),
                    self::CREATE_EXPENSE => $this->applyExpense($proposal, $payload, $actor, $receipt),
                    self::UPDATE_DOCUMENT_CATEGORY => $this->applyDocumentCategory($record, $proposal->after_snapshot, $actor),
                    default => $this->applyTodo($proposal, $payload),
                });
            } catch (UniqueConstraintViolationException $e) {
                if (! in_array($proposal->operation, [self::CREATE_DAILY_REPORT, self::CREATE_EXPENSE], true)) {
                    throw $e;
                }

                return $this->stale($proposal, $actor, 'source_already_registered');
            }
            abort_unless($this->snapshot($savedRecord, $proposal->operation) === $proposal->after_snapshot, 409, '저장 결과가 미리보기와 다릅니다.');

            $proposal->status = AssistantProposal::STATUS_APPLIED;
            $proposal->confirmed_at = now();
            $proposal->result = [
                'success' => true,
                'proposal_id' => $proposal->id,
                'status' => AssistantProposal::STATUS_APPLIED,
                'operation' => $proposal->operation,
                'record_id' => $savedRecord->id,
                'site_id' => $proposal->site_id,
                'company_id' => $proposal->company_id,
                'before' => $proposal->before_snapshot,
                'after' => $this->snapshot($savedRecord, $proposal->operation),
                'confirmed_at' => $proposal->confirmed_at->toIso8601String(),
            ];
            $this->audit($proposal, 'applied', $actor, ['record_id' => $savedRecord->id]);
            $proposal->save();

            return ['result' => $proposal->result];
        });

        // Reject after committing expiry/stale audit state, never roll that evidence back.
        abort_if(isset($outcome['error']), $outcome['status'] ?? 409, $outcome['error'] ?? '');

        return $outcome['result'];
    }

    public function cancel(User $actor, string $proposalId): array
    {
        return DB::transaction(function () use ($actor, $proposalId): array {
            $proposal = $this->ownedProposal($actor, $proposalId);
            // Cancellation is safe even after permission revocation, but never for another actor.
            $actor = $this->freshActor($actor);
            if ($proposal->status === AssistantProposal::STATUS_PENDING) {
                $proposal->status = AssistantProposal::STATUS_CANCELLED;
                $proposal->cancelled_at = now();
                $this->audit($proposal, 'cancelled', $actor);
                $proposal->save();
            }
            abort_if($proposal->status === AssistantProposal::STATUS_APPLIED, 409, '이미 적용된 변경은 취소할 수 없습니다.');

            return ['id' => $proposal->id, 'status' => $proposal->status];
        });
    }

    private function assertEnabled(): void
    {
        abort_unless(config('ai_assistant.mutations_enabled', false) === true, 403, 'AI 변경 기능이 비활성화되어 있습니다.');
    }

    private function freshActor(User $actor): User
    {
        abort_unless($actor->exists && $actor->getKey(), 403);
        $fresh = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        abort_unless($fresh, 403);
        $fresh->setRelation('employee', $fresh->employee()->lockForUpdate()->first());

        return $fresh;
    }

    private function scope(User $actor, int $siteId, ?int $companyId = null): array
    {
        $actor = $this->freshActor($actor);
        abort_unless($actor->account_status === 'active' && AccessPolicy::canManageSite($actor), 403);
        $site = Site::query()->whereKey($siteId)->lockForUpdate()->first();
        abort_unless($site && $site->status === 'active' && $site->company_id, 403);
        abort_unless($companyId === null || (int) $site->company_id === $companyId, 403);
        // Keep authorization evidence stable until commit, including a concurrent membership revoke.
        Company::query()->whereKey($site->company_id)->sharedLock()->first();
        DB::table('company_user')->where('user_id', $actor->id)->where('company_id', $site->company_id)->sharedLock()->first();
        abort_unless(AiInformationAccess::canUseSite($actor, $site) && $actor->canAccessCompany($site->company_id), 403, '현재 회사·현장 권한으로 변경할 수 없습니다.');

        return [$actor, $site];
    }

    private function ownedProposal(User $actor, string $id): AssistantProposal
    {
        abort_unless(Str::isUuid($id), 404);
        $proposal = AssistantProposal::query()->whereKey($id)->where('actor_id', $actor->getKey())->lockForUpdate()->first();
        abort_unless($proposal, 404);

        return $proposal;
    }

    private function record(string $operation, int $id, int $siteId, User $actor): OpsActionItem|DailyClosingReport|IntelligentDocument
    {
        $record = $this->recordQuery($operation)->whereKey($id)->where('site_id', $siteId)->lockForUpdate()->first();
        abort_unless($record, 404);
        $this->assertEditable($record, $operation, $actor);

        return $record;
    }

    private function recordQuery(string $operation): Builder
    {
        return match ($operation) {
            self::CREATE_TODO, self::UPDATE_TODO => OpsActionItem::query(),
            self::UPDATE_DAILY_PLAN, self::CREATE_DAILY_REPORT => DailyClosingReport::query(),
            self::UPDATE_DOCUMENT_CATEGORY => IntelligentDocument::query(),
            default => abort(422, '지원하지 않는 변경 작업입니다.'),
        };
    }

    private function assertEditable(OpsActionItem|DailyClosingReport|IntelligentDocument $record, string $operation, User $actor): void
    {
        if ($record instanceof IntelligentDocument) {
            $site = Site::findOrFail($record->site_id);
            abort_unless(AiInformationAccess::documents($actor, $site)->whereKey($record->id)->exists(), 404);
            abort_unless(in_array($record->category, self::DOCUMENT_CATEGORIES, true)
                && in_array($record->document_type, AiInformationAccess::TECHNICAL_TYPES, true)
                && ! isset($record->ai_payload['duplicate_document_id'])
                && ! isset($record->ai_payload['scope_review_reason'])
                && ! isset($record->ai_payload['source_review_reason']), 422, '검토가 끝난 일반 현장 문서의 분류만 수정할 수 있습니다.');
            foreach (['title', 'original_file_name', 'summary', 'extracted_text', 'search_text'] as $field) {
                $this->assertTechnicalText((string) $record->$field);
            }
            $this->assertTechnicalText(json_encode($record->key_facts, JSON_UNESCAPED_UNICODE));
            $parts = $record->folder_structure;
            $record->loadMissing(['company', 'site', 'project']);
            $expectedCompany = $record->company?->code ?: $record->company?->name ?: 'GLOBAL';
            $expectedProject = $record->project?->project_code ?: $record->site?->code ?: 'GENERAL';
            $hints = (array) ($record->ai_payload['folder_parts'] ?? []);
            abort_unless(is_array($parts) && count($parts) === 5 && array_is_list($parts)
                && count(array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')) === 5
                && $record->virtual_path === implode(' / ', $parts)
                && $parts[0] === $expectedCompany && $parts[1] === $expectedProject
                && in_array($parts[2], [$record->category, $hints[0] ?? null], true)
                && in_array($parts[3], [$record->document_type, $hints[1] ?? null], true), 422, '사용자 지정 또는 불완전한 문서 경로는 문서함에서 직접 확인하세요.');

            return;
        }
        if ($record instanceof DailyClosingReport && $operation === self::CREATE_DAILY_REPORT) {
            abort_unless($record->status === DailyClosingReport::OPEN && $record->closed_at === null
                && $record->closed_by_id === null && $record->field_submitted_at === null
                && in_array($record->field_status, [null, 'draft'], true) && ! $record->hasFieldReport(),
                422, '기존 현장 보고를 덮어쓰지 않고 비어 있는 일일보고 초안만 만들 수 있습니다.');

            return;
        }
        if ($record instanceof DailyClosingReport) {
            abort_unless($record->status === DailyClosingReport::OPEN
                && $record->plan_status === DailyClosingReport::PLAN_DRAFT
                && $record->plan_submitted_at === null && $record->closed_at === null
                && $record->closed_by_id === null && $record->hasPlan(), 422,
                '아직 확정·마감하지 않은 기존 작업계획 초안만 수정할 수 있습니다.');
            $this->assertTechnicalText((string) ($record->plan['workScope'] ?? '').' '.(string) ($record->plan['notes'] ?? ''));

            return;
        }

        abort_unless($record->kind === OpsActionItem::KIND_TODO && $record->status === 'open'
            && ! $record->is_blocker && $record->ops_intake_batch_id === null
            && $record->ops_intake_item_id === null && $record->requester === null
            && $record->assignee === null && $record->done_at === null && $record->done_by_id === null,
            422, '담당자·승인·보고와 연결되지 않은 일반 미완료 할 일만 수정할 수 있습니다.');
        $this->assertTechnicalText((string) $record->title.' '.(string) $record->detail);
    }

    private function validatePayload(string $operation, array $payload): array
    {
        if ($operation === self::CREATE_DAILY_REPORT) {
            $this->onlyFields($payload, ['report_date', 'work_title', 'work_today', 'work_tomorrow']);
            $data = Validator::make($payload, [
                'report_date' => ['required', 'date_format:Y-m-d'],
                'work_title' => ['required', 'string', 'max:500'],
                'work_today' => ['required', 'string', 'max:8000'],
                'work_tomorrow' => ['nullable', 'string', 'max:8000'],
            ])->validate();
            $data = ['report_date' => $data['report_date'], 'work_title' => trim($data['work_title']),
                'work_today' => trim($data['work_today']), 'work_tomorrow' => trim((string) ($data['work_tomorrow'] ?? '')) ?: null];
            abort_unless($data['work_title'] !== '' && $data['work_today'] !== '', 422);
            $this->assertTechnicalText(implode(' ', $data));

            return $data;
        }
        if ($operation === self::UPDATE_DOCUMENT_CATEGORY) {
            $this->onlyFields($payload, ['category']);

            return Validator::make($payload, ['category' => ['required', Rule::in(self::DOCUMENT_CATEGORIES)]])->validate();
        }
        if ($operation === self::CREATE_EXPENSE) {
            $this->onlyFields($payload, ['description', 'amount', 'currency', 'expense_date', 'accounting_account', 'payment_type', 'source_document_id']);
            $data = Validator::make($payload, [
                'description' => ['required', 'string', 'max:4000'],
                'amount' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', 'numeric', 'min:0.01', 'max:999999999999.99'],
                'currency' => ['required', Rule::in([ExpenseRegistrationService::CURRENCY])],
                'expense_date' => ['required', 'date_format:Y-m-d'],
                'accounting_account' => ['required', Rule::in(FinanceChartOfAccounts::accounts())],
                'payment_type' => ['required', Rule::in(['personal', 'corporate'])],
                'source_document_id' => ['nullable', 'integer', 'min:1'],
            ])->validate();
            $data['description'] = trim($data['description']);
            abort_unless($data['description'] !== '', 422);
            // Decimal strings retain every approved cent, without float rounding.
            [$whole, $fraction] = array_pad(explode('.', (string) $data['amount'], 2), 2, '');
            $data['amount'] = $whole.'.'.str_pad($fraction, 2, '0');
            $data['source_document_id'] = isset($data['source_document_id']) ? (int) $data['source_document_id'] : null;

            return $data;
        }
        if ($operation === self::UPDATE_DAILY_PLAN) {
            if (array_diff(array_keys($payload), ['work_scope', 'notes'])) {
                throw ValidationException::withMessages(['payload' => '작업계획 초안의 작업 내용과 비고만 변경할 수 있습니다.']);
            }
            $data = Validator::make($payload, [
                'work_scope' => ['required', 'string', 'max:8000'],
                'notes' => ['nullable', 'string', 'max:4000'],
            ])->validate();
            $data = ['work_scope' => trim($data['work_scope']), 'notes' => trim((string) ($data['notes'] ?? ''))];
            if ($data['work_scope'] === '') {
                throw ValidationException::withMessages(['work_scope' => '작업 내용을 입력하세요.']);
            }
            $this->assertTechnicalText($data['work_scope'].' '.$data['notes']);

            return $data;
        }

        if (array_diff(array_keys($payload), ['title', 'detail', 'due_on'])) {
            throw ValidationException::withMessages(['payload' => '제목, 상세 내용, 기한만 변경할 수 있습니다.']);
        }
        $data = Validator::make($payload, [
            'title' => ['required', 'string', 'max:255'],
            'detail' => ['nullable', 'string', 'max:4000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        $data = [
            'title' => trim($data['title']),
            'detail' => trim((string) ($data['detail'] ?? '')) ?: null,
            'due_on' => $data['due_on'] ?? null,
        ];
        if ($data['title'] === '') {
            throw ValidationException::withMessages(['title' => '할 일을 입력하세요.']);
        }
        $this->assertTechnicalText($data['title'].' '.($data['detail'] ?? ''));

        return $data;
    }

    private function applyTodo(AssistantProposal $proposal, array $payload): OpsActionItem
    {
        // Reuse the domain writer, but never reopen workflows or clear existing assignments.
        $saved = $this->actions->save($proposal->site_id, [
            'id' => $proposal->record_id, 'kind' => OpsActionItem::KIND_TODO,
            'title' => $payload['title'], 'detail' => $payload['detail'], 'dueOn' => $payload['due_on'],
            'requester' => null, 'assignee' => null, 'isBlocker' => false,
        ]);
        abort_unless(($saved['success'] ?? false) && isset($saved['id']), 409, '변경을 저장하지 못했습니다.');

        return OpsActionItem::query()->findOrFail($saved['id']);
    }

    private function applyDailyPlan(DailyClosingReport $record, array $payload, User $actor): DailyClosingReport
    {
        $original = $record->getRawOriginal();
        $plan = array_replace($record->plan, ['workScope' => $payload['work_scope'], 'notes' => $payload['notes']]);
        $saved = app(DailyPlanService::class)->save($record->site_id, $record->report_date->toDateString(), $plan, $actor->id, false);
        abort_unless(($saved['success'] ?? false) && ($saved['reportId'] ?? null) === $record->id, 409);
        $record->refresh();
        // Domain normalization must not silently remove old crew, hazard, permit or custom data.
        // Roll the entire transaction back if anything outside the reviewed change would differ.
        $changed = array_flip(['plan', 'plan_by_id', 'updated_at']);
        abort_unless($this->digest($record->plan) === $this->digest($plan)
            && array_diff_key($original, $changed) === array_diff_key($record->getRawOriginal(), $changed),
            409, '작업계획의 다른 항목을 보존할 수 없습니다. 기존 작업계획 화면에서 확인해 주세요.');

        return $record;
    }

    private function onlyFields(array $payload, array $fields): void
    {
        if (array_diff(array_keys($payload), $fields)) {
            throw ValidationException::withMessages(['payload' => '이 작업에서 허용하지 않는 필드가 포함되어 있습니다.']);
        }
    }

    private function authorizeOperation(User $actor, Site $site, string $operation, array $payload): void
    {
        if ($operation !== self::CREATE_EXPENSE) {
            return;
        }
        abort_unless(AccessPolicy::canManageMoney($actor), 403, '검토 대기 경비 등록에는 재무 권한이 필요합니다.');
        if (($payload['payment_type'] ?? null) === 'personal') {
            $employee = $actor->employee;
            abort_unless($employee && $employee->employment_status === 'active'
                && (int) $employee->company_id === (int) $site->company_id
                && (int) $employee->site_id === (int) $site->id, 403,
                '개인 경비는 현재 회사·현장에 소속된 본인의 직원 계정으로만 등록할 수 있습니다.');
        }
    }

    private function assertPreviewSourceAccess(AssistantProposal $proposal, User $actor, Site $site): void
    {
        if ($proposal->operation === self::UPDATE_DOCUMENT_CATEGORY) {
            abort_unless(AiInformationAccess::documents($actor, $site)->whereKey($proposal->record_id)->exists(), 404);
        }
        if ($proposal->operation === self::CREATE_EXPENSE && ! empty($proposal->payload['source_document_id'])) {
            $this->receiptSourceQuery($actor, $site)->whereKey($proposal->payload['source_document_id'])->firstOrFail();
        }
    }

    private function dailyReport(int $siteId, string $date): ?DailyClosingReport
    {
        return DailyClosingReport::query()->where('site_id', $siteId)->whereDate('report_date', $date)
            ->lockForUpdate()->first();
    }

    private function applyDailyReport(AssistantProposal $proposal, ?DailyClosingReport $record, array $payload): DailyClosingReport
    {
        $original = $record?->getRawOriginal();
        $attributes = ['work_title' => $payload['work_title'], 'work_today' => $payload['work_today'],
            'work_tomorrow' => $payload['work_tomorrow'], 'field_status' => 'draft'];
        $writer = app(DailyFieldReportService::class);
        $saved = $record === null
            ? $writer->create($proposal->site_id, $payload['report_date'], $attributes)
            : $writer->save($proposal->site_id, $payload['report_date'], $attributes);
        if ($original !== null) {
            $changed = array_flip(['work_title', 'work_today', 'work_tomorrow', 'field_status', 'updated_at']);
            abort_unless($saved->id === $record->id
                && array_diff_key($original, $changed) === array_diff_key($saved->getRawOriginal(), $changed), 409);
        }

        return $saved;
    }

    private function matchingExpense(Site $site, array $payload, User $actor): bool
    {
        return MobileExpense::query()->where('company_id', $site->company_id)->where('site_id', $site->id)
            ->whereDate('expense_date', $payload['expense_date'])->where('amount', $payload['amount'])
            ->where('description', $payload['description'])->where('payment_type', $payload['payment_type'])
            ->when($payload['payment_type'] === 'personal', fn (Builder $q) => $q->where('employee_id', $actor->employee_id))
            ->exists();
    }

    private function receiptSourceQuery(User $actor, Site $site): Builder
    {
        return AiInformationAccess::documents($actor, $site)
            ->where('document_type', 'receipt')->where('access_level', 'scope')
            ->whereIn('confidentiality', ['public', 'internal'])->where('uploaded_by', $actor->id)
            ->where(fn (Builder $q) => $q->whereNull('owner_user_id')->orWhere('owner_user_id', $actor->id))
            ->whereNull('ai_payload->duplicate_document_id')->whereNull('ai_payload->scope_review_reason')
            ->whereNull('ai_payload->source_review_reason');
    }

    /** Read an already uploaded receipt, never analyze/upload/move it or accept a client path. */
    private function receiptSource(User $actor, Site $site, int $id): array
    {
        $document = $this->receiptSourceQuery($actor, $site)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless(in_array(strtolower((string) $document->extension), ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true)
            && preg_match('/^[a-f0-9]{64}$/D', (string) $document->sha256)
            && $document->file_size > 0 && $document->file_size <= self::RECEIPT_MAX_BYTES,
            422, '확인 가능한 PDF·사진 영수증(최대 10MB)만 연결할 수 있습니다.');
        abort_unless(is_string($document->original_file_name) && $document->original_file_name !== ''
            && mb_strlen($document->original_file_name) <= 255
            && ! preg_match('~[\\x00-\\x1f\\x7f"/\\\\]~u', $document->original_file_name),
            422, '영수증 파일 이름을 문서함에서 확인해 주세요.');
        $moneyCurrency = strtoupper(trim((string) ($document->ai_payload['money']['currency'] ?? '')));
        abort_unless($moneyCurrency === '' || $moneyCurrency === ExpenseRegistrationService::CURRENCY, 422,
            'USD 이외의 영수증은 기존 재무 화면에서 통화를 확인해 주세요.');
        $disk = Storage::disk($document->disk ?: config('document-intelligence.disk'));
        abort_unless($disk->exists($document->file_path), 409, '영수증 원본 파일을 찾을 수 없습니다.');
        $stream = $disk->readStream($document->file_path);
        abort_unless(is_resource($stream), 409, '영수증 원본을 읽을 수 없습니다.');
        try {
            $bytes = stream_get_contents($stream, self::RECEIPT_MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        abort_unless(is_string($bytes) && strlen($bytes) === $document->file_size
            && strlen($bytes) <= self::RECEIPT_MAX_BYTES && hash_equals($document->sha256, hash('sha256', $bytes)),
            409, '영수증 원본이 등록된 증빙과 일치하지 않습니다.');

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = match (strtolower((string) $document->extension)) {
            'pdf' => ['application/pdf'],
            'jpg', 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'gif' => ['image/gif'],
            'heic', 'heif' => ['image/heic', 'image/heif'],
            default => [],
        };
        abort_unless(in_array($mime, $allowed, true) && $document->mime_type === $mime,
            422, '영수증의 실제 파일 형식과 등록 정보가 일치하지 않습니다.');

        return [$document, $bytes];
    }

    private function receiptSourceSnapshot(IntelligentDocument $source): array
    {
        return ['id' => $source->id, 'title' => $source->title, 'file_name' => $source->original_file_name,
            'sha256' => $source->sha256, 'mime_type' => $source->mime_type, 'size' => $source->file_size,
            'record_version' => $this->recordVersion($source), 'uploaded_by' => $source->uploaded_by,
            'owner_user_id' => $source->owner_user_id, 'access_level' => $source->access_level,
            'confidentiality' => $source->confidentiality, 'company_id' => $source->company_id, 'site_id' => $source->site_id];
    }

    private function applyExpense(AssistantProposal $proposal, array $payload, User $actor, ?string $receipt): MobileExpense
    {
        $proof = $proposal->after_snapshot['receipt'];
        $source = $proof ? ['document_id' => $proof['document_id'], 'receipt_proof_sha256' => $proof['sha256'], 'receipt_proof' => $proof] : [];
        $record = app(ExpenseRegistrationService::class)->registerPending([
            'company_id' => $proposal->company_id, 'site_id' => $proposal->site_id,
            'employee_id' => $payload['payment_type'] === 'personal' ? $actor->employee_id : null,
            'source_ref' => $proof ? 'document:'.$proof['document_id'] : 'assistant:'.$proposal->id,
            'payment_type' => $payload['payment_type'], 'category' => $payload['accounting_account'],
            'accounting_account' => $payload['accounting_account'], 'description' => $payload['description'],
            'amount' => $payload['amount'], 'expense_date' => $payload['expense_date'],
            'receipt_file' => $receipt === null ? null : ReceiptFilePayload::encode($receipt),
            'receipt_mime_type' => $proof['mime_type'] ?? null, 'receipt_original_name' => $proof['file_name'] ?? null,
            'ocr_data' => ['source' => 'assistant-confirmed', 'proposal_id' => $proposal->id, 'currency' => ExpenseRegistrationService::CURRENCY] + $source,
        ])->refresh();
        abort_unless($record->status === 'pending' && $record->reviewed_at === null && $record->reviewed_by_user_id === null
            && $record->paid_at === null && $record->paid_by_user_id === null && $record->payment_reference === null
            && $record->payroll_run_id === null && $record->expense_pre_approval_id === null
            && $record->vendor_id === null && $record->project_id === null && $record->wbs_code === null
            && $record->receipt_path === null && ($receipt === null || app(ExpenseRegistrationService::class)->hasCanonicalReceipt($record)), 409);

        return $record;
    }

    private function applyDocumentCategory(IntelligentDocument $record, array $after, User $actor): IntelligentDocument
    {
        $original = $record->getRawOriginal();
        // Category is filing metadata. Type, privacy, original bytes, scope, action items,
        // knowledge, financial links and analysis state remain exactly as reviewed.
        $record->update(['category' => $after['category'], 'folder_structure' => $after['folder_structure'],
            'virtual_path' => $after['virtual_path'], 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $record->refresh();
        $changed = array_flip(['category', 'folder_structure', 'virtual_path', 'reviewed_by', 'reviewed_at', 'updated_at']);
        abort_unless(array_diff_key($original, $changed) === array_diff_key($record->getRawOriginal(), $changed), 409);

        return $record;
    }

    private function assertTechnicalText(string $text): void
    {
        if (AiInformationAccess::financial($text)) {
            throw ValidationException::withMessages(['payload' => '이 변경 기능은 재무·급여·지급 내용에 사용할 수 없습니다.']);
        }
    }

    private function snapshot(OpsActionItem|DailyClosingReport|IntelligentDocument|MobileExpense $record, string $operation): array
    {
        if ($record instanceof MobileExpense) {
            return ['description' => $record->description, 'amount' => $record->amount,
                'currency' => ExpenseRegistrationService::CURRENCY, 'expense_date' => $record->expense_date->toDateString(),
                'accounting_account' => $record->accounting_account, 'category' => $record->category,
                'payment_type' => $record->payment_type, 'employee_id' => $record->employee_id,
                'status' => $record->status, 'reviewed_at' => $record->reviewed_at, 'paid_at' => $record->paid_at,
                'receipt' => $record->ocr_data['receipt_proof'] ?? null];
        }
        if ($record instanceof IntelligentDocument) {
            return ['title' => $record->title, 'category' => $record->category, 'document_type' => $record->document_type,
                'folder_structure' => $record->folder_structure, 'virtual_path' => $record->virtual_path,
                'confidentiality' => $record->confidentiality, 'access_level' => $record->access_level,
                'owner_user_id' => $record->owner_user_id, 'reviewed_by' => $record->reviewed_by];
        }
        if ($record instanceof DailyClosingReport && $operation === self::CREATE_DAILY_REPORT) {
            return ['report_date' => $record->report_date->toDateString(), 'work_title' => $record->work_title,
                'work_today' => $record->work_today, 'work_tomorrow' => $record->work_tomorrow,
                'field_status' => $record->field_status, 'closing_status' => $record->status];
        }
        if ($record instanceof DailyClosingReport) {
            return ['report_date' => $record->report_date->toDateString(),
                'work_scope' => (string) ($record->plan['workScope'] ?? ''), 'notes' => (string) ($record->plan['notes'] ?? ''),
                'plan_by_id' => $record->plan_by_id === null ? null : (int) $record->plan_by_id,
                'plan_status' => $record->plan_status, 'closing_status' => $record->status];
        }

        return ['title' => $record->title, 'detail' => $record->detail, 'due_on' => $record->due_on?->toDateString(),
            'kind' => $record->kind, 'status' => $record->status, 'is_blocker' => (bool) $record->is_blocker,
            'requester' => $record->requester, 'assignee' => $record->assignee];
    }

    private function plannedSnapshot(string $operation, array $payload, ?array $before, User $actor): array
    {
        if ($operation === self::CREATE_DAILY_REPORT) {
            return $payload + ['field_status' => 'draft', 'closing_status' => DailyClosingReport::OPEN];
        }
        if ($operation === self::CREATE_EXPENSE) {
            $source = $before['source_receipt'] ?? null;

            return ['description' => $payload['description'], 'amount' => $payload['amount'],
                'currency' => ExpenseRegistrationService::CURRENCY, 'expense_date' => $payload['expense_date'],
                'accounting_account' => $payload['accounting_account'], 'category' => $payload['accounting_account'],
                'payment_type' => $payload['payment_type'], 'employee_id' => $payload['payment_type'] === 'personal' ? $actor->employee_id : null,
                'status' => 'pending', 'reviewed_at' => null, 'paid_at' => null,
                'receipt' => $source ? ['document_id' => $source['id'], 'file_name' => $source['file_name'],
                    'sha256' => $source['sha256'], 'mime_type' => $source['mime_type'], 'size' => $source['size']] : null];
        }
        if ($operation === self::UPDATE_DOCUMENT_CATEGORY) {
            abort_unless($before !== null, 409);
            $parts = $before['folder_structure'];
            $parts[2] = $payload['category'];

            return array_replace($before, ['category' => $payload['category'], 'folder_structure' => $parts,
                'virtual_path' => implode(' / ', $parts), 'reviewed_by' => $actor->id]);
        }
        if ($operation === self::UPDATE_DAILY_PLAN) {
            abort_unless($before !== null, 409);

            return array_replace($before, $payload, ['plan_by_id' => $actor->id]);
        }

        return $payload + ['kind' => OpsActionItem::KIND_TODO, 'status' => 'open', 'is_blocker' => false, 'requester' => null, 'assignee' => null];
    }

    private function recordVersion(OpsActionItem|DailyClosingReport|IntelligentDocument $record): string
    {
        // All stored fields participate, not just second-resolution updated_at or displayed fields.
        return $this->digest($record->getRawOriginal());
    }

    private function proposalVersion(AssistantProposal $proposal): string
    {
        return $this->digest([
            'id' => $proposal->id,
            'operation' => $proposal->operation,
            'actor_id' => (int) $proposal->actor_id,
            'created_by_id' => (int) $proposal->created_by_id,
            'company_id' => (int) $proposal->company_id,
            'site_id' => (int) $proposal->site_id,
            'record_id' => $proposal->record_id === null ? null : (int) $proposal->record_id,
            'record_version' => $proposal->record_version,
            'actor_context' => $proposal->actor_context,
            'payload' => $proposal->payload,
            'before' => $proposal->before_snapshot,
            'after' => $proposal->after_snapshot,
            'expires_at' => $proposal->expires_at->utc()->toIso8601String(),
        ]);
    }

    private function digest(array $data): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function expire(AssistantProposal $proposal, User $actor): void
    {
        if ($proposal->status === AssistantProposal::STATUS_PENDING && $proposal->expires_at->lte(now())) {
            $proposal->status = AssistantProposal::STATUS_EXPIRED;
            $this->audit($proposal, 'expired', $actor);
            $proposal->save();
        }
    }

    private function stale(AssistantProposal $proposal, User $actor, string $reason): array
    {
        $proposal->status = AssistantProposal::STATUS_STALE;
        $this->audit($proposal, 'stale', $actor, ['reason' => $reason]);
        $proposal->save();

        return ['error' => '미리보기 이후 권한 또는 원본이 변경되었습니다. 새 변경 제안을 만들어 주세요.', 'status' => 409];
    }

    private function audit(AssistantProposal $proposal, string $event, User $actor, array $extra = []): void
    {
        $proposal->audit_events = [...($proposal->audit_events ?? []), [
            'event' => $event, 'actor_id' => $actor->id, 'at' => now()->toIso8601String(), 'version' => $proposal->version,
        ] + $extra];
    }

    private function serialize(AssistantProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'operation' => $proposal->operation,
            'status' => $proposal->status,
            'company_id' => $proposal->company_id,
            'site_id' => $proposal->site_id,
            'record_id' => $proposal->record_id,
            'before' => $proposal->before_snapshot,
            'after' => $proposal->after_snapshot,
            'version' => $proposal->version,
            'preview_token' => $proposal->preview_token,
            'expires_at' => $proposal->expires_at->utc()->toIso8601String(),
            'result' => $proposal->result,
            'visibility' => match ($proposal->operation) {
                self::UPDATE_DAILY_PLAN => 'site_daily_plan',
                self::CREATE_DAILY_REPORT => 'site_daily_report',
                self::CREATE_EXPENSE => 'finance_pending_review',
                self::UPDATE_DOCUMENT_CATEGORY => 'existing_document_access',
                default => 'site_operations_board',
            },
        ];
    }
}
