<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\DocumentActionItem;
use App\Models\Employee;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Assistant\AssistantCheckService;
use App\Services\Communication\ChatAssistant;
use App\Services\Communication\ChatFactFinder;
use App\Services\Communication\CommunicationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AssistantBoundaryIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    private Company $company;

    private Site $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ai_assistant.enabled' => true, 'ai_assistant.mutations_enabled' => true, 'ai_assistant.checks_enabled' => false,
            'services.anthropic.api_key' => 'review-synthetic-key']);
        $this->company = Company::create(['code' => 'REV-C', 'name' => 'Review synthetic company', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'REV-S', 'name' => 'Review synthetic site', 'status' => 'active']);
        $this->owner = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active', 'allowed_company_id' => $this->company->id]);
    }

    private function document(array $extra = []): IntelligentDocument
    {
        return IntelligentDocument::create($extra + [
            'uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'source' => 'upload', 'disk' => 'local', 'file_path' => 'synthetic-never-created.pdf', 'original_file_name' => 'review-drawing.pdf', 'stored_file_name' => 'review-drawing.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 100, 'sha256' => hash('sha256', (string) Str::uuid()),
            'title' => 'Synthetic drawing', 'document_type' => 'drawing', 'status' => 'received', 'ai_status' => 'ready', 'confidentiality' => 'internal',
            'access_level' => 'private', 'owner_user_id' => $this->owner->id, 'search_text' => 'Synthetic drawing REVIEW_PRIVATE_SENTINEL 418', 'summary' => 'REVIEW_PRIVATE_SENTINEL 418',
            'expires_on' => now()->addDays(5)->toDateString(),
        ]);
    }

    public function test_real_web_csrf_rejects_proposal_without_token(): void
    {
        $this->actingAsPurchaseUser($this->owner);
        $this->app['env'] = 'production';
        try {
            $this->postJson('/ask-api/workspace/proposals', [
                'operation' => 'ops.todo.create', 'site_id' => $this->site->id, 'payload' => ['title' => 'Inspect duct layout'],
            ])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('assistant_proposals', 0);
        $this->assertDatabaseCount('ops_action_items', 0);
    }

    public function test_web_preview_confirmation_is_exactly_once(): void
    {
        $this->actingAsPurchaseUser($this->owner);
        $preview = $this->postJson('/ask-api/workspace/proposals', [
            'operation' => 'ops.todo.create', 'site_id' => $this->site->id, 'payload' => ['title' => 'Inspect duct layout'],
        ])->assertOk()->json('proposal');
        $this->assertDatabaseCount('ops_action_items', 0);
        $payload = ['preview_token' => $preview['preview_token'], 'version' => $preview['version'], 'confirmed' => true];
        $first = $this->postJson('/ask-api/workspace/proposals/'.$preview['id'].'/confirm', $payload)->assertOk()->json();
        $second = $this->postJson('/ask-api/workspace/proposals/'.$preview['id'].'/confirm', $payload)->assertOk()->json();
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('ops_action_items', 1);
        Http::assertNothingSent();
    }

    public function test_downloaded_excel_roundtrip_never_contains_executable_cell_formulas(): void
    {
        WbsItem::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'project_code' => 'REV-P', 'wbs_code' => 'REV-W', 'name' => '=HYPERLINK("https://invalid.example","synthetic")', 'level' => 'task', 'progress' => 1]);
        $response = $this->actingAsPurchaseUser($this->owner)->get('/ask-api/workspace/export?'.http_build_query([
            'dataset' => 'wbs_items', 'company_id' => $this->company->id, 'site_id' => $this->site->id,
        ]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'erp-report-');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        $sentinel = false;
        foreach ($book->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                $this->assertNotSame('f', $cell->getDataType());
                if (str_starts_with((string) $cell->getValue(), '=HYPERLINK')) {
                    $sentinel = true;
                }
            }
        }
        $this->assertTrue($sentinel);
        $book->disconnectWorksheets();
        unlink($path);
        Http::assertNothingSent();
    }

    public function test_check_read_reauthorizes_private_sources_after_owner_changes(): void
    {
        $doc = $this->document();
        $service = app(AssistantCheckService::class);
        $check = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'expiring_documents', 'interval_hours' => 24]);
        config(['ai_assistant.checks_enabled' => true]);
        $service->activate($this->owner, $check->id, true, $check->approval_version);
        $this->assertSame(1, $service->runDue());
        $this->assertSame(1, $service->list($this->owner)[0]['result']['count']);
        $other = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $doc->update(['owner_user_id' => $other->id]);
        $rows = $service->list($this->owner);
        $this->assertSame(0, $rows[0]['result']['count']);
        $this->assertStringNotContainsString('Synthetic drawing', json_encode($rows));
        Http::assertNothingSent();
    }

    public function test_private_source_is_never_used_to_compose_a_shared_room_answer(): void
    {
        $this->document();
        $employee = Employee::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'name' => 'Synthetic asker', 'employment_status' => 'active']);
        $this->owner->update(['employee_id' => $employee->id, 'allowed_site_id' => $this->site->id]);
        $room = CommunicationRoom::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => 'Synthetic room', 'status' => 'active']);
        app(CommunicationService::class)->ensureRoomMember($room, $employee);
        $question = CommunicationMessage::create(['communication_room_id' => $room->id, 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'sender_user_id' => $this->owner->id, 'sender_employee_id' => $employee->id, 'kind' => CommunicationMessage::KIND_MESSAGE, 'body' => '@AI Synthetic drawing 알려줘', 'status' => 'active']);
        $facts = app(ChatFactFinder::class)->gatherFor('Synthetic drawing', $this->site, $this->owner->fresh(), true);
        $this->assertStringContainsString('REVIEW_PRIVATE_SENTINEL', json_encode($facts));
        $prompt = '';
        Http::fake(['*api.anthropic.com*' => function ($request) use (&$prompt) {
            $prompt = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return Http::response(['content' => [['type' => 'text', 'text' => str_contains($prompt, 'REVIEW_PRIVATE_SENTINEL') ? 'REVIEW_PRIVATE_SENTINEL 418' : 'No private facts']]]);
        }]);
        $answer = app(ChatAssistant::class)->answer($question);
        $this->assertNotNull($answer);
        $this->assertTrue(str_contains($answer->body, '개인') || str_contains($answer->body, 'No private facts'), 'Unexpected response: '.$answer->body);
        $this->assertStringNotContainsString('REVIEW_PRIVATE_SENTINEL', $prompt, 'Private source must never enter the shared-answer provider prompt.');
        $this->assertStringNotContainsString('REVIEW_PRIVATE_SENTINEL', $answer?->body ?? '');
    }

    public function test_nested_private_inspection_action_cannot_enter_shared_answer(): void
    {
        $doc = $this->document();
        DocumentActionItem::create(['intelligent_document_id' => $doc->id, 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'action_type' => 'inspection', 'status' => 'open', 'title' => 'REVIEW_PRIVATE_ACTION_SENTINEL', 'recommended_action' => 'REVIEW_PRIVATE_ACTION_SENTINEL inspect confidential prototype']);
        $employee = Employee::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'name' => 'Synthetic asker', 'employment_status' => 'active']);
        $this->owner->update(['employee_id' => $employee->id, 'allowed_site_id' => $this->site->id]);
        $room = CommunicationRoom::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => 'Synthetic room', 'status' => 'active']);
        app(CommunicationService::class)->ensureRoomMember($room, $employee);
        $question = CommunicationMessage::create(['communication_room_id' => $room->id, 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'sender_user_id' => $this->owner->id, 'sender_employee_id' => $employee->id, 'kind' => CommunicationMessage::KIND_MESSAGE, 'body' => '@AI 검사 일정 알려줘', 'status' => 'active']);
        $facts = app(ChatFactFinder::class)->gatherFor('검사 일정', $this->site, $this->owner->fresh(), true);
        $this->assertStringContainsString('REVIEW_PRIVATE_ACTION_SENTINEL', json_encode($facts));
        $prompt = '';
        Http::fake(['*api.anthropic.com*' => function ($request) use (&$prompt) {
            $prompt = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return Http::response(['content' => [['type' => 'text', 'text' => str_contains($prompt, 'REVIEW_PRIVATE_ACTION_SENTINEL') ? 'REVIEW_PRIVATE_ACTION_SENTINEL 418' : 'No private facts']]]);
        }]);
        $answer = app(ChatAssistant::class)->answer($question);
        $this->assertNotNull($answer);
        $this->assertTrue(str_contains($answer->body, '개인') || str_contains($answer->body, 'No private facts'), 'Unexpected response: '.$answer->body);
        $this->assertStringNotContainsString('REVIEW_PRIVATE_ACTION_SENTINEL', $prompt, 'Private source must never enter the shared-answer provider prompt.');
        $this->assertStringNotContainsString('REVIEW_PRIVATE_ACTION_SENTINEL', $answer?->body ?? '');
    }
}
