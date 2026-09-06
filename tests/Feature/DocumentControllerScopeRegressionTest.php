<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentControllerScopeRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['document-intelligence.disk' => 'local']);
        Storage::fake('local');
        Bus::fake();
    }

    public function test_dropzone_defaults_to_the_company_selected_in_the_erp_session(): void
    {
        $this->projectFixture('AAA');
        [$selectedCompany] = $this->projectFixture('EXAMPLE');

        $this->actingAs($this->user())
            ->withSession([CurrentCompany::SESSION_KEY => $selectedCompany->id])
            ->get(route('document-intelligence.index', ['embed' => 1]))
            ->assertOk()
            ->assertViewHas('defaultCompanyId', $selectedCompany->id);
    }

    public function test_upload_without_an_explicit_company_uses_the_session_choice(): void
    {
        $this->projectFixture('AAA');
        [$selectedCompany] = $this->projectFixture('EXAMPLE');

        $this->actingAs($this->user())
            ->withSession([CurrentCompany::SESSION_KEY => $selectedCompany->id])
            ->postJson(route('document-intelligence.upload'), [
                'files' => [UploadedFile::fake()->createWithContent('A-101.txt', 'Room finish schedule for Building Alpha.')],
            ])
            ->assertStatus(202)
            ->assertJsonCount(1, 'documents');

        $document = IntelligentDocument::query()->sole();
        $this->assertSame($selectedCompany->id, $document->company_id);
        $this->assertNull($document->site_id);
        $this->assertNull($document->project_id);
        Bus::assertDispatched(AnalyzeIntelligentDocumentJob::class, fn ($job): bool => $job->documentId === $document->id);
    }

    public function test_admin_can_explicitly_select_global_despite_a_session_company(): void
    {
        [$selectedCompany] = $this->projectFixture('EXAMPLE');

        $this->actingAs($this->user())
            ->withSession([CurrentCompany::SESSION_KEY => $selectedCompany->id])
            ->postJson(route('document-intelligence.upload'), [
                'company_id' => '',
                'site_id' => '',
                'project_id' => '',
                'files' => [UploadedFile::fake()->createWithContent('general.txt', 'General company-independent reference document.')],
            ])
            ->assertStatus(202)
            ->assertJsonCount(1, 'documents');

        $document = IntelligentDocument::query()->sole();
        $this->assertNull($document->company_id);
        $this->assertNull($document->site_id);
        $this->assertNull($document->project_id);
    }

    public function test_upload_rejects_a_project_that_does_not_belong_to_the_requested_site(): void
    {
        [$company, , $project] = $this->projectFixture('EXAMPLE');
        $otherSite = Site::query()->create([
            'company_id' => $company->id, 'code' => 'OTHER-SITE',
            'name' => 'Another site', 'status' => 'active',
        ]);

        $this->actingAs($this->user())
            ->postJson(route('document-intelligence.upload'), [
                'company_id' => $company->id,
                'site_id' => $otherSite->id,
                'project_id' => $project->id,
                'files' => [UploadedFile::fake()->createWithContent('plan.txt', 'This project belongs to a different site.')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->assertDatabaseCount('intelligent_documents', 0);
        Bus::assertNotDispatched(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_manual_filing_preserves_both_documents_when_the_destination_already_contains_the_file(): void
    {
        [$company, $site, $project] = $this->projectFixture('EXAMPLE');
        $admin = $this->user();
        $contents = 'Identical Building Alpha architectural drawing content.';
        $existing = $this->document($admin, $contents, [
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'title' => 'Previously reviewed drawing', 'ai_status' => 'ready',
            'summary' => 'The existing approved analysis must remain intact.',
        ]);
        $incoming = $this->document($admin, $contents, [
            'title' => 'New upload', 'ai_status' => 'failed',
            'ai_payload' => ['key_facts' => ['Incoming analysis is preserved.']],
        ]);
        $existingBefore = $existing->refresh()->getRawOriginal();

        $this->actingAs($admin)
            ->patchJson(route('document-intelligence.review', $incoming), [
                'title' => 'Refiled drawing', 'category' => 'drawing_spec',
                'document_type' => 'drawing', 'site_id' => $site->id,
                'project_id' => $project->id,
            ])
            ->assertOk()
            ->assertJsonPath('document.aiStatus', 'review_required')
            ->assertJsonPath('document.duplicateDocumentId', $existing->id);

        $this->assertSame($existingBefore, $existing->refresh()->getRawOriginal());
        $incoming->refresh();
        $this->assertNull($incoming->company_id);
        $this->assertNull($incoming->site_id);
        $this->assertNull($incoming->project_id);
        $this->assertNull($incoming->ai_error);
        $this->assertSame(['Incoming analysis is preserved.'], $incoming->ai_payload['key_facts']);
        $this->assertNotEmpty($incoming->ai_payload['duplicate_reason']);
        $this->assertDatabaseCount('intelligent_documents', 2);
        Storage::disk('local')->assertExists([$existing->file_path, $incoming->file_path]);
        Bus::assertNotDispatched(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_reanalyze_does_not_enqueue_an_extra_job_for_an_active_analysis(): void
    {
        $admin = $this->user();
        $document = $this->document($admin, 'Document already being analyzed.', ['ai_status' => 'analyzing']);

        $this->actingAs($admin)
            ->postJson(route('document-intelligence.reanalyze', $document))
            ->assertStatus(202)
            ->assertJsonPath('success', true);

        $this->assertSame('analyzing', $document->refresh()->ai_status);
        Bus::assertNotDispatched(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_duplicate_links_and_payload_do_not_expose_documents_outside_the_viewers_scope(): void
    {
        [$company, $site, $project] = $this->projectFixture('ALLOWED');
        [$otherCompany, $otherSite, $otherProject] = $this->projectFixture('RESTRICTED');
        $admin = $this->user();
        $existing = $this->document($admin, 'Restricted company original.', [
            'company_id' => $otherCompany->id, 'site_id' => $otherSite->id,
            'project_id' => $otherProject->id, 'ai_status' => 'ready',
        ]);
        $incoming = $this->document($admin, 'Allowed document with a stale duplicate reference.', [
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'ai_status' => 'review_required',
            'ai_payload' => [
                'duplicate_document_id' => $existing->id,
                'duplicate_target_scope' => [
                    'company_id' => $otherCompany->id, 'site_id' => $otherSite->id,
                    'project_id' => $otherProject->id,
                ],
                'key_facts' => ['The visible document analysis remains available.'],
            ],
        ]);
        $siteManager = $this->user([
            'access_role' => 'site_manager', 'access_scope' => 'site',
            'allowed_site_id' => $site->id, 'allowed_company_id' => $company->id,
        ]);

        $this->actingAs($siteManager)
            ->getJson(route('document-intelligence.show', $incoming))
            ->assertOk()
            ->assertJsonPath('document.duplicateDocumentId', null)
            ->assertJsonMissingPath('document.aiPayload.duplicate_document_id')
            ->assertJsonMissingPath('document.aiPayload.duplicate_target_scope')
            ->assertJsonPath('document.aiPayload.key_facts.0', 'The visible document analysis remains available.');
        $this->getJson(route('document-intelligence.show', $existing))->assertNotFound();
        $this->get(route('document-intelligence.download', $existing))->assertNotFound();

        $this->actingAs($admin)
            ->getJson(route('document-intelligence.show', $incoming))
            ->assertOk()
            ->assertJsonPath('document.duplicateDocumentId', $existing->id);
    }

    /** @return array{Company, Site, Project} */
    private function projectFixture(string $code): array
    {
        $company = Company::query()->create(['code' => $code, 'name' => $code.' MEP', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => $code.'-SITE',
            'name' => $code.' Site', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => $code.'-PROJECT', 'name' => $code.' kitchen',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);

        return [$company, $site, $project];
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
            ...$attributes,
        ]);
    }

    private function document(User $user, string $contents, array $attributes = []): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $path = 'document-intelligence/inbox/'.$uuid.'/drawing.txt';
        Storage::disk('local')->put($path, $contents);

        return IntelligentDocument::withoutEvents(fn () => IntelligentDocument::query()->create([
            'uuid' => $uuid, 'uploaded_by' => $user->id, 'disk' => 'local', 'file_path' => $path,
            'original_file_name' => 'drawing.txt', 'stored_file_name' => 'drawing.txt',
            'mime_type' => 'text/plain', 'extension' => 'txt', 'file_size' => strlen($contents),
            'sha256' => hash('sha256', $contents), 'title' => 'Drawing', 'received_at' => now(),
            'ai_status' => 'queued', 'category' => 'drawing_spec', 'document_type' => 'drawing',
            ...$attributes,
        ]));
    }
}
