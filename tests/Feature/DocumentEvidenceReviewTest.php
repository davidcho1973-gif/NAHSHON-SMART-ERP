<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\DocumentActionItem;
use App\Models\DocumentEvidenceBatch;
use App\Models\DocumentEvidenceBatchItem;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DocumentEvidenceReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document-intelligence.disk' => 'local']);
        Storage::fake('local');
        $this->admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->admin);
        $company = Company::create(['code' => 'EVREV', 'name' => 'Evidence', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'EVREV', 'name' => 'Evidence', 'timezone' => 'UTC', 'status' => 'active']);
        $this->project = Project::create(['company_id' => $company->id, 'site_id' => $site->id, 'project_code' => 'EVREV', 'name' => 'Evidence', 'construction_type' => 'equipment_setting']);
    }

    public function test_preview_serves_private_derivative_while_download_preserves_original(): void
    {
        $document = $this->document();
        $original = Storage::disk('local')->get($document->file_path);
        $path = $document->ai_payload['image_derivative']['path'];
        $preview = $this->get(route('document-intelligence.preview', $document))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $preview->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('local')->get($path), $preview->streamedContent());
        $this->assertSame($original, $this->get(route('document-intelligence.download', $document))->assertOk()->streamedContent());
        $this->assertSame($document->sha256, hash('sha256', $original));
    }

    public function test_evidence_entry_requires_billing_management_without_removing_ordinary_document_management(): void
    {
        $this->get(route('document-intelligence.index', ['embed' => 1]))->assertOk()
            ->assertViewHas('canEvidenceManage', true)->assertViewHas('canManage', true);
        $safety = User::factory()->create(['access_role' => 'safety_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($safety)->get(route('document-intelligence.index', ['embed' => 1]))->assertOk()
            ->assertViewHas('canEvidenceManage', false)->assertViewHas('canManage', true);
    }

    public function test_forged_or_corrupt_derivative_never_exposes_or_deletes_an_unrelated_file(): void
    {
        $document = $this->document();
        $original = Storage::disk('local')->get($document->file_path);
        $metadata = $document->ai_payload['image_derivative'];
        Storage::disk('local')->put('private/another-person.jpg', 'DO NOT EXPOSE');
        foreach ([['path' => 'private/another-person.jpg'], ['original_sha256' => str_repeat('f', 64)], ['disk' => 'public']] as $change) {
            $payload = $document->ai_payload;
            $payload['image_derivative'] = array_replace($metadata, $change);
            $document->update(['ai_payload' => $payload]);
            $this->assertSame($original, $this->get(route('document-intelligence.preview', $document))->assertOk()->streamedContent());
        }
        $payload['image_derivative'] = $metadata;
        $document->update(['ai_payload' => $payload]);
        Storage::disk('local')->put($metadata['path'], 'CHANGED DERIVATIVE');
        $this->assertSame($original, $this->get(route('document-intelligence.preview', $document))->assertOk()->streamedContent());
        $payload['image_derivative']['path'] = 'private/another-person.jpg';
        $document->update(['ai_payload' => $payload]);
        $this->deleteJson(route('document-intelligence.destroy', $document))->assertOk();
        Storage::disk('local')->assertExists('private/another-person.jpg');
    }

    public function test_private_owner_scope_applies_to_preview_and_original_even_for_admin(): void
    {
        $document = $this->document(['access_level' => 'private', 'owner_user_id' => User::factory()->create()->id]);
        $this->get(route('document-intelligence.preview', $document))->assertNotFound();
        $this->get(route('document-intelligence.download', $document))->assertNotFound();
    }

    public function test_only_explicit_review_with_original_resolution_note_clears_source_warning_and_preserves_history(): void
    {
        // Match CI's database timezone even when the app uses America/Phoenix.
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $document = $this->document();
        $this->patchJson(route('document-intelligence.review', $document), $this->reviewData($document, ['title' => 'Renamed only']))
            ->assertOk()->assertJsonPath('document.aiStatus', 'review_required');
        $this->assertNotEmpty($document->fresh()->ai_payload['source_review_reason']);
        $payload = $this->reviewData($document, ['evidence_reviewed' => true]);
        $this->patchJson(route('document-intelligence.review', $document), $payload)->assertUnprocessable()->assertJsonValidationErrors('evidence_review_note');
        $payload['evidence_review_note'] = 'Original printed total $120 is correct; handwritten $100 was an estimate.';
        $this->patchJson(route('document-intelligence.review', $document), $payload)->assertOk()->assertJsonPath('document.aiStatus', 'ready');
        $document->refresh();
        $history = $document->ai_payload['evidence_review']['history'];
        $this->assertCount(1, $history);
        $this->assertSame($this->admin->id, $history[0]['reviewed_by']);
        $this->assertSame($document->sha256, $history[0]['source_sha256']);
        $this->assertSame('Printed and handwritten amount conflict', $history[0]['reason']);
        $this->assertSame($payload['evidence_review_note'], $history[0]['note']);
        $this->assertNotEmpty($history[0]['reviewed_at']);
        $this->assertArrayNotHasKey('source_review_reason', $document->ai_payload);
        $this->assertNotEmpty($document->ai_payload['evidence']['conflicts'], 'observations remain intact after human resolution');
        $this->patchJson(route('document-intelligence.review', $document), $payload)->assertOk();
        $this->assertSame($history, $document->fresh()->ai_payload['evidence_review']['history'], 'response retry cannot duplicate or rewrite review history');
    }

    public function test_new_analysis_or_missing_original_cannot_be_approved_from_an_old_review_form(): void
    {
        $document = $this->document();
        $payload = $this->reviewData($document, ['evidence_reviewed' => true, 'evidence_review_note' => 'Read original']);
        $document->update(['analyzed_at' => $document->fresh()->analyzed_at->addMinute()]);
        $this->patchJson(route('document-intelligence.review', $document), $payload)->assertConflict()
            ->assertJsonPath('message', '분석 내용이 바뀌었거나 검토 화면이 오래되었습니다. 새로 열어 확인해 주세요.');
        $payload = $this->reviewData($document, ['evidence_reviewed' => true, 'evidence_review_note' => 'Read original']);
        Storage::disk('local')->delete($document->file_path);
        $this->patchJson(route('document-intelligence.review', $document), $payload)->assertConflict()
            ->assertJsonPath('message', '원본 파일을 확인할 수 없어 검토를 완료할 수 없습니다.');
        $this->assertNotEmpty($document->fresh()->ai_payload['source_review_reason']);
        $this->assertArrayNotHasKey('evidence_review', $document->fresh()->ai_payload);
    }

    public function test_review_version_tracks_analysis_changes_not_database_timezone_representation(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $document = $this->document();
        $payload = $this->reviewData($document, ['evidence_reviewed' => true, 'evidence_review_note' => 'Read original']);
        $utcVersion = $document->fresh()->evidenceReviewVersion();
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $this->assertSame($utcVersion, $document->fresh()->evidenceReviewVersion());
        $this->patchJson(route('document-intelligence.review', $document), $payload)->assertOk()->assertJsonPath('document.aiStatus', 'ready');

        // A changed result in the same second is also a genuinely stale form.
        $document->refresh();
        $analysis = $document->ai_payload;
        $analysis['evidence']['conflicts'][0]['handwritten_value'] = '90';
        $document->update(['ai_payload' => $analysis]);
        $this->patchJson(route('document-intelligence.review', $document), $payload)->assertConflict()
            ->assertJsonPath('message', '분석 내용이 바뀌었거나 검토 화면이 오래되었습니다. 새로 열어 확인해 주세요.');
    }

    public function test_unanalyzed_batch_cannot_be_marked_ready_and_its_source_cannot_be_changed(): void
    {
        $document = $this->document(['ai_status' => 'uploaded', 'analyzed_at' => null]);
        $this->patchJson(route('document-intelligence.review', $document), $this->reviewData($document, ['evidence_reviewed' => true, 'evidence_review_note' => 'Skip analysis']))->assertConflict();
        $this->assertSame('uploaded', $document->fresh()->ai_status);
        $this->expectException(ValidationException::class);
        $document->update(['source' => 'dropzone']);
    }

    public function test_protected_claim_original_derivative_and_actions_survive_delete_but_unlinked_copies_are_cleaned(): void
    {
        $document = $this->document();
        $action = DocumentActionItem::create(['intelligent_document_id' => $document->id, 'action_type' => 'quality', 'title' => 'Retain action']);
        $contract = ProjectContract::create(['project_id' => $this->project->id, 'company_id' => $this->project->company_id, 'site_id' => $this->project->site_id,
            'title' => 'Contract', 'direction' => 'receivable', 'status' => 'active', 'original_amount' => 100]);
        $line = ContractBoqLine::create(['project_contract_id' => $contract->id, 'line_no' => '1', 'description' => 'Pipe', 'unit' => 'EA',
            'contract_qty' => 1, 'unit_price' => 100, 'source_document_id' => $document->id, 'status' => 'accepted']);
        $this->deleteJson(route('document-intelligence.destroy', $document))->assertUnprocessable();
        Storage::disk('local')->assertExists($document->file_path);
        Storage::disk('local')->assertExists($document->ai_payload['image_derivative']['path']);
        $this->assertModelExists($document);
        $this->assertModelExists($action);
        $line->delete();
        $this->deleteJson(route('document-intelligence.destroy', $document))->assertOk();
        Storage::disk('local')->assertMissing($document->file_path);
        Storage::disk('local')->assertMissing($document->ai_payload['image_derivative']['path']);
        $this->assertModelMissing($document);
        $this->assertModelMissing($action);
    }

    public function test_optional_derivative_cleanup_failure_does_not_leave_a_live_record_without_its_original(): void
    {
        $document = $this->document();
        $disk = Storage::disk('local');
        $previewPath = $document->ai_payload['image_derivative']['path'];
        $adapter = Mockery::mock($disk);
        $adapter->shouldReceive('delete')->with($previewPath)->once()->andThrow(new \RuntimeException('Preview cleanup unavailable'));
        $adapter->shouldReceive('delete')->with($document->file_path)->once()->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($adapter);
        $this->deleteJson(route('document-intelligence.destroy', $document))->assertOk();
        $this->assertModelMissing($document);
        $this->assertFalse($disk->exists($document->file_path));
        $this->assertTrue($disk->exists($previewPath));
    }

    public function test_batch_reference_rejects_deletion_before_removing_original_derivative_or_actions(): void
    {
        $document = $this->document();
        $action = DocumentActionItem::create(['intelligent_document_id' => $document->id, 'action_type' => 'quality', 'title' => 'Retain action']);
        $batch = DocumentEvidenceBatch::create(['company_id' => $this->project->company_id, 'site_id' => $this->project->site_id,
            'project_id' => $this->project->id, 'created_by' => $this->admin->id, 'status' => 'draft']);
        $item = DocumentEvidenceBatchItem::create(['batch_id' => $batch->id, 'document_id' => $document->id, 'upload_key' => (string) Str::uuid(),
            'original_name' => $document->original_file_name, 'original_sha256' => $document->sha256, 'status' => 'waiting']);
        $this->deleteJson(route('document-intelligence.destroy', $document))->assertUnprocessable()->assertJsonValidationErrors('document');
        Storage::disk('local')->assertExists($document->file_path);
        Storage::disk('local')->assertExists($document->ai_payload['image_derivative']['path']);
        $this->assertModelExists($document);
        $this->assertModelExists($action);
        $this->assertModelExists($item);
    }

    private function document(array $overrides = []): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $original = 'original evidence bytes '.$uuid;
        $copy = 'small evidence bytes '.$uuid;
        $sourcePath = 'document-intelligence/inbox/'.$uuid.'/original.png';
        $copyPath = 'document-intelligence/derivatives/'.$uuid.'/'.hash('sha256', $copy).'.jpg';
        Storage::disk('local')->put($sourcePath, $original);
        Storage::disk('local')->put($copyPath, $copy);

        return IntelligentDocument::create(array_replace([
            'uuid' => $uuid, 'company_id' => $this->project->company_id, 'site_id' => $this->project->site_id, 'project_id' => $this->project->id,
            'uploaded_by' => $this->admin->id, 'source' => 'evidence_batch', 'disk' => 'local', 'file_path' => $sourcePath,
            'original_file_name' => 'receipt.png', 'stored_file_name' => 'receipt.png', 'mime_type' => 'image/png', 'extension' => 'png',
            'file_size' => strlen($original), 'sha256' => hash('sha256', $original), 'title' => 'Evidence receipt', 'category' => 'finance', 'document_type' => 'receipt',
            'received_at' => now(), 'analyzed_at' => now(), 'ai_status' => 'review_required',
            'ai_payload' => ['source_review_reason' => 'Printed and handwritten amount conflict',
                'evidence' => ['conflicts' => [['printed_value' => '120', 'handwritten_value' => '100']]],
                'image_derivative' => ['disk' => 'local', 'path' => $copyPath, 'mime' => 'image/jpeg', 'original_sha256' => hash('sha256', $original), 'derivative_sha256' => hash('sha256', $copy)]],
        ], $overrides));
    }

    private function reviewData(IntelligentDocument $document, array $extra = []): array
    {
        // Browsers receive this opaque version from show(), never manufacture it
        // from the pre-database model whose timestamp has not round-tripped yet.
        $version = $this->getJson(route('document-intelligence.show', $document))->assertOk()->json('document.evidenceReviewVersion');

        return array_replace(['title' => $document->title, 'category' => $document->category, 'document_type' => $document->document_type,
            'site_id' => $document->site_id, 'project_id' => $document->project_id, 'evidence_review_version' => $version], $extra);
    }
}
