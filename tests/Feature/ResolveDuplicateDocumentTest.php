<?php

namespace Tests\Feature;

use App\Models\BoqItem;
use App\Models\Company;
use App\Models\DocumentActionItem;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResolveDuplicateDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['document-intelligence.disk' => 'local', 'filesystems.documents_disk' => 'local']);
        Storage::fake('local');
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_preview_is_read_only_and_does_not_start_analysis(): void
    {
        [$incoming, $existing] = $this->duplicateFixture();
        $before = $this->documentSnapshot();
        $files = Storage::disk('local')->allFiles();

        $this->artisan('docs:resolve-duplicate', ['document' => $incoming->id, 'existing' => $existing->id])
            ->expectsOutput('미리보기입니다. 변경하지 않았습니다.')
            ->assertSuccessful();

        $this->assertSame($before, $this->documentSnapshot());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_apply_preserves_originals_ready_analysis_and_all_business_references(): void
    {
        [$incoming, $existing, $project] = $this->duplicateFixture();
        $references = $this->businessReferences($incoming, $existing, $project);
        $beforeReferences = $this->referenceSnapshot($references);
        $existingBefore = $existing->refresh()->getRawOriginal();
        $sourceBefore = $incoming->refresh()->getRawOriginal();
        $contents = Storage::disk('local')->get($incoming->file_path);

        $this->apply($incoming, $existing);

        $incoming->refresh();
        $this->assertSame('review_required', $incoming->ai_status);
        $this->assertSame($existing->id, $incoming->ai_payload['duplicate_document_id']);
        $this->assertNotEmpty($incoming->ai_payload['duplicate_reason']);
        $this->assertSame(['Source analysis result'], $incoming->ai_payload['key_facts']);
        $this->assertNull($incoming->ai_error);
        $this->assertSame($existingBefore, $existing->refresh()->getRawOriginal());
        foreach (['id', 'company_id', 'site_id', 'project_id', 'uploaded_by', 'disk', 'file_path', 'sha256', 'title', 'summary'] as $field) {
            $this->assertSame($sourceBefore[$field], $incoming->getRawOriginal($field), $field.' must remain unchanged');
        }
        $this->assertSame($beforeReferences, $this->referenceSnapshot($references));
        $this->assertDatabaseCount('intelligent_documents', 2);
        $this->assertSame($contents, Storage::disk('local')->get($incoming->file_path));
        $this->assertSame($contents, Storage::disk('local')->get($existing->file_path));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_repeating_apply_is_idempotent_and_never_duplicates_business_records(): void
    {
        [$incoming, $existing, $project] = $this->duplicateFixture();
        $references = $this->businessReferences($incoming, $existing, $project);
        $this->apply($incoming, $existing);
        $first = Arr::except($incoming->refresh()->getRawOriginal(), ['updated_at']);
        $firstReferences = $this->referenceSnapshot($references);
        $existingBefore = $existing->refresh()->getRawOriginal();

        $this->travel(1)->minutes();
        $this->apply($incoming, $existing);

        $this->assertSame($first, Arr::except($incoming->refresh()->getRawOriginal(), ['updated_at']));
        $this->assertSame($existingBefore, $existing->refresh()->getRawOriginal());
        $this->assertSame($firstReferences, $this->referenceSnapshot($references));
        $this->assertDatabaseCount('intelligent_documents', 2);
        $this->assertDatabaseCount('integrated_documents', 1);
        $this->assertDatabaseCount('document_action_items', 2);
        $this->assertDatabaseCount('boq_items', 1);
        $this->assertDatabaseCount('submittals', 1);
        $this->assertDatabaseCount('submittal_documents', 2);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_different_project_is_refused_even_for_an_administrator_and_matching_hash(): void
    {
        [$incoming, $existing, $project] = $this->duplicateFixture();
        $otherProject = Project::query()->create([
            'company_id' => $project->company_id, 'site_id' => $project->site_id,
            'project_code' => 'OTHER-PROJECT', 'name' => 'Another project',
            'construction_type' => 'equipment_setting',
        ]);
        $incoming->update(['project_id' => $otherProject->id]);

        $this->assertRefusedWithoutMutation($incoming, $existing);
    }

    public function test_different_file_hash_is_refused(): void
    {
        [$incoming, $existing] = $this->duplicateFixture();
        $incoming->update(['sha256' => hash('sha256', 'A different file.')]);
        Storage::disk('local')->put($incoming->file_path, 'A different file.');

        $this->assertRefusedWithoutMutation($incoming, $existing);
    }

    public function test_an_active_analysis_is_not_modified(): void
    {
        [$incoming, $existing] = $this->duplicateFixture();
        $incoming->update(['ai_status' => 'analyzing']);

        $this->assertRefusedWithoutMutation($incoming, $existing);
    }

    public function test_canonical_document_must_already_be_ready(): void
    {
        [$incoming, $existing] = $this->duplicateFixture();
        $existing->update(['ai_status' => 'queued']);

        $this->assertRefusedWithoutMutation($incoming, $existing);
    }

    public function test_a_duplicate_outside_the_uploaders_scope_is_not_linked(): void
    {
        [$incoming, $existing] = $this->duplicateFixture();
        $otherCompany = Company::query()->create(['code' => 'OTHER', 'name' => 'Other company', 'status' => 'active']);
        $restrictedUploader = User::factory()->create([
            'access_role' => 'hr_manager', 'access_scope' => 'company',
            'allowed_company_id' => $otherCompany->id, 'account_status' => 'active',
        ]);
        $incoming->update(['company_id' => $otherCompany->id, 'uploaded_by' => $restrictedUploader->id]);

        $this->assertRefusedWithoutMutation($incoming, $existing);
    }

    public function test_missing_original_prevents_repair(): void
    {
        [$incoming, $existing] = $this->duplicateFixture();
        Storage::disk('local')->delete($incoming->file_path);

        $this->assertRefusedWithoutMutation($incoming, $existing);
    }

    public function test_unconfirmed_scope_analysis_does_not_overwrite_the_integrated_document(): void
    {
        [$incoming, $existing, $project] = $this->duplicateFixture();
        $references = $this->businessReferences($incoming, $existing, $project);
        $before = $this->referenceSnapshot($references);

        $incoming->update([
            'ai_status' => 'review_required', 'title' => 'Unconfirmed AI title',
            'extracted_text' => 'Unconfirmed AI body',
            'ai_payload' => ['scope_review_reason' => 'The project needs human confirmation.'],
        ]);

        $this->assertSame($before, $this->referenceSnapshot($references));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    private function apply(IntelligentDocument $incoming, IntelligentDocument $existing): void
    {
        $this->artisan('docs:resolve-duplicate', [
            'document' => $incoming->id, 'existing' => $existing->id, '--apply' => true,
        ])->assertSuccessful();
    }

    private function assertRefusedWithoutMutation(IntelligentDocument $incoming, IntelligentDocument $existing): void
    {
        $before = $this->documentSnapshot();
        $files = Storage::disk('local')->allFiles();
        $this->artisan('docs:resolve-duplicate', [
            'document' => $incoming->id, 'existing' => $existing->id, '--apply' => true,
        ])->assertFailed();
        $this->assertSame($before, $this->documentSnapshot());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    private function documentSnapshot(): array
    {
        return IntelligentDocument::query()->orderBy('id')->get()->map(fn ($document) => $document->getRawOriginal())->all();
    }

    /** @return array{IntelligentDocument, IntelligentDocument, Project} */
    private function duplicateFixture(): array
    {
        $company = Company::query()->create(['code' => 'EXAMPLE', 'name' => 'Example Company', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'SITE-ALPHA', 'name' => 'Example site', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => 'PROJECT-ALPHA', 'name' => 'Commercial kitchen',
            'construction_type' => 'equipment_setting',
        ]);
        $admin = User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $contents = 'Identical architecture PDF fixture content.';
        $existing = $this->document($admin, $contents, [
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'title' => 'Existing reviewed drawing', 'summary' => 'Previously reviewed analysis.', 'ai_status' => 'ready',
        ]);
        $incoming = $this->document($admin, $contents, [
            'title' => 'Newly uploaded drawing', 'summary' => 'Source analysis.', 'ai_status' => 'failed',
            'ai_error' => 'Previous duplicate save failure.',
            'ai_payload' => ['key_facts' => ['Source analysis result']],
        ]);

        return [$incoming, $existing, $project];
    }

    private function document(User $user, string $contents, array $attributes): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $path = 'document-intelligence/inbox/'.$uuid.'/drawing.pdf';
        Storage::disk('local')->put($path, $contents);

        return IntelligentDocument::withoutEvents(fn () => IntelligentDocument::query()->create([
            'uuid' => $uuid, 'uploaded_by' => $user->id, 'disk' => 'local', 'file_path' => $path,
            'original_file_name' => 'drawing.pdf', 'stored_file_name' => 'drawing.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => strlen($contents),
            'sha256' => hash('sha256', $contents), 'received_at' => now(),
            'category' => 'drawing_spec', 'document_type' => 'drawing',
            ...$attributes,
        ]));
    }

    private function businessReferences(IntelligentDocument $incoming, IntelligentDocument $existing, Project $project): array
    {
        $mirrorPath = 'integrated-documents/reviewed-copy.pdf';
        Storage::disk('local')->put($mirrorPath, 'Independently retained integrated copy.');
        $mirror = IntegratedDocument::withoutEvents(fn () => IntegratedDocument::query()->create([
            'source_document_id' => $incoming->id, 'disk' => 'local', 'path' => $mirrorPath,
            'title' => 'Human edited integrated title', 'body_text' => 'Human reviewed body text',
            'status' => 'needs_review', 'folder_code' => '03',
        ]));
        $sourceAction = DocumentActionItem::query()->create([
            'intelligent_document_id' => $incoming->id, 'action_type' => 'review',
            'title' => 'Source document action', 'status' => 'in_progress',
        ]);
        $existingAction = DocumentActionItem::query()->create([
            'intelligent_document_id' => $existing->id, 'action_type' => 'review',
            'title' => 'Existing document action', 'status' => 'completed',
        ]);
        $boq = BoqItem::query()->create([
            'project_id' => $project->id, 'seq' => 1, 'discipline_code' => '09', 'discipline' => 'Finishes',
            'name_kr' => '천장 패널', 'unit' => 'SF', 'qty' => 120, 'qty_basis' => '문서확정', 'unit_price' => 8.5,
            'source_document_id' => $incoming->id,
        ]);
        $submittal = Submittal::query()->create([
            'project_id' => $project->id, 'seq' => 1, 'csi' => '09 5100', 'section' => 'Ceilings',
            'category' => 'Action 제출물', 'title' => 'Ceiling sample', 'status' => '제출',
            'source_document_id' => $existing->id,
        ]);
        $submittal->documents()->attach([$incoming->id => ['kind' => 'received'], $existing->id => ['kind' => 'approval']]);

        return [$mirror, $sourceAction, $existingAction, $boq, $submittal];
    }

    private function referenceSnapshot(array $records): array
    {
        return [
            'records' => array_map(fn ($record) => $record->refresh()->getRawOriginal(), $records),
            'submittal_documents' => DB::table('submittal_documents')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'integrated_file' => Storage::disk('local')->get('integrated-documents/reviewed-copy.pdf'),
        ];
    }
}
