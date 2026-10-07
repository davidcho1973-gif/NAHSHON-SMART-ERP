<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\DocumentQuestion;
use App\Models\Employee;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Communication\ChatAssistant;
use App\Services\Communication\CommunicationService;
use App\Services\Documents\DocumentAsk;
use App\Services\Wbs\CpmEngine;
use App\Support\AiAssistantBudget;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AiAssistantBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_assistant.enabled' => true, 'ai_assistant.company_daily_requests' => 200,
            'ai_assistant.company_monthly_requests' => 3000, 'ai_assistant.user_daily_requests' => 20,
            'ai_assistant.user_monthly_requests' => 300, 'ai_assistant.max_input_bytes' => 64000,
            'ai_assistant.max_output_tokens' => 1200, 'ai_assistant.companies' => [],
            'services.anthropic.api_key' => 'synthetic-test-key']);
        Http::preventStrayRequests();
        $this->company = Company::create(['code' => 'BUDGET', 'name' => 'Budget Co', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'BUDGET-SITE', 'name' => 'Budget Site', 'status' => 'active']);
        $this->actor = $this->actor();
    }

    private function actor(): User
    {
        $employee = Employee::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => 'Test Worker', 'employment_status' => 'active']);

        return User::factory()->create(['employee_id' => $employee->id, 'access_role' => 'site_manager',
            'access_scope' => 'site', 'allowed_company_id' => $this->company->id,
            'allowed_site_id' => $this->site->id, 'account_status' => 'active']);
    }

    private function budgetCall(?User $actor = null, ?callable $call = null): mixed
    {
        return app(AiAssistantBudget::class)->run($actor ?? $this->actor, $this->company->id, 'document_ask',
            ['max_tokens' => 1200, 'messages' => [['role' => 'user', 'content' => 'synthetic question']]],
            $call ?? fn (array $payload): string => 'answer');
    }

    private function fakeAnswer(string $text = '{"answer":"시공 확인","found":false,"sources":[]}'): void
    {
        Http::fake(['*api.anthropic.com*' => Http::response(['content' => [['type' => 'text', 'text' => $text]]])]);
    }

    private function question(string $body = '@AI 공정 진행률 알려줘'): CommunicationMessage
    {
        $room = CommunicationRoom::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => 'Budget chat', 'status' => 'active']);
        app(CommunicationService::class)->ensureRoomMember($room, $this->actor->employee);

        // Create directly so only the explicit answer() invocation runs the provider.
        return CommunicationMessage::create(['communication_room_id' => $room->id, 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'sender_user_id' => $this->actor->id, 'sender_employee_id' => $this->actor->employee_id,
            'kind' => CommunicationMessage::KIND_MESSAGE, 'body' => $body, 'status' => 'active']);
    }

    public function test_private_and_room_requests_share_the_same_user_budget(): void
    {
        config(['ai_assistant.user_daily_requests' => 1]);
        $this->fakeAnswer();
        $result = app(DocumentAsk::class)->ask($this->actor, '시공 순서 알려줘');
        $this->assertTrue($result['success']);
        $reply = app(ChatAssistant::class)->answer($this->question());
        $this->assertStringContainsString('개인 AI 질문 한도', $reply->body);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
    }

    public function test_company_limit_combines_different_users(): void
    {
        config(['ai_assistant.company_daily_requests' => 1]);
        $this->budgetCall();
        $status = app(AiAssistantBudget::class)->status($this->actor());
        $this->assertFalse($status['allowed']);
        $this->assertSame('company_day_limit', $status['reason']);
        $this->assertSame(0, $status['requests']['user_day']['used']);
        $this->expectException(DomainException::class);
        $this->budgetCall($this->actor(), fn () => $this->fail('Exhausted company must not call provider.'));
    }

    public function test_daily_windows_are_utc_even_when_database_timezone_is_not(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'America/Phoenix'");
        $this->travelTo(Carbon::parse('2026-10-06T23:59:59Z'));
        config(['ai_assistant.user_daily_requests' => 1]);
        $this->budgetCall();
        $reserved = Carbon::parse(DB::table('ai_assistant_requests')->sole()->reserved_at)->utc();
        $this->assertSame('2026-10-06T23:59:59+00:00', $reserved->toIso8601String());
        $this->assertSame('user_day_limit', app(AiAssistantBudget::class)->status($this->actor)['reason']);
        $this->travelTo(Carbon::parse('2026-10-07T00:00:00Z'));
        $this->assertTrue(app(AiAssistantBudget::class)->status($this->actor)['allowed']);
        $this->assertSame('answer', $this->budgetCall());
    }

    public function test_monthly_user_limit_survives_a_day_boundary(): void
    {
        $this->travelTo(now('UTC')->startOfMonth()->addDays(3));
        config(['ai_assistant.user_monthly_requests' => 1]);
        $this->budgetCall();
        $this->travel(1)->days();
        $status = app(AiAssistantBudget::class)->status($this->actor);
        $this->assertSame('user_month_limit', $status['reason']);
        $this->assertSame(0, $status['requests']['user_day']['used']);
        $this->expectException(DomainException::class);
        $this->budgetCall();
    }

    public function test_monthly_company_limit_survives_a_day_boundary_and_resets_next_month(): void
    {
        $this->travelTo(now('UTC')->startOfMonth()->addDays(3));
        config(['ai_assistant.company_monthly_requests' => 1]);
        $this->budgetCall();
        $this->travel(1)->days();
        $this->assertSame('company_month_limit', app(AiAssistantBudget::class)->status($this->actor())['reason']);
        $this->travelTo(now('UTC')->addMonthNoOverflow()->startOfMonth());
        $this->assertTrue(app(AiAssistantBudget::class)->status($this->actor)['allowed']);
        $this->assertSame('answer', $this->budgetCall());
    }

    public function test_disabled_company_or_zero_limit_cannot_make_a_call(): void
    {
        config(['ai_assistant.companies.'.$this->company->id.'.enabled' => false]);
        $this->fakeAnswer();
        $this->assertFalse(app(DocumentAsk::class)->ask($this->actor, '시공 알려줘')['success']);
        config(['ai_assistant.companies.'.$this->company->id.'.enabled' => true,
            'ai_assistant.companies.'.$this->company->id.'.company_daily_requests' => 0]);
        $this->assertFalse(app(DocumentAsk::class)->ask($this->actor, '시공 알려줘')['success']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_global_disable_blocks_both_surfaces(): void
    {
        config(['ai_assistant.enabled' => false]);
        $this->fakeAnswer();
        $this->assertFalse(app(DocumentAsk::class)->available());
        $this->assertFalse(app(DocumentAsk::class)->ask($this->actor, '시공 알려줘')['success']);
        $this->assertNull(app(ChatAssistant::class)->answer($this->question()));
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_provider_failure_is_charged_and_error_body_is_not_retained(): void
    {
        config(['ai_assistant.user_daily_requests' => 1]);
        try {
            $this->budgetCall(call: fn () => throw new RuntimeException('private question synthetic-secret-token'));
            $this->fail('Expected a sanitized provider error.');
        } catch (RuntimeException $e) {
            $this->assertSame('AI provider request failed.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $row = DB::table('ai_assistant_requests')->sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringNotContainsString('private question', json_encode($row));
        $this->assertStringNotContainsString('synthetic-secret-token', json_encode($row));
        $this->assertSame('user_day_limit', app(AiAssistantBudget::class)->status($this->actor)['reason']);
    }

    public function test_malformed_json_does_not_make_a_second_provider_call(): void
    {
        $this->fakeAnswer('invalid JSON answer');
        $result = app(DocumentAsk::class)->ask($this->actor, '시공 알려줘');
        $this->assertTrue($result['success']);
        $this->assertFalse($result['found']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_assistant_requests', 1);
    }

    public function test_input_size_rejects_before_provider_and_output_is_clamped(): void
    {
        config(['ai_assistant.max_input_bytes' => 10]);
        $this->fakeAnswer();
        $this->assertFalse(app(DocumentAsk::class)->ask($this->actor, '시공 알려줘')['success']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
        config(['ai_assistant.max_input_bytes' => 64000, 'ai_assistant.max_output_tokens' => 32]);
        $this->budgetCall(call: function (array $payload): string {
            $this->assertSame(32, $payload['max_tokens']);

            return 'answer';
        });
        $this->assertSame(32, DB::table('ai_assistant_requests')->sole()->max_output_tokens);
    }

    public function test_queue_charges_message_sender_even_when_a_different_user_is_authenticated(): void
    {
        $otherCompany = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $other = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active', 'allowed_company_id' => $otherCompany->id]);
        $this->actingAs($other);
        $this->fakeAnswer('시공 순서를 확인하세요.');
        $this->assertNotNull(app(ChatAssistant::class)->answer($this->question()));
        $this->assertDatabaseHas('ai_assistant_requests', ['company_id' => $this->company->id, 'user_id' => $this->actor->id, 'feature' => 'chat_ask']);
        $this->assertDatabaseMissing('ai_assistant_requests', ['user_id' => $other->id]);
    }

    public function test_queue_reloads_suspended_sender_and_makes_no_call(): void
    {
        $message = $this->question()->load('senderUser');
        $this->actor->update(['account_status' => 'suspended']);
        $this->fakeAnswer();
        $this->assertNull(app(ChatAssistant::class)->answer($message));
        Http::assertNothingSent();
    }

    public function test_budget_rejects_stale_actor_and_a_foreign_billing_company(): void
    {
        $otherCompany = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->assertNull(app(AiAssistantBudget::class)->companyId($this->actor, $otherCompany->id));
        User::whereKey($this->actor->id)->update(['account_status' => 'suspended']);
        $this->expectException(DomainException::class);
        $this->budgetCall(call: fn () => $this->fail('Stale actor must not call provider.'));
    }

    public function test_missing_budget_storage_fails_closed(): void
    {
        Schema::rename('ai_assistant_requests', 'ai_assistant_requests_unavailable');
        try {
            $this->assertSame('storage_unavailable', app(AiAssistantBudget::class)->status($this->actor)['reason']);
            $this->fakeAnswer();
            $this->assertFalse(app(DocumentAsk::class)->ask($this->actor, '시공 알려줘')['success']);
            Http::assertNothingSent();
        } finally {
            Schema::rename('ai_assistant_requests_unavailable', 'ai_assistant_requests');
        }
    }

    public function test_selected_authorized_site_drives_sources_history_and_billing(): void
    {
        $otherCompany = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $site = Site::create(['company_id' => $otherCompany->id, 'code' => 'SELECTED', 'name' => 'Selected site', 'status' => 'active']);
        $this->actor->update(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $doc = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'company_id' => $otherCompany->id, 'site_id' => $site->id,
            'source' => 'dropzone', 'disk' => 'local', 'file_path' => 'docs/test.pdf', 'original_file_name' => 'spec.pdf',
            'stored_file_name' => 'spec.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 100,
            'sha256' => hash('sha256', 'synthetic fixture'), 'title' => '배관 시방', 'document_type' => 'specification',
            'status' => 'received', 'ai_status' => 'ready', 'search_text' => '배관 설치 순서']);
        $this->fakeAnswer(json_encode(['answer' => '시방을 확인하세요.', 'found' => true, 'sources' => [$doc->id]]));
        $result = app(DocumentAsk::class)->ask($this->actor, '배관 시방 알려줘', $site);
        $this->assertTrue($result['success']);
        $this->assertSame($doc->id, $result['sources'][0]['document_id']);
        $this->assertSame($site->id, DocumentQuestion::query()->sole()->site_id);
        $this->assertSame($otherCompany->id, DB::table('ai_assistant_requests')->sole()->company_id);
        $this->assertCount(1, app(DocumentAsk::class)->recent($this->actor));
        $doc->update(['company_id' => $this->company->id]);
        $this->assertSame([], app(DocumentAsk::class)->recent($this->actor));
    }

    public function test_worker_cannot_choose_a_foreign_site(): void
    {
        $site = Site::create(['company_id' => $this->company->id, 'code' => 'FOREIGN', 'name' => 'Foreign site', 'status' => 'active']);
        $this->actor->update(['access_role' => 'worker']);
        $this->fakeAnswer();
        $this->assertFalse(app(DocumentAsk::class)->ask($this->actor, '시공 알려줘', $site)['success']);
        Http::assertNothingSent();
    }

    public function test_what_if_is_read_only_even_when_mutations_are_enabled(): void
    {
        config(['ai_assistant.mutations_enabled' => true]);
        WbsItem::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'project_code' => 'BUDGET-P',
            'wbs_code' => 'B.1', 'name' => '배관', 'planned_start' => '2026-10-01', 'planned_end' => '2026-10-10', 'progress' => 0]);
        $engine = \Mockery::mock(CpmEngine::class);
        $engine->shouldReceive('simulate')->once()->with('BUDGET-P', '배관', 3)
            ->andReturn(['success' => true, 'wbsCode' => 'B.1', 'name' => '배관', 'delayDays' => 3]);
        $this->app->instance(CpmEngine::class, $engine);
        $this->fakeAnswer('3일 지연 시뮬레이션입니다.');
        $before = DB::table('ops_intake_items')->count();
        $reply = app(ChatAssistant::class)->answer($this->question('@AI 배관 3일 밀리면?'));
        $this->assertStringContainsString('실제 공정표는 변경되지 않았습니다', $reply->body);
        $this->assertSame($before, DB::table('ops_intake_items')->count());
        $this->assertSame('2026-10-10', WbsItem::where('wbs_code', 'B.1')->first()->planned_end->toDateString());
        Http::assertSent(fn ($request) => str_contains($request->body(), 'delayDays'));
    }
}
