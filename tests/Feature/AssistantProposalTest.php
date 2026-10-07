<?php

namespace Tests\Feature;

use App\Models\AssistantProposal;
use App\Models\Company;
use App\Models\DailyClosingReport;
use App\Models\OpsActionItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Assistant\AssistantProposalService;
use App\Services\Ops\DailyPlanService;
use App\Services\Ops\OpsActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AssistantProposalTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Company $company;

    private Site $site;

    private AssistantProposalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ai_assistant.mutations_enabled' => true]);
        $this->company = Company::create(['code' => 'AP-CO', 'name' => 'Proposal Co', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'AP-SITE', 'name' => 'Proposal Site', 'status' => 'active']);
        $this->actor = $this->actor();
        $this->service = app(AssistantProposalService::class);
    }

    private function actor(array $attributes = []): User
    {
        $actor = User::factory()->create($attributes + [
            'account_status' => 'active', 'access_role' => 'site_manager', 'access_scope' => 'site',
            'allowed_company_id' => $this->company->id, 'allowed_site_id' => $this->site->id,
        ]);
        $actor->companies()->attach($this->company);

        return $actor;
    }

    private function todo(array $attributes = []): OpsActionItem
    {
        return OpsActionItem::create($attributes + [
            'site_id' => $this->site->id, 'kind' => 'todo', 'title' => 'Bring updated drawings',
            'detail' => 'For the coordination meeting', 'status' => 'open', 'is_blocker' => false,
            'due_on' => '2026-10-10',
        ]);
    }

    private function propose(?OpsActionItem $record = null, array $payload = []): array
    {
        return $this->service->create($this->actor,
            $record ? AssistantProposalService::UPDATE_TODO : AssistantProposalService::CREATE_TODO,
            $this->site->id, $payload + ['title' => 'Prepare revised drawings', 'detail' => 'Level three coordination', 'due_on' => '2026-10-12'],
            $record?->id);
    }

    private function confirm(array $preview, mixed $confirmed = true): array
    {
        return $this->service->confirm($this->actor, $preview['id'], $preview['preview_token'], $preview['version'], $confirmed);
    }

    private function reject(callable $callback, int $status): void
    {
        try {
            $callback();
            $this->fail('Expected request to be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame($status, $e->status);
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());
        }
    }

    public function test_proposal_and_preview_do_not_create_operational_records(): void
    {
        $preview = $this->propose();
        $this->assertSame($preview, $this->service->preview($this->actor, $preview['id']));
        $this->assertSame('site_operations_board', $preview['visibility']);
        $this->assertNull($preview['before']);
        $this->assertSame('pending', $preview['status']);
        $this->assertDatabaseCount('ops_action_items', 0);
        $this->assertDatabaseCount('assistant_proposals', 1);
        $proposal = AssistantProposal::findOrFail($preview['id']);
        $this->assertSame($this->actor->id, $proposal->created_by_id);
        $this->assertSame('created', $proposal->audit_events[0]['event']);
        $this->assertArrayNotHasKey('preview_token', $proposal->toArray());
    }

    public function test_confirm_creates_exact_preview_once_and_persists_audited_result(): void
    {
        $preview = $this->propose();
        $result = $this->confirm($preview);
        $again = $this->confirm($preview);
        $this->assertSame($result, $again);
        $this->assertSame($preview['after'], $result['after']);
        $this->assertSame('applied', $result['status']);
        $this->assertDatabaseCount('ops_action_items', 1);
        $this->assertDatabaseHas('ops_action_items', ['id' => $result['record_id'], 'site_id' => $this->site->id,
            'title' => 'Prepare revised drawings', 'kind' => 'todo', 'status' => 'open', 'assignee' => null]);
        $proposal = AssistantProposal::findOrFail($preview['id']);
        $this->assertSame($result, $proposal->result);
        $this->assertSame(['created', 'applied'], array_column($proposal->audit_events, 'event'));
        $this->assertSame($this->actor->id, $proposal->audit_events[1]['actor_id']);
        Http::assertNothingSent();
    }

    public function test_update_reuses_the_existing_todo_and_preview_makes_cleared_fields_explicit(): void
    {
        $todo = $this->todo(['occurred_on' => '2026-10-01']);
        $preview = $this->service->create($this->actor, AssistantProposalService::UPDATE_TODO, $this->site->id,
            ['title' => 'Collect site drawings'], $todo->id);
        $this->assertSame('Bring updated drawings', $todo->fresh()->title);
        $this->assertSame('For the coordination meeting', $preview['before']['detail']);
        $this->assertNull($preview['after']['detail']);
        $this->assertNull($preview['after']['due_on']);
        $result = $this->confirm($preview);
        $this->assertSame($todo->id, $result['record_id']);
        $this->assertDatabaseCount('ops_action_items', 1);
        $todo->refresh();
        $this->assertNull($todo->detail);
        $this->assertNull($todo->due_on);
        $this->assertSame('2026-10-01', $todo->occurred_on->toDateString());
    }

    public function test_disabled_service_refuses_both_proposal_and_confirmation(): void
    {
        $preview = $this->propose();
        config(['ai_assistant.mutations_enabled' => false]);
        $this->reject(fn () => $this->propose(), 403);
        $this->reject(fn () => $this->confirm($preview), 403);
        $this->assertSame('pending', $this->service->preview($this->actor, $preview['id'])['status']);
        $this->assertSame('cancelled', $this->service->cancel($this->actor, $preview['id'])['status']);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public function test_explicit_true_and_exact_original_preview_token_and_version_are_required(): void
    {
        $preview = $this->propose();
        foreach ([false, null, 'true', 'false', 1] as $notConfirmed) {
            $this->reject(fn () => $this->confirm($preview, $notConfirmed), 422);
        }
        $this->reject(fn () => $this->service->confirm($this->actor, $preview['id'], 'bad-token', $preview['version'], true), 409);
        $this->reject(fn () => $this->service->confirm($this->actor, $preview['id'], $preview['preview_token'], 'wrong-version', true), 409);
        $this->assertDatabaseCount('ops_action_items', 0);
        $this->assertSame('pending', AssistantProposal::find($preview['id'])->status);
    }

    public function test_other_actor_cannot_preview_confirm_or_cancel_even_with_token(): void
    {
        $preview = $this->propose();
        $other = $this->actor(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $this->reject(fn () => $this->service->preview($other, $preview['id']), 404);
        $this->reject(fn () => $this->service->confirm($other, $preview['id'], $preview['preview_token'], $preview['version'], true), 404);
        $this->reject(fn () => $this->service->cancel($other, $preview['id']), 404);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public function test_expired_proposal_cannot_execute_and_expiration_is_audited(): void
    {
        $preview = $this->propose();
        $this->travel(16)->minutes();
        $this->reject(fn () => $this->confirm($preview), 409);
        $proposal = AssistantProposal::findOrFail($preview['id']);
        $this->assertSame('expired', $proposal->status);
        $this->assertSame(['created', 'expired'], array_column($proposal->audit_events, 'event'));
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public function test_successful_confirmation_remains_idempotent_after_expiry(): void
    {
        $preview = $this->propose();
        $result = $this->confirm($preview);
        $this->travel(16)->minutes();
        $this->assertSame($result, $this->confirm($preview));
        $this->assertDatabaseCount('ops_action_items', 1);
    }

    public function test_cancel_is_idempotent_and_prevents_confirmation(): void
    {
        $preview = $this->propose();
        $result = $this->service->cancel($this->actor, $preview['id']);
        $this->assertSame($result, $this->service->cancel($this->actor, $preview['id']));
        $this->assertSame('cancelled', $result['status']);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertSame(['created', 'cancelled'], array_column(AssistantProposal::find($preview['id'])->audit_events, 'event'));
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public function test_cancel_does_not_undo_an_applied_record(): void
    {
        $preview = $this->propose();
        $this->confirm($preview);
        $this->reject(fn () => $this->service->cancel($this->actor, $preview['id']), 409);
        $this->assertDatabaseCount('ops_action_items', 1);
    }

    public function test_edited_original_is_not_overwritten_even_when_updated_at_is_unchanged(): void
    {
        $todo = $this->todo();
        $preview = $this->propose($todo);
        DB::table('ops_action_items')->where('id', $todo->id)->update(['title' => 'Human revised the instructions']);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertSame('Human revised the instructions', $todo->fresh()->title);
        $proposal = AssistantProposal::findOrFail($preview['id']);
        $this->assertSame('stale', $proposal->status);
        $this->assertSame('record_changed', $proposal->audit_events[1]['reason']);
    }

    public function test_deleted_original_is_not_recreated(): void
    {
        $todo = $this->todo();
        $preview = $this->propose($todo);
        $todo->delete();
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertDatabaseCount('ops_action_items', 0);
        $this->assertSame('stale', AssistantProposal::find($preview['id'])->status);
    }

    public function test_two_proposals_for_one_original_cannot_overwrite_each_other(): void
    {
        $todo = $this->todo();
        $first = $this->propose($todo, ['title' => 'First approved revision']);
        $second = $this->propose($todo, ['title' => 'Second proposed revision']);
        $this->confirm($first);
        $this->reject(fn () => $this->confirm($second), 409);
        $this->assertSame('First approved revision', $todo->fresh()->title);
    }

    public function test_current_permissions_are_reloaded_instead_of_trusting_the_passed_actor(): void
    {
        $preview = $this->propose();
        User::whereKey($this->actor->id)->update(['account_status' => 'suspended']);
        $this->reject(fn () => $this->confirm($preview), 403);
        $this->reject(fn () => $this->service->preview($this->actor, $preview['id']), 403);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public function test_revoked_company_membership_prevents_confirmation(): void
    {
        $preview = $this->propose();
        $this->actor->companies()->detach();
        $this->reject(fn () => $this->confirm($preview), 403);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public function test_changed_but_still_authorized_scope_requires_a_new_preview(): void
    {
        $preview = $this->propose();
        User::whereKey($this->actor->id)->update(['access_scope' => 'company']);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertSame('stale', AssistantProposal::find($preview['id'])->status);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public function test_cross_site_and_cross_company_records_are_not_accepted(): void
    {
        $otherCompany = Company::create(['code' => 'AP-OTHER', 'name' => 'Other company', 'status' => 'active']);
        $otherSite = Site::create(['company_id' => $otherCompany->id, 'code' => 'AP-OTHER', 'name' => 'Other site', 'status' => 'active']);
        $otherTodo = $this->todo(['site_id' => $otherSite->id]);
        $this->reject(fn () => $this->service->create($this->actor, AssistantProposalService::CREATE_TODO, $otherSite->id, ['title' => 'Bring plans']), 403);
        $this->reject(fn () => $this->propose($otherTodo), 404);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_authorized_admin_can_select_another_active_company_site(): void
    {
        $otherCompany = Company::create(['code' => 'AP-ADMIN', 'name' => 'Another company', 'status' => 'active']);
        $otherSite = Site::create(['company_id' => $otherCompany->id, 'code' => 'AP-ADMIN', 'name' => 'Another site', 'status' => 'active']);
        $admin = $this->actor(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $preview = $this->service->create($admin, AssistantProposalService::CREATE_TODO, $otherSite->id, ['title' => 'Bring site plans']);
        $result = $this->service->confirm($admin, $preview['id'], $preview['preview_token'], $preview['version'], true);
        $this->assertSame($otherSite->id, $result['site_id']);
        $this->assertSame($otherCompany->id, $result['company_id']);
    }

    public function test_readonly_and_nonmanager_roles_cannot_create_proposals(): void
    {
        foreach (['worker', 'foreman', 'vendor_admin', 'viewer', 'client', 'payroll', 'safety_manager'] as $role) {
            $actor = $this->actor(['access_role' => $role]);
            $this->reject(fn () => $this->service->create($actor, AssistantProposalService::CREATE_TODO, $this->site->id, ['title' => 'Bring drawings']), 403);
        }
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_fixed_operations_reject_dispatch_and_unapproved_fields(): void
    {
        foreach (['sql.execute', 'users.update', 'wbs.update', 'payment.create'] as $operation) {
            $this->reject(fn () => $this->service->create($this->actor, $operation, $this->site->id, ['title' => 'Bring plans']), 422);
        }
        foreach (['id', 'site_id', 'company_id', 'kind', 'status', 'assignee', 'requester', 'is_blocker', 'amount', 'sql', 'tool'] as $field) {
            $this->reject(fn () => $this->propose(null, [$field => 'untrusted input']), 422);
        }
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_financial_text_is_not_accepted_even_for_an_admin(): void
    {
        $this->actor->update(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        foreach (['Pay invoice 500 USD', '급여 정산', 'Contract amount approval'] as $title) {
            $this->reject(fn () => $this->propose(null, ['title' => $title]), 422);
        }
        $financial = $this->todo(['title' => 'Payment approval']);
        $this->reject(fn () => $this->propose($financial), 422);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_existing_workflow_records_cannot_be_changed_through_simple_todo_operation(): void
    {
        foreach ([['kind' => 'approval'], ['kind' => 'decision'], ['status' => 'done'], ['is_blocker' => true],
            ['assignee' => 'Site lead'], ['requester' => 'Client'], ['done_at' => now()], ['done_by_id' => $this->actor->id]] as $attributes) {
            $this->reject(fn () => $this->propose($this->todo($attributes)), 422);
        }
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_invalid_payload_and_invalid_target_combinations_are_rejected(): void
    {
        foreach ([['title' => '   '], ['title' => str_repeat('a', 256)], ['detail' => str_repeat('a', 4001)], ['due_on' => '2026-02-30'], ['due_on' => 'tomorrow']] as $payload) {
            $this->reject(fn () => $this->propose(null, $payload), 422);
        }
        $this->reject(fn () => $this->service->create($this->actor, AssistantProposalService::CREATE_TODO, $this->site->id, ['title' => 'Bring plans'], 12), 422);
        $this->reject(fn () => $this->service->create($this->actor, AssistantProposalService::UPDATE_TODO, $this->site->id, ['title' => 'Bring plans']), 422);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_tampered_saved_payload_cannot_be_applied_with_original_approval(): void
    {
        $preview = $this->propose();
        AssistantProposal::whereKey($preview['id'])->update(['payload' => ['title' => 'Different instructions', 'detail' => null, 'due_on' => null]]);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    private function dailyPlan(): DailyClosingReport
    {
        $saved = app(DailyPlanService::class)->save($this->site->id, '2026-10-10', [
            'workScope' => 'Review level three drawings', 'notes' => 'Coordination meeting',
            'weather' => 'Sunny', 'temperature' => '20', 'tbmTime' => '07:00', 'tbmLeader' => 'Site lead',
            'tbmHeadcount' => 3,
            'crews' => [['company' => 'Proposal Co', 'trade' => 'MEP', 'headcount' => 3, 'location' => 'Level three', 'work' => 'Drawing review']],
            'equipment' => [['name' => 'Lift', 'code' => 'EQ-1', 'use' => 'Access']],
            'permits' => [['no' => 'PT-1', 'type' => 'General', 'title' => 'Existing permit']],
            'hazards' => [['hazard' => 'Access route', 'control' => 'Follow the existing approved plan']],
        ], null, false);

        return DailyClosingReport::findOrFail($saved['reportId']);
    }

    private function proposePlan(DailyClosingReport $report, array $payload = []): array
    {
        return $this->service->create($this->actor, AssistantProposalService::UPDATE_DAILY_PLAN,
            $this->site->id, $payload + ['work_scope' => 'Review levels three and four drawings', 'notes' => 'Bring revised layouts'], $report->id);
    }

    public function test_daily_plan_changes_only_reviewed_draft_text_and_records_actor(): void
    {
        $report = $this->dailyPlan();
        $original = $report->getRawOriginal();
        $plan = $report->plan;
        $preview = $this->proposePlan($report);
        $this->assertSame('site_daily_plan', $preview['visibility']);
        $this->assertSame('2026-10-10', $preview['before']['report_date']);
        $this->assertNull($preview['before']['plan_by_id']);
        $this->assertSame($this->actor->id, $preview['after']['plan_by_id']);
        $this->assertSame($plan, $report->fresh()->plan);
        $result = $this->confirm($preview);
        $this->assertSame($result, $this->confirm($preview));
        $report->refresh();
        $expected = array_replace($plan, ['workScope' => 'Review levels three and four drawings', 'notes' => 'Bring revised layouts']);
        $this->assertSame($expected, $report->plan);
        $this->assertSame($report->id, $result['record_id']);
        $this->assertSame('draft', $report->plan_status);
        $this->assertSame('open', $report->status);
        $this->assertNull($report->plan_submitted_at);
        $unchanged = array_flip(['plan', 'plan_by_id', 'updated_at']);
        $this->assertSame(array_diff_key($original, $unchanged), array_diff_key($report->getRawOriginal(), $unchanged));
        $this->assertDatabaseCount('daily_closing_reports', 1);
        $this->assertDatabaseCount('report_dispatches', 0);
        $this->assertDatabaseCount('ops_action_items', 0);
        Http::assertNothingSent();
    }

    public function test_daily_plan_refuses_submission_safety_crew_and_date_payload_fields(): void
    {
        $report = $this->dailyPlan();
        foreach (['submit', 'plan_status', 'report_date', 'crews', 'equipment', 'hazards', 'permits', 'tbmTime', 'plan_by_id'] as $field) {
            $this->reject(fn () => $this->proposePlan($report, [$field => 'untrusted']), 422);
        }
        $this->reject(fn () => $this->proposePlan($report, ['work_scope' => '   ']), 422);
        $this->reject(fn () => $this->proposePlan($report, ['work_scope' => str_repeat('a', 8001)]), 422);
        $this->reject(fn () => $this->proposePlan($report, ['notes' => 'Pay invoice']), 422);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_daily_plan_only_allows_an_existing_open_unsubmitted_draft(): void
    {
        $report = $this->dailyPlan();
        $original = $report->getAttributes();
        foreach ([['plan_status' => 'submitted'], ['status' => 'done'], ['status' => 'writing'],
            ['plan_submitted_at' => now()], ['closed_at' => now()], ['closed_by_id' => $this->actor->id], ['plan' => []]] as $attributes) {
            $report->setRawAttributes($original)->fill($attributes)->save();
            $this->reject(fn () => $this->proposePlan($report), 422);
        }
        $this->reject(fn () => $this->service->create($this->actor, AssistantProposalService::UPDATE_DAILY_PLAN,
            $this->site->id, ['work_scope' => 'Prepare drawings']), 422);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_daily_plan_concurrent_submission_prevents_stale_edit(): void
    {
        $report = $this->dailyPlan();
        $preview = $this->proposePlan($report);
        $report->update(['plan_status' => 'submitted', 'plan_submitted_at' => now()]);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertSame('submitted', $report->fresh()->plan_status);
        $this->assertSame('Review level three drawings', $report->fresh()->plan['workScope']);
        $this->assertSame('stale', AssistantProposal::find($preview['id'])->status);
    }

    public function test_daily_plan_rolls_back_if_domain_normalization_would_drop_existing_metadata(): void
    {
        $report = $this->dailyPlan();
        $report->update(['plan' => $report->plan + ['important_legacy_note' => 'Keep this field']]);
        $plan = $report->plan;
        $preview = $this->proposePlan($report);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertSame($plan, $report->fresh()->plan);
        $this->assertNull($report->fresh()->plan_by_id);
        $this->assertSame('pending', AssistantProposal::find($preview['id'])->status);
    }

    public function test_daily_plan_is_rechecked_for_company_site_and_full_row_changes(): void
    {
        $report = $this->dailyPlan();
        $preview = $this->proposePlan($report);
        DB::table('daily_closing_reports')->where('id', $report->id)->update(['work_today' => 'The human updated the field report']);
        $this->reject(fn () => $this->confirm($preview), 409);
        $this->assertSame('Review level three drawings', $report->fresh()->plan['workScope']);
        $this->assertSame('stale', AssistantProposal::find($preview['id'])->status);
    }

    public function test_writer_failure_rolls_back_business_write_and_preserves_pending_proposal(): void
    {
        $preview = $this->propose();
        $writer = Mockery::mock(OpsActionService::class);
        $writer->shouldReceive('save')->once()->andReturnUsing(function (): array {
            $this->todo();

            return ['success' => false];
        });
        $service = new AssistantProposalService($writer);
        $this->reject(fn () => $service->confirm($this->actor, $preview['id'], $preview['preview_token'], $preview['version'], true), 409);
        $this->assertDatabaseCount('ops_action_items', 0);
        $this->assertSame('pending', AssistantProposal::find($preview['id'])->status);
    }
}
