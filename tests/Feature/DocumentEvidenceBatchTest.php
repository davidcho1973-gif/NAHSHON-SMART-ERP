<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Jobs\MatchDocumentEvidenceBatch;
use App\Models\ClaimEvidenceInboxItem;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\DocumentEvidenceBatch;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertService;
use App\Services\Documents\DocumentEvidenceBatchService;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\DocumentIntelligenceService;
use App\Services\Documents\StuckAnalysisReaper;
use App\Services\Finance\ClaimEvidenceInboxService;
use App\Support\JobAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DocumentEvidenceBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Project $project;

    private ProjectContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        config(['document-intelligence.disk' => 'local']);
        $this->actor = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->actor);
        $company = Company::create(['code' => 'BULK', 'name' => 'Bulk', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'BULK', 'name' => 'Bulk', 'status' => 'active']);
        $this->project = Project::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_code' => 'BULK', 'name' => 'Bulk', 'construction_type' => 'equipment_setting']);
        $this->contract = ProjectContract::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $this->project->id, 'direction' => 'receivable', 'title' => 'Claim', 'status' => 'draft']);
        ContractBoqLine::create(['project_contract_id' => $this->contract->id, 'line_no' => '1', 'description' => 'Pipe', 'unit' => 'EA', 'contract_qty' => 10, 'unit_price' => 20, 'status' => 'accepted', 'recognition_basis' => 'quantity']);
    }

    private function service(): DocumentEvidenceBatchService
    {
        return app(DocumentEvidenceBatchService::class);
    }

    private function batch(): DocumentEvidenceBatch
    {
        return $this->service()->create($this->actor, $this->project->id, null);
    }

    private function upload(DocumentEvidenceBatch $batch, string $contents = 'receipt', ?string $key = null): int
    {
        return $this->service()->upload($this->actor, $batch->id, UploadedFile::fake()->createWithContent('receipt.txt', $contents), $key ?: (string) Str::uuid())['item']['documentId'];
    }

    public function test_upload_preserves_original_photo_and_waits_for_explicit_start(): void
    {
        $batch = $this->batch();
        $photo = UploadedFile::fake()->image('receipt.jpg', 2800, 2000);
        $original = file_get_contents($photo->getRealPath());
        $result = $this->service()->upload($this->actor, $batch->id, $photo, (string) Str::uuid());
        $document = IntelligentDocument::findOrFail($result['item']['documentId']);
        $this->assertSame('uploaded', $document->ai_status);
        $this->assertSame('evidence_batch', $document->source);
        $this->assertSame(hash('sha256', $original), $document->sha256);
        $this->assertSame($original, Storage::disk('local')->get($document->file_path));
        $this->assertSame(1, $result['batch']['counts']['uploaded']);
        Queue::assertNothingPushed();
        $this->artisan('docs:reap-stuck')->run();
        $this->assertSame('uploaded', $document->fresh()->ai_status);
    }

    public function test_explicit_start_is_idempotent_and_serial_even_when_a_file_fails(): void
    {
        $batch = $this->batch();
        $one = $this->upload($batch, 'one');
        $two = $this->upload($batch, 'two');
        $this->service()->start($this->actor, $batch->id);
        $this->service()->start($this->actor, $batch->id);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 1);
        $this->assertSame('uploaded', IntelligentDocument::find($two)->ai_status);
        $job = Queue::pushed(AnalyzeIntelligentDocumentJob::class)->first();
        $processor = Mockery::mock(DocumentIntelligenceService::class);
        $processor->shouldReceive('process')->once()->andThrow(new \RuntimeException('synthetic analysis failure'));
        $job->handle($processor, app(UnifiedAlertService::class));
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 2);
        $this->assertSame('failed', IntelligentDocument::find($one)->ai_status);
        $this->assertSame('queued', IntelligentDocument::find($two)->ai_status);
        $job->handle($processor, app(UnifiedAlertService::class));
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 2);
        $next = Queue::pushed(AnalyzeIntelligentDocumentJob::class)->last();
        $success = Mockery::mock(DocumentIntelligenceService::class);
        $success->shouldReceive('process')->once()->andReturnUsing(fn ($doc) => tap($doc)->update(['ai_status' => 'ready']));
        $next->handle($success, app(UnifiedAlertService::class));
        Queue::assertPushed(MatchDocumentEvidenceBatch::class, 1);
        $this->assertSame('matching', $batch->fresh()->status);
        $this->assertSame(1, $this->service()->summary($batch->fresh())['counts']['failed']);
        $this->assertDatabaseCount('mobile_expenses', 0);
        $this->assertDatabaseCount('pay_applications', 0);
    }

    public function test_replayed_upload_and_session_keys_do_not_duplicate_originals(): void
    {
        $session = (string) Str::uuid();
        $batch = $this->service()->create($this->actor, $this->project->id, null, $session);
        $this->assertSame($batch->id, $this->service()->create($this->actor, $this->project->id, null, $session)->id);
        $key = (string) Str::uuid();
        $id = $this->upload($batch, 'original', $key);
        $this->assertSame($id, $this->upload($batch, 'original', $key));
        $this->assertSame($id, $this->upload($batch, 'original'));
        $this->assertDatabaseCount('document_evidence_batches', 1);
        $this->assertDatabaseCount('document_evidence_batch_items', 2);
        $this->assertDatabaseCount('intelligent_documents', 1);
        Queue::assertNothingPushed();
        $this->post('/document-hub/api/evidence-batches/'.$batch->id.'/files', ['file' => UploadedFile::fake()->createWithContent('receipt.txt', 'different'), 'upload_key' => $key], ['Accept' => 'application/json'])->assertConflict();
    }

    public function test_revoked_actor_stops_before_paid_analysis_and_retry_rechecks_current_scope(): void
    {
        $batch = $this->batch();
        $id = $this->upload($batch);
        $this->service()->start($this->actor, $batch->id);
        $job = Queue::pushed(AnalyzeIntelligentDocumentJob::class)->first();
        $this->actor->update(['account_status' => 'inactive']);
        $processor = Mockery::mock(DocumentIntelligenceService::class);
        $processor->shouldNotReceive('process');
        $job->handle($processor, app(UnifiedAlertService::class));
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertSame('failed', IntelligentDocument::find($id)->ai_status);
        $this->assertDatabaseCount('mobile_expenses', 0);
        $this->actor->forceFill(JobAccess::grant(['jobRole' => 'office', 'jobDuties' => ['accounting'],
            'companyId' => $this->project->company_id, 'scope' => 'company', 'siteIds' => [$this->project->site_id],
            'jobPermissions' => ['documents' => ['view', 'edit'], 'contracts' => ['view', 'edit']]]))->save();
        $this->actor->update(['account_status' => 'active']);
        $this->actingAs($this->actor);
        $this->service()->start($this->actor, $batch->id, true);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 2);
        $this->assertSame('queued', IntelligentDocument::find($id)->ai_status);
        $permissions = $this->actor->job_permissions;
        $permissions['documents'] = ['view'];
        $this->actor->forceFill(['job_permissions' => $permissions])->save();
        $this->assertFalse(JobAccess::can($this->actor->fresh(), 'documents', 'edit'));
        $this->assertSame($batch->fresh()->run_token, Queue::pushed(AnalyzeIntelligentDocumentJob::class)->last()->batchRunToken);
        Queue::pushed(AnalyzeIntelligentDocumentJob::class)->last()->handle($processor, app(UnifiedAlertService::class));
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertSame('failed', IntelligentDocument::find($id)->ai_status);
    }

    public function test_batch_scope_cannot_be_read_or_started_by_another_site(): void
    {
        $batch = $this->batch();
        $this->upload($batch);
        $manager = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => null, 'account_status' => 'active']);
        $this->actingAs($manager)->getJson('/document-hub/api/evidence-batches/'.$batch->id)->assertStatus(422);
        $this->postJson('/document-hub/api/evidence-batches/'.$batch->id.'/start')->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_storage_failure_does_not_silently_fall_back_to_local_or_report_saved(): void
    {
        $batch = $this->batch();
        config(['document-intelligence.disk' => 'broken', 'filesystems.disks.broken' => ['driver' => 's3', 'visibility' => 'private']]);
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('broken')->once()->andReturn($disk);
        $this->post('/document-hub/api/evidence-batches/'.$batch->id.'/files', ['file' => UploadedFile::fake()->createWithContent('receipt.txt', 'safe original'), 'upload_key' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('intelligent_documents', 0);
        $this->assertDatabaseCount('document_evidence_batch_items', 0);
        Queue::assertNothingPushed();
    }

    public function test_finalization_matches_only_batch_sources_and_never_enables_periodic_processing(): void
    {
        $batch = $this->batch();
        $id = $this->upload($batch);
        $batch->items()->update(['status' => 'done']);
        IntelligentDocument::whereKey($id)->update(['ai_status' => 'ready']);
        $this->service()->start($this->actor, $batch->id);
        Queue::assertNotPushed(AnalyzeIntelligentDocumentJob::class);
        $job = Queue::pushed(MatchDocumentEvidenceBatch::class)->first();
        $matcher = Mockery::mock(ClaimEvidenceInboxService::class);
        $matcher->shouldReceive('match')->with($this->contract->id, [$id])->once()->andReturn(['success' => true, 'linked' => 0]);
        $job->handle($this->service(), $matcher);
        $job->handle($this->service(), $matcher);
        $this->assertSame('done', $batch->fresh()->status);
        $this->assertEmpty($this->contract->fresh()->payload['dailyEvidenceAutomation'] ?? null);
        $this->service()->start($this->actor, $batch->id, false, true);
        Queue::assertNotPushed(AnalyzeIntelligentDocumentJob::class);
        Queue::assertPushed(MatchDocumentEvidenceBatch::class, 2);
    }

    public function test_existing_canonical_document_is_referenced_without_reanalysis_or_scope_mutation(): void
    {
        $result = app(DocumentIntake::class)->ingest(UploadedFile::fake()->createWithContent('receipt.txt', 'same'),
            ['company_id' => $this->project->company_id, 'site_id' => $this->project->site_id, 'project_id' => $this->project->id], ['uploaded_by' => $this->actor->id]);
        $document = $result['document'];
        $id = $document->id;
        $document->update(['ai_status' => 'failed']);
        Queue::fake();
        $before = $document->fresh()->getAttributes();
        $second = $this->batch();
        $this->assertSame($id, $this->upload($second, 'same'));
        $this->service()->start($this->actor, $second->id, true);
        $this->assertSame($before, $document->fresh()->getAttributes());
        Queue::assertNotPushed(AnalyzeIntelligentDocumentJob::class);
        $this->assertSame(1, $this->service()->summary($second->fresh())['counts']['failed']);
        Storage::disk('local')->delete($document->file_path);
        $restore = $this->batch();
        $this->assertSame($id, $this->upload($restore, 'same'));
        $this->assertSame('same', Storage::disk('local')->get($document->fresh()->file_path));
        $this->assertSame('failed', $document->fresh()->ai_status);
        $this->assertSame('dropzone', $document->fresh()->source);
        Queue::assertNotPushed(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_an_unstarted_canonical_evidence_original_can_be_explicitly_processed_by_another_batch(): void
    {
        $first = $this->batch();
        $id = $this->upload($first, 'shared staged original');
        $second = $this->batch();
        $this->assertSame($id, $this->upload($second, 'shared staged original'));
        Queue::assertNothingPushed();
        $this->service()->start($this->actor, $second->id);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 1);
        $this->assertSame('queued', IntelligentDocument::find($id)->ai_status);
        $this->service()->start($this->actor, $first->id);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 1);
        $job = Queue::pushed(AnalyzeIntelligentDocumentJob::class)->first();
        $this->assertSame($second->id, $job->evidenceBatchId);
        $this->assertSame(1, $this->service()->summary($first->fresh())['counts']['queued']);
    }

    public function test_queue_recovery_advances_terminal_failed_file_without_starting_drafts(): void
    {
        $batch = $this->batch();
        $id = $this->upload($batch, 'first');
        $nextId = $this->upload($batch, 'second');
        $draft = $this->batch();
        $draftId = $this->upload($draft, 'draft');
        $this->service()->start($this->actor, $batch->id);
        IntelligentDocument::whereKey($id)->update(['ai_status' => 'failed', 'ai_error' => 'terminated']);
        $batch->refresh()->update(['updated_at' => now()->subMinutes(20)]);
        app(StuckAnalysisReaper::class)->reap();
        $this->assertSame('queued', IntelligentDocument::find($nextId)->ai_status);
        $this->assertSame('uploaded', IntelligentDocument::find($draftId)->ai_status);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 2);
    }

    public function test_adopted_draft_with_a_later_contract_requires_review_without_mutating_the_original(): void
    {
        ContractBoqLine::where('project_contract_id', $this->contract->id)->update(['status' => 'draft']);
        $first = $this->batch();
        $id = $this->upload($first, 'shared original without a contract');
        $second = $this->batch();
        $this->assertSame($id, $this->upload($second, 'shared original without a contract'));
        $this->assertTrue($second->items()->first()->is_duplicate);
        $before = IntelligentDocument::findOrFail($id)->getAttributes();
        ContractBoqLine::where('project_contract_id', $this->contract->id)->update(['status' => 'accepted']);

        $this->service()->start($this->actor, $second->id);

        $this->assertSame($this->contract->id, $second->fresh()->project_contract_id);
        $this->assertSame($before, IntelligentDocument::findOrFail($id)->getAttributes());
        Queue::assertNotPushed(AnalyzeIntelligentDocumentJob::class);
        $view = $this->service()->show($this->actor, $second->id, 1, true);
        $this->assertCount(1, $view['rows']);
        $this->assertSame('review_required', $view['rows'][0]['status']);
        $this->assertStringContainsString('문서 상세', $view['rows'][0]['error']);
        $this->assertSame(1, $view['batch']['counts']['review_required']);
        $this->assertSame(0, $view['batch']['counts']['failed']);
        $matcher = Mockery::mock(ClaimEvidenceInboxService::class);
        $matcher->shouldReceive('match')->with($this->contract->id, [$id])->twice()->andReturn(['success' => true, 'linked' => 0]);
        Queue::pushed(MatchDocumentEvidenceBatch::class)->first()->handle($this->service(), $matcher);
        $this->assertSame('done', $second->fresh()->status);

        // The message points to a real explicit recovery action, not a dead end.
        $this->postJson('/document-hub/api/documents/'.$id.'/reanalyze')->assertAccepted();
        $this->postJson('/document-hub/api/documents/'.$id.'/reanalyze')->assertAccepted();
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 1);
        $processor = Mockery::mock(DocumentIntelligenceService::class);
        $processor->shouldReceive('process')->once()->andReturnUsing(fn ($doc) => tap($doc)->update(['ai_status' => 'ready']));
        Queue::pushed(AnalyzeIntelligentDocumentJob::class)->first()->handle($processor, app(UnifiedAlertService::class));
        $this->assertSame('ready', IntelligentDocument::findOrFail($id)->ai_status);
        $this->assertNull(IntelligentDocument::findOrFail($id)->project_contract_id);
        $this->service()->start($this->actor, $second->id, false, true);
        Queue::pushed(MatchDocumentEvidenceBatch::class)->last()->handle($this->service(), $matcher);
        $view = $this->service()->show($this->actor, $second->id, 1, true);
        $this->assertCount(0, $view['rows']);
        $this->assertSame('done', $second->fresh()->status);
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class, 1);
        $this->assertDatabaseCount('intelligent_documents', 1);
    }

    public function test_later_contract_binding_and_review_filter_do_not_reanalyze_completed_documents(): void
    {
        ContractBoqLine::where('project_contract_id', $this->contract->id)->update(['status' => 'draft']);
        $batch = $this->batch();
        $this->assertNull($batch->project_contract_id);
        $id = $this->upload($batch);
        IntelligentDocument::whereKey($id)->update(['ai_status' => 'ready']);
        $batch->items()->update(['status' => 'done']);
        $this->service()->start($this->actor, $batch->id);
        $this->assertSame('done', $batch->fresh()->status);
        ContractBoqLine::where('project_contract_id', $this->contract->id)->update(['status' => 'accepted']);
        $this->service()->start($this->actor, $batch->id, false, true, $this->contract->id);
        $this->assertSame($this->contract->id, IntelligentDocument::find($id)->project_contract_id);
        Queue::assertNotPushed(AnalyzeIntelligentDocumentJob::class);
        Queue::assertPushed(MatchDocumentEvidenceBatch::class, 1);
        ClaimEvidenceInboxItem::create(['project_contract_id' => $this->contract->id, 'source_type' => 'document', 'source_id' => $id, 'status' => 'review', 'reason' => 'Uncertain process']);
        $view = $this->service()->show($this->actor, $batch->id, 1, true);
        $this->assertCount(1, $view['rows']);
        $this->assertSame('review', $view['rows'][0]['claimStatus']);
        $this->assertSame(1, $view['batch']['claimCounts']['review']);
    }
}
