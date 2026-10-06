<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\Company;
use App\Models\DocumentActionItem;
use App\Models\DocumentQuestion;
use App\Models\Employee;
use App\Models\IntelligentDocument;
use App\Models\KnowledgeFact;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Communication\ChatAssistant;
use App\Services\Communication\CommunicationService;
use App\Services\Documents\DocumentAsk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatAssistantPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private CommunicationRoom $room;

    private User $asker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'synthetic-test-key', 'ai_assistant.enabled' => true]);
        Http::preventStrayRequests();
        $this->company = Company::create(['code' => 'PRIVATE', 'name' => 'Private fixture', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'PRIVATE-SITE', 'name' => 'Private site', 'status' => 'active']);
        $this->room = CommunicationRoom::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => 'Site room', 'status' => 'active']);
        $this->asker = $this->user($this->company, 'site_manager');
        $this->user($this->company, 'worker');
    }

    private function user(Company $company, string $role, bool $join = true): User
    {
        $employee = Employee::create(['company_id' => $company->id, 'site_id' => $this->site->id,
            'name' => 'Audience fixture', 'employment_status' => 'active']);
        $user = User::factory()->create(['employee_id' => $employee->id, 'account_status' => 'active',
            'access_role' => $role, 'access_scope' => 'site', 'allowed_company_id' => $company->id, 'allowed_site_id' => $this->site->id]);
        if ($join) {
            app(CommunicationService::class)->ensureRoomMember($this->room, $employee);
        }

        return $user;
    }

    private function question(string $body = '@AI 배관 공정 진행률 알려줘'): CommunicationMessage
    {
        return CommunicationMessage::create(['communication_room_id' => $this->room->id, 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'sender_user_id' => $this->asker->id, 'sender_employee_id' => $this->asker->employee_id,
            'kind' => CommunicationMessage::KIND_MESSAGE, 'body' => $body, 'status' => 'active']);
    }

    private function privateSource(): IntelligentDocument
    {
        $doc = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'owner_user_id' => $this->asker->id, 'access_level' => 'private',
            'source' => 'dropzone', 'disk' => 'local', 'file_path' => 'docs/private.pdf', 'original_file_name' => 'private.pdf',
            'stored_file_name' => 'private.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 100,
            'sha256' => hash('sha256', 'private fixture'), 'title' => '배관 공정 시방 PRIVATE-DOC', 'document_type' => 'specification',
            'confidentiality' => 'internal', 'status' => 'received', 'ai_status' => 'ready', 'search_text' => '배관 공정 PRIVATE-EXCERPT']);
        KnowledgeFact::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'intelligent_document_id' => $doc->id, 'document_type' => 'specification',
            'doc_title' => $doc->title, 'fact' => '배관 공정 PRIVATE-KNOWLEDGE']);

        return $doc;
    }

    private function fake(): void
    {
        Http::fake(['*api.anthropic.com*' => Http::response(['content' => [['type' => 'text', 'text' => '공정표 기준 62% 입니다.']]])]);
    }

    public function test_verified_shared_operational_answer_excludes_private_documents_knowledge_and_old_history(): void
    {
        $this->privateSource();
        WbsItem::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'project_code' => 'P1',
            'wbs_code' => 'P1-1', 'name' => '배관', 'progress' => 62]);
        $legacy = $this->question('LEGACY-PRIVATE-HISTORY');
        $legacy->update(['kind' => CommunicationMessage::KIND_SYSTEM, 'sender_user_id' => null,
            'payload' => ['bot' => ChatAssistant::BOT_MARKER, 'question_id' => $legacy->id]]);
        $this->fake();
        $reply = app(ChatAssistant::class)->answer($this->question());
        $this->assertSame('공정표 기준 62% 입니다.', $reply->body);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $prompt = $request['messages'][0]['content'];
            $this->assertStringNotContainsString('PRIVATE-DOC', $prompt);
            $this->assertStringNotContainsString('PRIVATE-EXCERPT', $prompt);
            $this->assertStringNotContainsString('PRIVATE-KNOWLEDGE', $prompt);
            $this->assertStringNotContainsString('LEGACY-PRIVATE-HISTORY', $prompt);
            $this->assertStringContainsString('62', $prompt);

            return true;
        });
    }

    public function test_shared_inspection_facts_exclude_private_document_actions(): void
    {
        $doc = $this->privateSource();
        DocumentActionItem::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'intelligent_document_id' => $doc->id, 'action_type' => 'inspection',
            'title' => 'PRIVATE-INSPECTION-ACTION', 'recommended_action' => 'PRIVATE-ACTION-INSTRUCTION']);
        $this->fake();
        $reply = app(ChatAssistant::class)->answer($this->question('@AI 검사 일정 알려줘'));
        $this->assertNotNull($reply);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $this->assertStringNotContainsString('PRIVATE-INSPECTION-ACTION', $request['messages'][0]['content']);
            $this->assertStringNotContainsString('PRIVATE-ACTION-INSTRUCTION', $request['messages'][0]['content']);

            return true;
        });
    }

    public function test_private_inspection_answer_tracks_action_source_for_history_reauthorization(): void
    {
        $doc = $this->privateSource();
        DocumentActionItem::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'intelligent_document_id' => $doc->id, 'action_type' => 'inspection', 'title' => 'PRIVATE-INSPECTION-ACTION']);
        // Keep the action source outside the separate recent-document fallback.
        for ($i = 0; $i < 6; $i++) {
            $decoy = $doc->replicate();
            $decoy->uuid = (string) Str::uuid();
            $decoy->sha256 = hash('sha256', 'decoy '.$i);
            $decoy->title = 'Unrelated recent source '.$i;
            $decoy->search_text = 'Unrelated source';
            $decoy->save();
        }
        Http::fake(['*api.anthropic.com*' => Http::response(['content' => [['type' => 'text',
            'text' => '{"answer":"검사 기한을 확인했습니다","found":true,"sources":[]}']]])]);
        $this->assertTrue(app(DocumentAsk::class)->ask($this->asker, '검사 일정 알려줘')['success']);
        $this->assertContains($doc->id, DocumentQuestion::query()->sole()->source_document_ids);
        $this->assertCount(1, app(DocumentAsk::class)->recent($this->asker));
        $doc->update(['owner_user_id' => User::where('access_role', 'worker')->value('id')]);
        $this->assertSame([], app(DocumentAsk::class)->recent($this->asker));
    }

    public function test_document_and_attendance_questions_redirect_without_any_provider_call(): void
    {
        $this->privateSource();
        $this->fake();
        foreach (['@AI 배관 도면 알려줘', '@AI 오늘 출근 인원 몇 명이야?', '@AI 개인 자료 알려줘'] as $body) {
            $reply = app(ChatAssistant::class)->answer($this->question($body));
            $this->assertStringContainsString('/attendance-app/ask', $reply->body);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_cross_company_member_is_not_given_askers_site_facts(): void
    {
        $other = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->user($other, 'vendor_admin');
        $this->fake();
        $reply = app(ChatAssistant::class)->answer($this->question());
        $this->assertStringContainsString('/attendance-app/ask', $reply->body);
        Http::assertNothingSent();
    }

    public function test_unlinked_member_causes_private_redirect(): void
    {
        $employee = Employee::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => 'Unlinked fixture', 'employment_status' => 'active']);
        CommunicationRoomMember::create(['communication_room_id' => $this->room->id, 'employee_id' => $employee->id, 'status' => 'active']);
        $this->fake();
        $this->assertStringContainsString('/attendance-app/ask', app(ChatAssistant::class)->answer($this->question())->body);
        Http::assertNothingSent();
    }

    public function test_scope_authorized_reader_outside_member_list_is_still_checked(): void
    {
        $other = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $reader = $this->user($other, 'worker', false);
        $reader->update(['access_scope' => 'all_sites']);
        $this->fake();
        $this->assertStringContainsString('/attendance-app/ask', app(ChatAssistant::class)->answer($this->question())->body);
        Http::assertNothingSent();
    }

    public function test_membership_changed_during_provider_call_withholds_the_generated_answer(): void
    {
        Http::fake(function () {
            $other = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
            $this->user($other, 'vendor_admin');

            return Http::response(['content' => [['type' => 'text', 'text' => 'WITHHOLD-GENERATED-ANSWER']]]);
        });
        $reply = app(ChatAssistant::class)->answer($this->question());
        $this->assertStringContainsString('/attendance-app/ask', $reply->body);
        $this->assertDatabaseMissing('communication_messages', ['body' => 'WITHHOLD-GENERATED-ANSWER']);
        Http::assertSentCount(1);
    }

    public function test_room_scope_change_during_provider_call_withholds_the_generated_answer(): void
    {
        Http::fake(function () {
            $this->room->update(['site_id' => null]);

            return Http::response(['content' => [['type' => 'text', 'text' => 'WITHHOLD-CHANGED-ROOM']]]);
        });
        $reply = app(ChatAssistant::class)->answer($this->question());
        $this->assertStringContainsString('/attendance-app/ask', $reply->body);
        $this->assertDatabaseMissing('communication_messages', ['body' => 'WITHHOLD-CHANGED-ROOM']);
    }

    public function test_room_without_explicit_site_does_not_use_askers_private_site(): void
    {
        $this->room->update(['site_id' => null]);
        $this->fake();
        $this->assertStringContainsString('/attendance-app/ask', app(ChatAssistant::class)->answer($this->question())->body);
        Http::assertNothingSent();
    }

    public function test_sender_suspended_during_provider_call_does_not_publish_generated_answer(): void
    {
        Http::fake(function () {
            User::whereKey($this->asker->id)->update(['account_status' => 'suspended']);

            return Http::response(['content' => [['type' => 'text', 'text' => 'WITHHOLD-SUSPENDED-ANSWER']]]);
        });
        app(ChatAssistant::class)->answer($this->question());
        $this->assertDatabaseMissing('communication_messages', ['body' => 'WITHHOLD-SUSPENDED-ANSWER']);
    }

    public function test_exhausted_budget_cannot_call_embedding_provider_during_fact_retrieval(): void
    {
        $this->privateSource();
        config(['services.gemini.api_key' => 'synthetic-gemini-key', 'ai_assistant.company_daily_requests' => 0]);
        Http::fake();
        $result = app(DocumentAsk::class)->ask($this->asker, '배관 공정 알려줘');
        $this->assertFalse($result['success']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_available_budget_makes_exactly_one_provider_call_even_with_embedding_key(): void
    {
        $this->privateSource();
        config(['services.gemini.api_key' => 'synthetic-gemini-key']);
        Http::fake(['*api.anthropic.com*' => Http::response(['content' => [['type' => 'text',
            'text' => '{"answer":"확인했습니다","found":true,"sources":[]}']]])]);
        $this->assertTrue(app(DocumentAsk::class)->ask($this->asker, '배관 공정 알려줘')['success']);
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('ai_assistant_requests')->count());
    }
}
