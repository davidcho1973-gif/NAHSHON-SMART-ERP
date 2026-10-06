<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DailyClosingReport;
use App\Models\Employee;
use App\Models\IntelligentDocument;
use App\Models\OpsActionItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Assistant\AssistantProposalService;
use App\Support\AiAssistantBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Model output is an editable field patch, never a proposal or permission to write. */
class AssistantDraftSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/ask-api/workspace/suggestions';

    private Company $company;

    private Site $site;

    private User $actor;

    private array $providerPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['ai_assistant.enabled' => true, 'ai_assistant.mutations_enabled' => true,
            'ai_assistant.company_daily_requests' => 200, 'ai_assistant.company_monthly_requests' => 3000,
            'ai_assistant.user_daily_requests' => 20, 'ai_assistant.user_monthly_requests' => 300,
            'ai_assistant.max_input_bytes' => 64000, 'ai_assistant.max_output_tokens' => 1200,
            'ai_assistant.companies' => [], 'services.anthropic.api_key' => 'synthetic-suggestion-key',
            'services.anthropic.endpoint' => 'https://api.anthropic.com', 'filesystems.documents_disk' => 'public']);
        $this->company = Company::create(['code' => 'SUGGEST-CO', 'name' => 'COMPANY_PRIVATE_SENTINEL', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'SUGGEST-SITE', 'name' => 'SITE_PRIVATE_SENTINEL', 'status' => 'active']);
        $this->actor = User::factory()->create(['account_status' => 'active', 'access_role' => 'site_manager', 'access_scope' => 'site',
            'allowed_company_id' => $this->company->id, 'allowed_site_id' => $this->site->id]);
        $this->actor->companies()->attach($this->company);
    }

    private function input(array $overrides = []): array
    {
        return $overrides + ['operation' => AssistantProposalService::CREATE_TODO, 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'record_id' => null, 'source_document_id' => null,
            'request_text' => 'Prepare revised drawings for coordination on 2026-10-12.'];
    }

    private function suggest(array $overrides = []): TestResponse
    {
        return $this->actingAsPurchaseUser($this->actor)->postJson(self::ENDPOINT, $this->input($overrides));
    }

    private function fakeProvider(mixed $fields = ['title' => 'Prepare revised drawings'], array $questions = [], ?callable $during = null): void
    {
        $this->fakeRaw(['stop_reason' => 'end_turn', 'content' => [['type' => 'text',
            'text' => json_encode(['fields' => is_array($fields) ? (object) $fields : $fields, 'questions' => $questions], JSON_THROW_ON_ERROR)]]], $during);
    }

    private function fakeRaw(array $body, ?callable $during = null, int $status = 200): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => function (Request $request) use ($body, $during, $status) {
            $this->providerPayloads[] = $request->data();
            if ($during) {
                $during();
            }

            return Http::response($body, $status);
        }]);
    }

    private function finance(): void
    {
        $this->actor->update(['access_role' => 'admin']);
    }

    private function todo(array $extra = []): OpsActionItem
    {
        return OpsActionItem::create($extra + ['site_id' => $this->site->id, 'kind' => 'todo', 'title' => 'RECORD_PRIVATE_SENTINEL',
            'detail' => 'Existing coordination notes', 'status' => 'open', 'is_blocker' => false]);
    }

    private function document(array $extra = []): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $bytes = "%PDF-1.4\nRECEIPT_CONTENT_PRIVATE_SENTINEL ".$uuid;
        $path = 'documents/'.$uuid.'.pdf';
        Storage::disk('local')->put($path, $bytes);

        return IntelligentDocument::create($extra + ['uuid' => $uuid, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'uploaded_by' => $this->actor->id, 'owner_user_id' => $this->actor->id, 'access_level' => 'scope', 'confidentiality' => 'internal',
            'disk' => 'local', 'file_path' => $path, 'original_file_name' => 'synthetic-layout.pdf', 'stored_file_name' => $uuid.'.pdf',
            'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'title' => 'DOCUMENT_PRIVATE_SENTINEL', 'summary' => 'SOURCE_SUMMARY_PRIVATE_SENTINEL', 'search_text' => 'SOURCE_TEXT_PRIVATE_SENTINEL',
            'category' => 'general', 'document_type' => 'drawing', 'ai_status' => 'ready',
            'folder_structure' => ['SUGGEST-CO', 'SUGGEST-SITE', 'general', 'drawing', '2026'],
            'virtual_path' => 'SUGGEST-CO / SUGGEST-SITE / general / drawing / 2026']);
    }

    private function receipt(array $extra = []): IntelligentDocument
    {
        return $this->document($extra + ['document_type' => 'receipt', 'category' => 'finance', 'ai_payload' => ['money' => ['currency' => 'USD']]]);
    }

    private function target(string $operation): ?int
    {
        return match ($operation) {
            AssistantProposalService::UPDATE_TODO => $this->todo()->id,
            AssistantProposalService::UPDATE_DAILY_PLAN => DailyClosingReport::create(['site_id' => $this->site->id,
                'report_date' => '2026-10-12', 'status' => 'open', 'plan_status' => 'draft', 'plan' => ['workScope' => 'EXISTING_PLAN_PRIVATE_SENTINEL']])->id,
            AssistantProposalService::UPDATE_DOCUMENT_CATEGORY => $this->document()->id,
            default => null,
        };
    }

    private function businessSnapshot(): array
    {
        $snapshot = [];
        foreach (['assistant_proposals', 'assistant_checks', 'document_questions', 'communication_messages', 'ops_action_items',
            'daily_closing_reports', 'mobile_expenses', 'intelligent_documents', 'integrated_documents', 'report_dispatches'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach (['local', 'public'] as $disk) {
            $snapshot[$disk] = [];
            foreach (Storage::disk($disk)->allFiles() as $path) {
                $snapshot[$disk][$path] = hash('sha256', Storage::disk($disk)->get($path));
            }
        }

        return $snapshot;
    }

    private function assertNoOutboundEffects(): void
    {
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function operations(): array
    {
        return [
            'create todo' => ['ops.todo.create', ['title' => 'Prepare revised drawings', 'detail' => 'Coordination', 'due_on' => '2026-10-12'], ['title']],
            'update todo' => ['ops.todo.update', ['title' => 'Prepare revised drawings', 'detail' => 'Coordination', 'due_on' => '2026-10-12'], ['title']],
            'update plan' => ['daily_plan.draft.update', ['work_scope' => 'Review revised drawings', 'notes' => 'Coordination'], ['work_scope']],
            'create report' => ['daily_report.draft.create', ['report_date' => '2026-10-12', 'work_title' => 'Drawing review', 'work_today' => 'Reviewed layout', 'work_tomorrow' => 'Continue coordination'], ['report_date', 'work_title', 'work_today']],
            'create expense' => ['expense.pending.create', ['description' => 'Office supplies', 'amount' => '123.40', 'expense_date' => '2026-10-12', 'accounting_account' => '6601 Office Supplies', 'payment_type' => 'corporate'], ['description', 'amount', 'expense_date', 'accounting_account', 'payment_type']],
            'update category' => ['document.category.update', ['category' => 'drawing_spec'], ['category']],
        ];
    }

    #[DataProvider('operations')]
    public function test_each_operation_returns_only_editable_fields_with_fixed_scope_and_no_writes(string $operation, array $fields, array $required): void
    {
        if ($operation === AssistantProposalService::CREATE_EXPENSE) {
            $this->finance();
        }
        $recordId = $this->target($operation);
        $before = $this->businessSnapshot();
        $this->fakeProvider($fields);
        $response = $this->suggest(['operation' => $operation, 'record_id' => $recordId,
            'request_text' => 'Review drawings on 2026-10-12. Office supplies total USD 123.40, corporate payment.']);
        // Non-expense requests retain the technical-text policy.
        if ($operation !== AssistantProposalService::CREATE_EXPENSE) {
            $response->assertUnprocessable();
            Http::assertNothingSent();
            $response = $this->suggest(['operation' => $operation, 'record_id' => $recordId]);
        }
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertEquals(['operation' => $operation, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'record_id' => $recordId, 'source_document_id' => null, 'fields' => $fields, 'questions' => [], 'missing_fields' => []], $response->json('suggestion'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('ai_assistant_requests', 1);
        $this->assertDatabaseHas('ai_assistant_requests', ['feature' => 'draft_suggestion', 'company_id' => $this->company->id, 'user_id' => $this->actor->id]);
        Http::assertSentCount(1);
        $this->assertNoOutboundEffects();
    }

    #[DataProvider('operations')]
    public function test_null_partial_fields_are_omitted_and_required_fields_are_derived_on_the_server(string $operation, array $fields, array $required): void
    {
        if ($operation === AssistantProposalService::CREATE_EXPENSE) {
            $this->finance();
        }
        $recordId = $this->target($operation);
        $this->fakeProvider(array_fill_keys(array_keys($fields), null));
        $response = $this->suggest(['operation' => $operation, 'record_id' => $recordId])->assertOk();
        $this->assertSame([], $response->json('suggestion.fields'));
        $this->assertEqualsCanonicalizing($required, $response->json('suggestion.missing_fields'));
        $this->assertNotEmpty($response->json('suggestion.questions'));
        $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->suggestion->fields);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_requires_current_strong_authentication_and_rejects_device_only_sessions(): void
    {
        $this->fakeProvider();
        $this->assertContains($this->postJson(self::ENDPOINT, $this->input())->status(), [401, 403]);
        $this->actingAs($this->actor)->postJson(self::ENDPOINT, $this->input())->assertForbidden();
        $this->actingAsPurchaseUser($this->actor)->withSession(['worker_device_only' => true])->postJson(self::ENDPOINT, $this->input())->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_production_csrf_is_required_even_for_a_strongly_authenticated_actor(): void
    {
        $this->fakeProvider();
        $this->actingAsPurchaseUser($this->actor);
        $this->app['env'] = 'production';
        try {
            $this->postJson(self::ENDPOINT, $this->input())->assertStatus(419);
            $this->withHeader('X-CSRF-TOKEN', 'forged-token')->postJson(self::ENDPOINT, $this->input())->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public static function invalidRequests(): array
    {
        return [
            'unknown operation' => [['operation' => 'expense.pay']], 'create has target' => [['record_id' => 1]],
            'update lacks target' => [['operation' => 'ops.todo.update']], 'missing company' => [['company_id' => null]],
            'missing site' => [['site_id' => null]], 'negative target' => [['record_id' => -1]], 'fractional site' => [['site_id' => 1.5]],
            'empty request' => [['request_text' => '   ']], 'long request' => [['request_text' => str_repeat('가', 2001)]],
            'source on todo' => [['source_document_id' => 1]], 'client actor' => [['actor_id' => 1]],
            'client role' => [['role' => 'super_admin']], 'client provider' => [['provider' => 'attacker']],
            'client model' => [['model' => 'arbitrary']], 'client token limit' => [['max_tokens' => 1000000]],
            'client tool' => [['execute' => 'delete']], 'client approval' => [['confirmed' => true]],
            'client payload' => [['payload' => ['title' => 'Injected']]],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_request_shapes_never_reserve_or_call_the_provider(array $overrides): void
    {
        $this->fakeProvider();
        $this->suggest($overrides)->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_company_must_match_selected_site_and_membership_must_be_current(): void
    {
        $other = Company::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'status' => 'active']);
        $this->fakeProvider();
        $this->suggest(['company_id' => $other->id])->assertForbidden();
        $this->actor->companies()->detach();
        $this->suggest()->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_closed_assigned_deleted_and_other_site_targets_are_rejected_before_provider(): void
    {
        $other = Site::create(['company_id' => $this->company->id, 'code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->fakeProvider();
        foreach ([['status' => 'done'], ['assignee' => 'Someone'], ['is_blocker' => true], ['site_id' => $other->id]] as $attributes) {
            $record = $this->todo($attributes);
            $response = $this->suggest(['operation' => 'ops.todo.update', 'record_id' => $record->id]);
            $this->assertContains($response->status(), [404, 422]);
        }
        $record = $this->todo();
        $record->delete();
        $this->suggest(['operation' => 'ops.todo.update', 'record_id' => $record->id])->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_expense_requires_finance_permission_before_source_access_or_provider_work(): void
    {
        $this->fakeProvider();
        $this->suggest(['operation' => 'expense.pending.create', 'source_document_id' => 999999])->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public static function permissionChanges(): array
    {
        return array_map(fn ($change) => [$change], ['suspended', 'role', 'site', 'membership', 'site_inactive', 'company_inactive', 'site_company']);
    }

    #[DataProvider('permissionChanges')]
    public function test_permission_changes_during_provider_call_suppress_the_result_and_keep_quota(string $change): void
    {
        $this->fakeProvider(during: function () use ($change): void {
            match ($change) {
                'suspended' => User::whereKey($this->actor->id)->update(['account_status' => 'suspended']),
                'role' => User::whereKey($this->actor->id)->update(['access_role' => 'worker']),
                'site' => User::whereKey($this->actor->id)->update(['allowed_site_id' => null]),
                'membership' => $this->actor->companies()->detach(),
                'site_inactive' => $this->site->update(['status' => 'inactive']),
                'company_inactive' => $this->company->update(['status' => 'inactive']),
                'site_company' => $this->site->update(['company_id' => Company::create(['code' => 'REASSIGNED', 'name' => 'Reassigned', 'status' => 'active'])->id]),
            };
        });
        $response = $this->suggest();
        $this->assertContains($response->status(), [403, 409]);
        $response->assertJsonMissingPath('suggestion');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
        $this->assertDatabaseCount('assistant_proposals', 0);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public static function targetChanges(): array
    {
        return [['ops.todo.update', 'title', 'Human corrected task'], ['daily_plan.draft.update', 'weather', 'Cloudy'], ['document.category.update', 'title', 'Human corrected title']];
    }

    #[DataProvider('targetChanges')]
    public function test_selected_target_changes_during_provider_call_are_never_returned_or_overwritten(string $operation, string $field, string $value): void
    {
        $id = $this->target($operation);
        $table = match ($operation) {
            'ops.todo.update' => 'ops_action_items', 'daily_plan.draft.update' => 'daily_closing_reports', default => 'intelligent_documents'
        };
        $this->fakeProvider([], [], fn () => DB::table($table)->where('id', $id)->update([$field => $value]));
        $this->suggest(['operation' => $operation, 'record_id' => $id])->assertConflict()->assertJsonMissingPath('suggestion');
        $this->assertDatabaseHas($table, ['id' => $id, $field => $value]);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
        $this->assertDatabaseCount('assistant_proposals', 0);
        Http::assertSentCount(1);
    }

    public static function malformedOutputs(): array
    {
        return [
            'root list' => ['[]'], 'root scalar' => ['"unsafe"'], 'missing questions' => ['{"fields":{"title":"Safe"}}'],
            'field list' => ['{"fields":[],"questions":[]}'], 'nested field' => ['{"fields":{"title":{"execute":true}},"questions":[]}'],
            'numeric field' => ['{"fields":{"title":12},"questions":[]}'], 'unknown field' => ['{"fields":{"title":"Safe","company_id":"12"},"questions":[]}'],
            'root action' => ['{"fields":{"title":"Safe"},"questions":[],"execute":true}'],
            'root scope' => ['{"fields":{},"questions":[],"site_id":12}'], 'bad JSON' => ['PRIVATE_PROVIDER_BODY_SENTINEL'],
            'long title' => [json_encode(['fields' => ['title' => str_repeat('x', 256)], 'questions' => []])],
            'long question' => [json_encode(['fields' => (object) [], 'questions' => [str_repeat('x', 201)]])],
            'too many questions' => [json_encode(['fields' => (object) [], 'questions' => array_fill(0, 6, 'What?')])],
            'nontext question' => ['{"fields":{},"questions":[{"execute":true}]}'],
            'invalid date' => ['{"fields":{"due_on":"2026-02-30"},"questions":[]}'],
        ];
    }

    #[DataProvider('malformedOutputs')]
    public function test_malformed_or_action_bearing_provider_output_is_rejected_as_a_whole(string $text): void
    {
        $before = $this->businessSnapshot();
        $this->fakeRaw(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]]);
        $response = $this->suggest()->assertUnprocessable()->assertJsonMissingPath('suggestion');
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_BODY_SENTINEL', $response->getContent());
        $this->assertSame($before, $this->businessSnapshot());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
    }

    public static function stoppedOutputs(): array
    {
        return [['max_tokens', 422], ['refusal', 503], ['tool_use', 422], [null, 422]];
    }

    #[DataProvider('stoppedOutputs')]
    public function test_incomplete_or_refused_output_is_not_salvaged_or_retried(?string $reason, int $status): void
    {
        $this->fakeRaw(['stop_reason' => $reason, 'content' => [['type' => 'text', 'text' => '{"fields":{"title":"Safe"},"questions":[]}']]]);
        $this->suggest()->assertStatus($status)->assertJsonMissingPath('suggestion');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_provider_failure_is_sanitized_charged_and_never_automatically_retried(): void
    {
        $this->fakeRaw(['error' => 'PRIVATE_PROVIDER_BODY_SENTINEL synthetic-suggestion-key'], status: 500);
        $response = $this->suggest()->assertStatus(503)->assertJsonMissingPath('suggestion');
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_BODY_SENTINEL', $response->getContent());
        $this->assertStringNotContainsString('synthetic-suggestion-key', $response->getContent());
        Http::assertSentCount(1);
        $row = DB::table('ai_assistant_requests')->sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_BODY_SENTINEL', json_encode($row));
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public static function ungroundedAmounts(): array
    {
        return [
            'absent currency' => ['Office supplies total 123.40.', '123.40'],
            'rounded precision' => ['Office supplies total USD 123.4.', '123.40'],
            'converted currency' => ['Office supplies EUR 100; convert to USD 123.40.', '123.40'],
            'invented sum' => ['Office supplies USD 100 and USD 23.40.', '123.40'],
            'ambiguous thousand separator' => ['Office supplies total USD 1,000.00.', '1000.00'],
            'date mistaken for amount' => ['Office supplies on 2026-10-12, currency USD.', '2026'],
            'subtotal mistaken for total' => ['Office supplies subtotal USD 123.40 plus tax.', '123.40'],
            'multiple amounts' => ['Office supplies USD 123.40 and USD 15.00.', '123.40'],
            'negative amount substring' => ['Refund -123.40 USD.', '123.40'],
            'unit price' => ['USD 20 per item, 5 items', '20'],
            'unlabelled currency amount' => ['Office supplies USD 20.', '20'],
            'tax added to labeled total' => ['total USD 20 plus USD 2 tax', '20'],
            'total before tax' => ['total USD 20 before tax', '20'],
            'tax explicitly excluded' => ['total USD 20 excluding tax', '20'],
            'rate labeled as total' => ['total USD 20 per unit', '20'],
            'slash unit rate' => ['total USD 20/item', '20'],
            'Korean unit rate' => ['개당 USD 20, 5개', '20'],
            'Korean excluded VAT' => ['총액 USD 20, 부가세 별도', '20'],
            'Korean excluded tax' => ['합계 USD 20 세금 제외', '20'],
            'Spanish unit rate' => ['USD 20 por unidad, cinco unidades', '20'],
            'Spanish excluded tax' => ['total USD 20 sin impuestos', '20'],
            'Spanish additional VAT' => ['total USD 20 más IVA', '20'],
            'spaced negative prefix' => ['total - USD 20', '20'],
            'minus word' => ['total minus USD20', '20'],
            'Unicode negative prefix' => ['total − USD 20', '20'],
            'refund' => ['Refund total USD 20', '20'],
            'credit adjustment' => ['Credit total USD 20', '20'],
            'Korean refund' => ['환불 총액 USD 20', '20'],
            'Spanish credit' => ['Crédito total USD 20', '20'],
            'scientific notation prefix' => ['total USD 1e3', '1'],
            'scientific notation suffix' => ['total 1e3 USD', '3'],
            'letter continuation' => ['total USD 20abc', '20'],
            'underscore continuation' => ['total USD 20_code', '20'],
            'multiple receipt totals' => ['Receipt A total USD 20; receipt B total USD 30', '20'],
            'multiple receipt totals second' => ['Receipt A total USD 20; receipt B total USD 30', '30'],
            'hourly rate labeled total' => ['Hourly rate total USD 20', '20'],
            'tax inclusive subtotal' => ['Subtotal USD 20 including tax', '20'],
            'Korean tax inclusive subtotal' => ['소계 USD 20 세금 포함', '20'],

        ];
    }

    #[DataProvider('ungroundedAmounts')]
    public function test_unstated_ambiguous_calculated_or_converted_amounts_stay_blank(string $text, string $amount): void
    {
        $this->finance();
        $this->fakeProvider(['amount' => $amount]);
        $response = $this->suggest(['operation' => 'expense.pending.create', 'request_text' => $text])->assertOk();
        $response->assertJsonMissingPath('suggestion.fields.amount');
        $this->assertContains('amount', $response->json('suggestion.missing_fields'));
        $this->assertNotEmpty($response->json('suggestion.questions'));
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public static function ungroundedDates(): array
    {
        return [['tomorrow'], ['next Friday'], ['10/12/2026'], ['2026-10-11']];
    }

    #[DataProvider('ungroundedDates')]
    public function test_relative_ambiguous_or_different_dates_stay_blank(string $date): void
    {
        $this->fakeProvider(['title' => 'Review drawings', 'due_on' => '2026-10-12']);
        $response = $this->suggest(['request_text' => 'Review drawings '.$date])->assertOk();
        $response->assertJsonMissingPath('suggestion.fields.due_on');
        $this->assertNotEmpty($response->json('suggestion.questions'));
    }

    public function test_unspecified_or_conflicting_payment_type_is_not_invented(): void
    {
        $this->finance();
        $this->fakeProvider(['payment_type' => 'corporate']);
        foreach (['Office supplies', 'Office supplies with personal and corporate payment'] as $text) {
            $response = $this->suggest(['operation' => 'expense.pending.create', 'request_text' => $text])->assertOk();
            $response->assertJsonMissingPath('suggestion.fields.payment_type');
            $this->assertNotEmpty($response->json('suggestion.questions'));
        }
    }

    public function test_personal_payment_requires_current_active_same_site_employee(): void
    {
        $this->finance();
        $this->fakeProvider(['payment_type' => 'personal']);
        $input = ['operation' => 'expense.pending.create', 'request_text' => 'Personal payment for office supplies.'];
        $this->suggest($input)->assertForbidden();
        $employee = Employee::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => 'Synthetic', 'last_name' => 'Employee', 'employment_status' => 'active']);
        $this->actor->update(['employee_id' => $employee->id]);
        $this->suggest($input)->assertOk()->assertJsonPath('suggestion.fields.payment_type', 'personal');
        $employee->update(['employment_status' => 'inactive']);
        $this->suggest($input)->assertForbidden();
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_selected_receipt_and_record_data_never_enter_provider_prompt_or_history(): void
    {
        $this->finance();
        $receipt = $this->receipt();
        $before = $this->businessSnapshot();
        $this->fakeProvider(['amount' => '123.40']);
        $response = $this->suggest(['operation' => 'expense.pending.create', 'source_document_id' => $receipt->id,
            'request_text' => 'Office supplies total USD 123.40. REQUEST_TEXT_PRIVATE_SENTINEL'])->assertOk();
        $response->assertJsonPath('suggestion.source_document_id', $receipt->id);
        $payload = $this->providerPayloads[0];
        $prompt = json_encode($payload);
        foreach (['COMPANY_PRIVATE_SENTINEL', 'SITE_PRIVATE_SENTINEL', 'DOCUMENT_PRIVATE_SENTINEL', 'RECEIPT_CONTENT_PRIVATE_SENTINEL',
            'SOURCE_SUMMARY_PRIVATE_SENTINEL', 'SOURCE_TEXT_PRIVATE_SENTINEL', $receipt->file_path, $receipt->uuid, $receipt->sha256,
            'synthetic-suggestion-key', 'company_id', 'site_id', 'record_id', 'source_document_id', 'actor_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $prompt);
        }
        $this->assertStringContainsString('REQUEST_TEXT_PRIVATE_SENTINEL', $prompt);
        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayNotHasKey('tool_choice', $payload);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertStringNotContainsString('REQUEST_TEXT_PRIVATE_SENTINEL', DB::table('ai_assistant_requests')->get()->toJson());
        $this->assertNoOutboundEffects();
    }

    public function test_unselected_deictic_receipt_is_clarified_without_lookup_guess_or_provider(): void
    {
        $this->finance();
        $this->receipt();
        $this->fakeProvider(['amount' => '999.00']);
        $response = $this->suggest(['operation' => 'expense.pending.create', 'request_text' => 'Register this receipt.'])->assertOk();
        $response->assertJsonPath('suggestion.source_document_id', null)->assertJsonPath('suggestion.fields', []);
        $this->assertNotEmpty($response->json('suggestion.questions'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public static function ineligibleReceipts(): array
    {
        return [[['access_level' => 'private'], 404], [['confidentiality' => 'confidential'], 404],
            [['document_type' => 'invoice'], 404], [['ai_status' => 'analyzing'], 404],
            [['ai_payload' => ['duplicate_document_id' => 123]], 404],
            [['ai_payload' => ['money' => ['currency' => 'EUR']]], 422], [['sha256' => str_repeat('a', 64)], 409]];
    }

    #[DataProvider('ineligibleReceipts')]
    public function test_ineligible_receipts_are_rejected_before_provider(array $attributes, int $status): void
    {
        $this->finance();
        $receipt = $this->receipt($attributes);
        $this->fakeProvider();
        $this->suggest(['operation' => 'expense.pending.create', 'source_document_id' => $receipt->id])->assertStatus($status);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public static function receiptChanges(): array
    {
        return [['title'], ['bytes'], ['private'], ['owner']];
    }

    #[DataProvider('receiptChanges')]
    public function test_receipt_evidence_and_access_are_rechecked_after_provider(string $change): void
    {
        $this->finance();
        $receipt = $this->receipt();
        $this->fakeProvider([], [], function () use ($receipt, $change): void {
            match ($change) {
                'title' => $receipt->update(['title' => 'Human corrected receipt']),
                'bytes' => Storage::disk('local')->put($receipt->file_path, 'Changed bytes'),
                'private' => $receipt->update(['access_level' => 'private']),
                'owner' => $receipt->update(['owner_user_id' => User::factory()->create()->id]),
            };
        });
        $response = $this->suggest(['operation' => 'expense.pending.create', 'source_document_id' => $receipt->id]);
        $this->assertContains($response->status(), [404, 409]);
        $response->assertJsonMissingPath('suggestion');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
        $this->assertDatabaseCount('mobile_expenses', 0);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public static function disabledGates(): array
    {
        return [[['ai_assistant.enabled' => false]], [['ai_assistant.mutations_enabled' => false]],
            [['services.anthropic.api_key' => '']], [['ai_assistant.user_daily_requests' => 0]], [['ai_assistant.max_input_bytes' => 10]]];
    }

    #[DataProvider('disabledGates')]
    public function test_all_availability_and_budget_gates_fail_closed_before_provider(array $settings): void
    {
        config($settings);
        $this->fakeProvider();
        $response = $this->suggest();
        $this->assertContains($response->status(), [403, 422]);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_company_disable_and_missing_budget_storage_never_make_unmetered_calls(): void
    {
        $this->fakeProvider();
        config(['ai_assistant.companies.'.$this->company->id.'.enabled' => false]);
        $this->suggest()->assertUnprocessable();
        config(['ai_assistant.companies' => []]);
        Schema::rename('ai_assistant_requests', 'ai_assistant_requests_suggestion_unavailable');
        try {
            $this->suggest()->assertUnprocessable();
        } finally {
            Schema::rename('ai_assistant_requests_suggestion_unavailable', 'ai_assistant_requests');
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public static function sharedLimits(): array
    {
        return [['user_daily_requests'], ['user_monthly_requests'], ['company_daily_requests'], ['company_monthly_requests']];
    }

    #[DataProvider('sharedLimits')]
    public function test_suggestions_share_each_existing_limit_with_private_and_room_ask(string $limit): void
    {
        config(['ai_assistant.'.$limit => 3]);
        $budget = app(AiAssistantBudget::class);
        foreach (['document_ask', 'chat_ask'] as $feature) {
            $budget->run($this->actor, $this->company->id, $feature,
                ['max_tokens' => 100, 'messages' => [['role' => 'user', 'content' => 'Synthetic']]], fn () => 'Synthetic');
        }
        $this->fakeProvider();
        $this->suggest()->assertOk();
        $this->suggest()->assertUnprocessable();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 3);
        $this->assertSame(['document_ask', 'chat_ask', 'draft_suggestion'], DB::table('ai_assistant_requests')->orderBy('id')->pluck('feature')->all());
    }

    public function test_output_token_cap_is_shared_and_authorization_transaction_ends_before_provider(): void
    {
        config(['ai_assistant.max_output_tokens' => 32]);
        $baseline = DB::transactionLevel();
        $this->fakeProvider(during: function () use ($baseline): void {
            // RefreshDatabase owns one outer fixture transaction; the endpoint must add none here.
            $this->assertSame($baseline, DB::transactionLevel());
        });
        $this->suggest()->assertOk();
        $this->assertSame(32, $this->providerPayloads[0]['max_tokens']);
        $this->assertDatabaseHas('ai_assistant_requests', ['max_output_tokens' => 32, 'feature' => 'draft_suggestion']);
    }

    public function test_model_cannot_smuggle_finance_into_a_nonfinancial_operation(): void
    {
        $this->fakeProvider(['title' => 'Pay invoice 100 USD']);
        $this->suggest()->assertUnprocessable();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('assistant_proposals', 0);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public static function invalidExpenseFields(): array
    {
        return [
            [['amount' => 123.40]], [['amount' => '0']], [['amount' => '-1']], [['amount' => '1e3']],
            [['amount' => '1.001']], [['amount' => '1,000']], [['amount' => '1000000000000']],
            [['currency' => 'USD']], [['employee_id' => '1']], [['source_document_id' => '1']],
            [['status' => 'paid']], [['paid_at' => '2026-10-12']], [['confirmed' => 'true']],
            [['payment_type' => 'wire']], [['accounting_account' => 'unknown account']],
        ];
    }

    #[DataProvider('invalidExpenseFields')]
    public function test_expense_suggestions_reject_nonstring_invalid_or_forbidden_fields(array $fields): void
    {
        $this->finance();
        $this->fakeProvider($fields);
        $this->suggest(['operation' => 'expense.pending.create', 'request_text' => 'Office supplies total USD 123.40, corporate payment.'])
            ->assertUnprocessable()->assertJsonMissingPath('suggestion');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('assistant_proposals', 0);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public static function explicitAmounts(): array
    {
        return [['Office supplies total USD 123.4', '123.4'], ['Office supplies total 123.40 USD', '123.40'],
            ['Subtotal USD 100.00, tax USD 23.40, total USD 123.40', '123.40'], ['Office supplies USD 123.40 including tax', '123.40'], ['Credit card purchase total USD 123.40', '123.40'], ['Tarjeta de crédito total USD 123.40', '123.40']];
    }

    #[DataProvider('explicitAmounts')]
    public function test_exact_explicit_usd_total_preserves_the_users_decimal_string(string $text, string $amount): void
    {
        $this->finance();
        $this->fakeProvider(['amount' => $amount]);
        $this->suggest(['operation' => 'expense.pending.create', 'request_text' => $text])->assertOk()->assertJsonPath('suggestion.fields.amount', $amount);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_other_site_employee_and_midflight_employee_revocation_cannot_return_personal_payment(): void
    {
        $this->finance();
        $otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'EMP-OTHER', 'name' => 'Other', 'status' => 'active']);
        $employee = Employee::create(['company_id' => $this->company->id, 'site_id' => $otherSite->id,
            'first_name' => 'Synthetic', 'last_name' => 'Employee', 'employment_status' => 'active']);
        $this->actor->update(['employee_id' => $employee->id]);
        $calls = 0;
        $this->fakeProvider(['payment_type' => 'personal'], [], function () use ($employee, &$calls): void {
            if (++$calls === 2) {
                $employee->update(['employment_status' => 'inactive']);
            }
        });
        $input = ['operation' => 'expense.pending.create', 'request_text' => 'Personal payment for office supplies.'];
        $this->suggest($input)->assertForbidden();
        $employee->update(['site_id' => $this->site->id]);
        $this->suggest($input)->assertForbidden();
        $this->assertDatabaseCount('ai_assistant_requests', 2);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_finance_permission_is_rechecked_after_provider_even_when_empty_fields_are_returned(): void
    {
        $this->finance();
        $this->fakeProvider([], [], fn () => User::whereKey($this->actor->id)->update(['access_role' => 'site_manager']));
        $this->suggest(['operation' => 'expense.pending.create'])->assertForbidden()->assertJsonMissingPath('suggestion');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_deleted_target_during_provider_is_not_recreated(): void
    {
        $todo = $this->todo();
        $this->fakeProvider(during: fn () => $todo->delete());
        $this->suggest(['operation' => 'ops.todo.update', 'record_id' => $todo->id])->assertNotFound()->assertJsonMissingPath('suggestion');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
        $this->assertDatabaseCount('ops_action_items', 0);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }

    public function test_receipts_from_another_uploader_or_site_are_not_read_by_provider(): void
    {
        $this->finance();
        $other = User::factory()->create();
        $otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'SOURCE-OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->fakeProvider();
        foreach ([['uploaded_by' => $other->id], ['owner_user_id' => $other->id], ['site_id' => $otherSite->id]] as $attributes) {
            $receipt = $this->receipt($attributes);
            $this->suggest(['operation' => 'expense.pending.create', 'source_document_id' => $receipt->id])->assertNotFound();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_a_model_category_cannot_expand_the_existing_category_allowlist(): void
    {
        $document = $this->document();
        $this->fakeProvider(['category' => 'payroll']);
        $this->suggest(['operation' => 'document.category.update', 'record_id' => $document->id])->assertUnprocessable();
        $this->assertSame('general', $document->fresh()->category);
        $this->assertDatabaseCount('assistant_proposals', 0);
    }
}
