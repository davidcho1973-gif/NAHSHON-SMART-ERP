<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\Company;
use App\Models\DocumentActionItem;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertService;
use App\Services\Communication\ChatDocumentReplyConnector;
use App\Services\Documents\DocumentCrossCheck;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\DocumentIntelligenceAnalyzer;
use App\Services\Documents\DocumentIntelligenceService;
use App\Services\Documents\DocumentScope;
use App\Services\Documents\KnowledgeKeeper;
use App\Services\Equipment\DocumentEquipmentConnector;
use App\Services\Finance\BillingInflowConnector;
use App\Services\Finance\DocumentExpenseConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DocumentScopeReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document-intelligence.disk' => 'local']);
        Storage::fake('local');
    }

    public function test_ai_final_project_duplicate_preserves_both_originals_analysis_and_existing_actions(): void
    {
        [$company, $site, $project] = $this->project('A');
        $user = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        // Production regression: the previous record has project_id but legacy null scope.
        $existing = $this->document(['project_id' => $project->id, 'ai_status' => 'ready']);
        $incoming = $this->document(['uploaded_by' => $user->id]);
        $originalPath = $incoming->file_path;
        $action = DocumentActionItem::query()->create([
            'intelligent_document_id' => $incoming->id, 'action_type' => 'review', 'title' => 'Retain manually reviewed task',
        ]);
        $this->mock(DocumentIntelligenceAnalyzer::class)->shouldReceive('analyze')->once()->andReturn([
            'data' => ['project_code' => $project->project_code, 'title' => 'Read architecture drawing', 'summary' => 'AI successfully read the document.', 'confidence' => 95,
                'action_items' => [['title' => 'Must not be created']], 'duplicate_document_id' => 999999],
            'engine' => 'test', 'model' => 'test', 'extracted_text' => 'The complete extracted drawing text.',
        ]);
        $this->mock(DocumentCrossCheck::class)->shouldReceive('check')->once()->andReturnNull();
        $this->mock(KnowledgeKeeper::class)->shouldNotReceive('harvest');
        foreach ([DocumentExpenseConnector::class, BillingInflowConnector::class,
            DocumentEquipmentConnector::class, ChatDocumentReplyConnector::class] as $connector) {
            $this->mock($connector)->shouldNotReceive('sync');
        }

        $result = app(DocumentIntelligenceService::class)->process($incoming);

        $this->assertSame('review_required', $result->ai_status);
        $this->assertSame($existing->id, $result->ai_payload['duplicate_document_id']);
        $this->assertSame('Read architecture drawing', $result->title);
        $this->assertSame('The complete extracted drawing text.', $result->extracted_text);
        $this->assertNull($result->project_id);
        $this->assertNull($result->company_id);
        $this->assertNull($result->ai_error);
        $this->assertSame($originalPath, $result->file_path);
        $this->assertSame('ready', $existing->fresh()->ai_status);
        $this->assertDatabaseHas('document_action_items', ['id' => $action->id, 'title' => $action->title]);
        $this->assertDatabaseCount('document_action_items', 1);
        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertExists($existing->file_path);
    }

    public function test_postgres_unique_race_rolls_back_savepoint_and_retains_review_result(): void
    {
        [, , $project] = $this->project('A');
        $scope = app(DocumentScope::class)->normalize(['project_id' => $project->id]);
        $existing = $this->document([...$scope, 'ai_status' => 'ready']);
        $incoming = $this->document();
        $scopes = Mockery::mock(DocumentScope::class)->makePartial();
        // The winner becomes visible between lookup and write. Exercise PostgreSQL's
        // real unique constraint, not a fabricated exception with a healthy transaction.
        $scopes->shouldReceive('findDuplicate')->twice()->andReturn(null, $existing);

        $result = DB::transaction(fn () => $scopes->saveResolved($incoming, $scope, [
            'title' => 'Preserved after race', 'ai_status' => 'ready', 'ai_payload' => ['confidence' => 96],
        ]));

        $this->assertNull($result->project_id);
        $this->assertSame('review_required', $result->ai_status);
        $this->assertSame('Preserved after race', $result->title);
        $this->assertSame($existing->id, $result->ai_payload['duplicate_document_id']);
        $this->assertSame(2, IntelligentDocument::query()->count());
        $this->assertTrue(DB::table('pg_indexes')->where('indexname', 'intelligent_documents_scope_hash_unique')->exists());
    }

    public function test_intake_race_reuses_winner_without_reanalysis_or_orphan_copy(): void
    {
        Bus::fake();
        $existing = $this->document(['ai_status' => 'ready']);
        $scopes = Mockery::mock(DocumentScope::class)->makePartial();
        $scopes->shouldReceive('findDuplicate')->twice()->andReturn(null, $existing);
        $this->app->instance(DocumentScope::class, $scopes);

        $result = app(DocumentIntake::class)->ingest(UploadedFile::fake()->createWithContent('drawing.txt', 'Identical source bytes'));

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame($existing->id, $result['document']->id);
        $this->assertSame(1, IntelligentDocument::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Bus::assertNotDispatched(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_intake_normalizes_project_scope_and_reuses_legacy_project_record(): void
    {
        Bus::fake();
        [$company, $site, $project] = $this->project('A');
        $existing = $this->document(['project_id' => $project->id, 'ai_status' => 'ready']);
        $result = app(DocumentIntake::class)->ingest(UploadedFile::fake()->createWithContent('drawing.txt', 'Identical source bytes'), ['project_id' => $project->id]);
        $this->assertSame('duplicate', $result['status']);
        $this->assertSame($existing->id, $result['document']->id);
        $created = app(DocumentIntake::class)->ingest(UploadedFile::fake()->createWithContent('different.txt', 'Different source bytes'), ['project_id' => $project->id]);
        $this->assertSame($company->id, $created['document']->company_id);
        $this->assertSame($site->id, $created['document']->site_id);
        Bus::assertDispatchedTimes(AnalyzeIntelligentDocumentJob::class, 1);
    }

    public function test_explicit_site_mismatch_is_rejected_before_project_scope_derivation(): void
    {
        [, , $project] = $this->project('A');
        [, $wrongSite] = $this->project('B');
        $this->expectException(ValidationException::class);
        app(DocumentScope::class)->normalize(['site_id' => $wrongSite->id, 'project_id' => $project->id]);
    }

    public function test_company_restricted_user_cannot_select_another_company(): void
    {
        [$company] = $this->project('A');
        [, , $otherProject] = $this->project('B');
        $user = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'company', 'allowed_company_id' => $company->id]);
        $this->expectException(ValidationException::class);
        app(DocumentScope::class)->normalize(['project_id' => $otherProject->id], $user);
    }

    public function test_ambiguous_project_names_are_reviewed_and_user_scope_limits_candidates(): void
    {
        [$company, $site, $project] = $this->project('A', '703K kitchen');
        $this->project('B', '703K kitchen');
        $user = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $document = $this->document(['uploaded_by' => $user->id]);
        $service = app(DocumentScope::class);
        $ambiguous = $service->resolveForAnalysis($document, '703K');
        $this->assertNull($ambiguous['scope']['project_id']);
        $this->assertNotNull($ambiguous['review_reason']);

        $user->update(['access_role' => 'site_manager', 'access_scope' => 'company', 'allowed_company_id' => $company->id]);
        $document->unsetRelation('uploadedBy');
        $resolved = $service->resolveForAnalysis($document, '703K');
        $this->assertSame($project->id, $resolved['scope']['project_id']);
        $this->assertSame($company->id, $resolved['scope']['company_id']);
        $this->assertSame($site->id, $resolved['scope']['site_id']);
        $this->assertNull($resolved['review_reason']);
        $this->assertNull($service->resolveForAnalysis($document, '%')['scope']['project_id']);
    }

    public function test_same_content_in_different_projects_and_companies_remains_separate(): void
    {
        [, , $first] = $this->project('A');
        [, , $second] = $this->project('B');
        $service = app(DocumentScope::class);
        $existing = $this->document($service->normalize(['project_id' => $first->id]));
        $incoming = $this->document(['sha256' => $existing->sha256]);
        $result = $service->saveResolved($incoming, $service->normalize(['project_id' => $second->id]), ['ai_status' => 'ready']);
        $this->assertSame($second->id, $result->project_id);
        $this->assertSame('ready', $result->ai_status);
        $this->assertEmpty($result->ai_payload['duplicate_document_id'] ?? null);
        $this->assertSame($first->id, $existing->fresh()->project_id);
    }

    public function test_reupload_restores_original_without_losing_existing_references(): void
    {
        Bus::fake();
        $existing = $this->document(['ai_status' => 'failed']);
        Storage::disk('local')->delete($existing->file_path);
        $result = app(DocumentIntake::class)->ingest(UploadedFile::fake()->createWithContent('restored.txt', 'Identical source bytes'));
        $this->assertSame('restored', $result['status']);
        $this->assertSame($existing->id, $result['document']->id);
        $this->assertSame('queued', $result['document']->ai_status);
        Storage::disk('local')->assertExists($result['document']->file_path);
        Bus::assertDispatchedTimes(AnalyzeIntelligentDocumentJob::class, 1);
    }

    public function test_manual_title_change_keeps_duplicate_review_and_a_verified_new_scope_clears_it(): void
    {
        [, , $first] = $this->project('A');
        [, , $second] = $this->project('B');
        $service = app(DocumentScope::class);
        $existing = $this->document($service->normalize(['project_id' => $first->id]));
        $incoming = $this->document();
        $service->retainDuplicate($incoming, $existing);
        $service->saveResolved($incoming, $service->scopeOf($incoming), ['title' => 'User title', 'ai_status' => 'ready']);
        $this->assertSame('review_required', $incoming->ai_status);
        $this->assertSame($existing->id, $incoming->ai_payload['duplicate_document_id']);

        $service->saveResolved($incoming, $service->normalize(['project_id' => $second->id]), ['ai_status' => 'ready']);
        $this->assertSame($second->id, $incoming->project_id);
        $this->assertSame('ready', $incoming->ai_status);
        $this->assertArrayNotHasKey('duplicate_document_id', $incoming->ai_payload);
    }

    public function test_recovery_cannot_mark_different_content_as_a_duplicate(): void
    {
        $first = $this->document();
        $second = $this->document(['sha256' => hash('sha256', 'different content')]);
        $this->expectException(\InvalidArgumentException::class);
        app(DocumentScope::class)->retainDuplicate($second, $first);
    }

    public function test_superseded_analysis_cannot_overwrite_the_new_run_or_execute_connectors(): void
    {
        $incoming = $this->document(['ai_status' => 'analyzing', 'ai_payload' => ['analysis_run_token' => 'old-run']]);
        $this->mock(DocumentIntelligenceAnalyzer::class)->shouldReceive('analyze')->once()->andReturnUsing(function () use ($incoming): array {
            $incoming->fresh()->update(['title' => 'Newer completed result', 'ai_status' => 'ready', 'ai_payload' => ['analysis_run_token' => 'new-run']]);

            return ['data' => ['title' => 'Stale result', 'confidence' => 99], 'engine' => 'test', 'model' => 'test', 'extracted_text' => 'Stale text'];
        });
        $this->mock(DocumentCrossCheck::class)->shouldReceive('check')->once()->andReturnNull();
        $this->mock(KnowledgeKeeper::class)->shouldNotReceive('harvest');
        $result = app(DocumentIntelligenceService::class)->process($incoming);

        $this->assertSame('Newer completed result', $result->title);
        $this->assertSame('new-run', $result->ai_payload['analysis_run_token']);
        $this->assertSame('ready', $result->ai_status);
        $this->assertDatabaseCount('document_action_items', 0);
    }

    public function test_manual_scope_review_clears_uncertainty_without_implicitly_resolving_a_duplicate(): void
    {
        $user = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $document = $this->document(['ai_status' => 'review_required', 'ai_payload' => ['scope_review_reason' => 'Uncertain project', 'key_facts' => ['Keep this fact']]]);
        $service = app(DocumentScope::class);
        $service->saveResolved($document, $service->scopeOf($document), ['reviewed_by' => $user->id, 'ai_status' => 'ready']);
        $this->assertSame('ready', $document->ai_status);
        $this->assertArrayNotHasKey('scope_review_reason', $document->ai_payload);
        $this->assertSame(['Keep this fact'], $document->ai_payload['key_facts']);
    }

    public function test_downstream_alert_failure_keeps_original_at_the_rolled_back_database_path(): void
    {
        $document = $this->document();
        $oldPath = $document->file_path;
        $this->mock(DocumentIntelligenceAnalyzer::class)->shouldReceive('analyze')->once()->andReturn([
            'data' => ['title' => 'Analyzed drawing', 'confidence' => 95, 'action_items' => [['title' => 'Critical deadline', 'severity' => 'critical']]],
            'engine' => 'test', 'model' => 'test', 'extracted_text' => 'Source content',
        ]);
        $this->mock(DocumentCrossCheck::class)->shouldReceive('check')->once()->andReturnNull();
        $this->mock(UnifiedAlertService::class)->shouldReceive('emit')->once()->andThrow(new \RuntimeException('Alert write failed'));
        $this->mock(KnowledgeKeeper::class)->shouldNotReceive('harvest');

        try {
            app(DocumentIntelligenceService::class)->process($document);
            $this->fail('The alert failure must propagate to the job for safe failure recording.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Alert write failed', $e->getMessage());
        }

        $this->assertSame($oldPath, $document->fresh()->file_path);
        Storage::disk('local')->assertExists($oldPath);
        $this->assertSame('Identical source bytes', Storage::disk('local')->get($oldPath));
        $this->assertNotEmpty(Storage::disk('local')->allFiles('document-intelligence/library'));
        $this->assertDatabaseCount('document_action_items', 0);
    }

    public function test_connector_database_error_rolls_back_only_its_savepoint_and_keeps_analysis_ready(): void
    {
        $document = $this->document();
        $this->mock(DocumentIntelligenceAnalyzer::class)->shouldReceive('analyze')->once()->andReturn([
            'data' => ['title' => 'Saved despite connector failure', 'confidence' => 95],
            'engine' => 'test', 'model' => 'test', 'extracted_text' => 'Source content',
        ]);
        $this->mock(DocumentCrossCheck::class)->shouldReceive('check')->once()->andReturnNull();
        $this->mock(DocumentExpenseConnector::class)->shouldReceive('sync')->once()->andReturnUsing(function (): void {
            // A real PostgreSQL error poisons a transaction until its savepoint is rolled back.
            DB::select('select no_such_document_connector_column from intelligent_documents');
        });
        foreach ([BillingInflowConnector::class, DocumentEquipmentConnector::class,
            ChatDocumentReplyConnector::class] as $connector) {
            $this->mock($connector)->shouldReceive('sync')->once()->andReturnNull();
        }
        $this->mock(KnowledgeKeeper::class)->shouldReceive('harvest')->once()->andReturn(0);

        $result = app(DocumentIntelligenceService::class)->process($document);

        $this->assertSame('ready', $result->ai_status);
        $this->assertSame('Saved despite connector failure', $result->title);
        $this->assertStringStartsWith('document-intelligence/library/', $result->file_path);
        Storage::disk('local')->assertExists($result->file_path);
    }

    private function project(string $code, ?string $name = null): array
    {
        $company = Company::query()->create(['code' => $code, 'name' => $code, 'status' => 'active']);
        $site = Site::query()->create(['company_id' => $company->id, 'code' => $code, 'name' => $code, 'country' => 'US', 'timezone' => 'UTC', 'status' => 'active']);
        $project = Project::query()->create(['company_id' => $company->id, 'site_id' => $site->id, 'project_code' => $code.'-PROJECT', 'name' => $name ?? $code.' project', 'construction_type' => 'piping', 'project_stage' => 'awarded']);

        return [$company, $site, $project];
    }

    private function document(array $attributes = []): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $path = 'document-intelligence/inbox/'.$uuid.'/drawing.txt';
        Storage::disk('local')->put($path, 'Identical source bytes');

        return IntelligentDocument::query()->create([
            'uuid' => $uuid, 'disk' => 'local', 'file_path' => $path, 'original_file_name' => 'drawing.txt', 'stored_file_name' => 'drawing.txt',
            'extension' => 'txt', 'mime_type' => 'text/plain', 'sha256' => hash('sha256', 'Identical source bytes'), 'ai_status' => 'queued', ...$attributes,
        ]);
    }
}
