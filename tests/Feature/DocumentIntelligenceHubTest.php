<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\Company;
use App\Models\DocumentActionItem;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertService;
use App\Services\Documents\DocumentIntelligenceService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class DocumentIntelligenceHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['document-intelligence.disk' => 'local']);
        Storage::fake('local');
    }

    public function test_admin_can_upload_private_documents_and_duplicate_content_is_not_stored_twice(): void
    {
        [$company, $site, $project] = $this->projectFixture();
        $admin = $this->user('admin');
        Bus::fake();

        $payload = [
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_id' => $project->id,
            'files' => [UploadedFile::fake()->createWithContent('RFI-017-response.txt', 'Response due in seven days.')],
        ];

        $this->actingAs($admin)
            ->post(route('document-intelligence.upload'), $payload)
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'documents')
            ->assertJsonCount(0, 'duplicates');

        $document = IntelligentDocument::query()->sole();
        $this->assertSame($company->id, $document->company_id);
        $this->assertSame($site->id, $document->site_id);
        $this->assertSame($project->id, $document->project_id);
        $this->assertSame('queued', $document->ai_status);
        $this->assertStringStartsWith('document-intelligence/inbox/', $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
        Bus::assertDispatched(AnalyzeIntelligentDocumentJob::class, fn (AnalyzeIntelligentDocumentJob $job): bool => $job->documentId === $document->id);

        $this->actingAs($admin)
            ->post(route('document-intelligence.upload'), $payload)
            ->assertStatus(202)
            ->assertJsonCount(0, 'documents')
            ->assertJsonCount(1, 'duplicates')
            ->assertJsonPath('duplicates.0.documentId', $document->id);

        $this->assertDatabaseCount('intelligent_documents', 1);
    }

    public function test_ai_analysis_classifies_indexes_organizes_and_creates_preventive_alerts(): void
    {
        [$company, $site, $project] = $this->projectFixture();
        $admin = $this->user('admin');
        $document = $this->document($admin, $company, $site, $project, 'notice.txt', 'Liquidated damages apply unless notice is sent by July 25.');

        $this->mock(OcrEngine::class, function (MockInterface $mock): void {
            $mock->shouldReceive('name')->once()->andReturn('test-ai');
            $mock->shouldReceive('analyze')->once()->andReturn([
                'model' => 'test-document-model',
                'data' => [
                    'title' => 'Delay Notice Requirement',
                    'category' => 'legal',
                    'document_type' => 'notice',
                    'discipline' => 'Project Controls',
                    'direction' => 'incoming',
                    'document_number' => 'NOTICE-017',
                    'revision' => '0',
                    'sender' => 'General Contractor',
                    'recipients' => ['XYZ MEP'],
                    'document_date' => '2026-07-20',
                    'response_due_on' => '2026-07-25',
                    'confidentiality' => 'confidential',
                    'summary' => 'Delay notice is required to preserve time and cost rights.',
                    'folder_parts' => ['Claims', 'Notices', '2026'],
                    'tags' => ['delay', 'claim'],
                    'keywords' => ['Liquidated Damages', 'NOTICE-017', 'July 25'],
                    'key_facts' => ['Failure to notify may waive the claim.'],
                    'project_code' => 'LGES-AZ-2026-001',
                    'confidence' => 96,
                    'action_items' => [[
                        'action_type' => 'notice',
                        'related_module' => 'CONTRACT',
                        'severity' => 'critical',
                        'title' => 'Submit delay notice',
                        'details' => 'Submit written notice before the contractual deadline.',
                        'recommended_action' => 'Route draft to the Project Manager today.',
                        'source_excerpt' => 'Notice shall be submitted within five days.',
                        'due_on' => '2026-07-25',
                        'remind_days_before' => 5,
                        'confidence' => 95,
                    ]],
                ],
            ]);
        });

        $processed = app(DocumentIntelligenceService::class)->process($document)->refresh();

        $this->assertSame('ready', $processed->ai_status);
        $this->assertSame('legal', $processed->category);
        $this->assertSame('notice', $processed->document_type);
        $this->assertSame('NOTICE-017', $processed->document_number);
        $this->assertSame('2026-07-25', $processed->response_due_on?->toDateString());
        $this->assertSame('XYZ / LGES-AZ-2026-001 / Claims / Notices / 2026', $processed->virtual_path);
        $this->assertStringContainsString('Liquidated Damages', $processed->search_text);
        $this->assertStringStartsWith('document-intelligence/library/', $processed->file_path);
        Storage::disk('local')->assertExists($processed->file_path);

        $action = DocumentActionItem::query()->sole();
        $this->assertSame('critical', $action->severity);
        $this->assertSame('Submit delay notice', $action->title);

        $alert = UnifiedAlert::query()->sole();
        $this->assertSame('DOC', $alert->source_module);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('/document-hub?document='.$processed->id, $alert->action_url);
    }

    public function test_keyword_search_and_document_download_respect_site_scope(): void
    {
        [$company, $site, $project] = $this->projectFixture();
        $otherSite = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'OTHER-AZ',
            'name' => 'Other Arizona Site',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $otherProject = Project::query()->create([
            'company_id' => $company->id,
            'site_id' => $otherSite->id,
            'project_code' => 'OTHER-AZ-2026-001',
            'name' => 'Other Project',
            'construction_type' => 'equipment_setting',
            'project_stage' => 'awarded',
        ]);
        $admin = $this->user('admin');
        $allowed = $this->document($admin, $company, $site, $project, 'submittal.pdf', '%PDF private', [
            'title' => 'Firestop Submittal',
            'search_text' => 'Hilti firestop UL system W-L-1054',
            'keywords' => ['Hilti', 'firestop'],
            'ai_status' => 'ready',
        ]);
        $blocked = $this->document($admin, $company, $otherSite, $otherProject, 'secret.pdf', '%PDF secret', [
            'title' => 'Other Site Secret',
            'search_text' => 'Hilti restricted other site',
            'ai_status' => 'ready',
        ]);
        // MIME 이 text/plain 인 가짜 docx — 변환은 실패해도 MIME 폴백으로 텍스트 미리보기가 된다.
        $officeDocument = $this->document($admin, $company, $site, $project, 'method-statement.docx', 'PK office document', [
            'title' => 'Method Statement',
            'search_text' => 'Method statement for permitted work',
            'ai_status' => 'ready',
        ]);
        // 확장자·파일명·MIME 셋 다 알아볼 수 없는 형식 — 이것만 415 가 맞다.
        $archive = $this->document($admin, $company, $site, $project, 'photos.zip', 'PK archive', [
            'title' => 'Photo Archive',
            'mime_type' => 'application/zip',
            'extension' => 'zip',
            'ai_status' => 'ready',
        ]);
        $siteManager = $this->user('site_manager', 'site', $site->id);

        $this->actingAs($siteManager)
            ->getJson(route('document-intelligence.documents', ['q' => 'Hilti']))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('documents.0.id', $allowed->id);

        $this->get(route('document-intelligence.download', $allowed))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('submittal.pdf');
        $this->get(route('document-intelligence.preview', $allowed))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        // 예전에는 확장자 컬럼만 보고 415 로 밀어냈다 — 지금은 파일명·MIME 까지 보고
        // 알아볼 수 있으면 열어 준다(임포트 문서는 확장자 칸이 비어 있었다).
        $this->get(route('document-intelligence.preview', $officeDocument))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->get(route('document-intelligence.preview', $archive))->assertStatus(415);
        $this->get(route('document-intelligence.download', $blocked))->assertNotFound();
    }

    public function test_site_manager_cannot_upload_into_another_site_and_worker_cannot_open_hub(): void
    {
        [$company, $site] = $this->projectFixture();
        $otherSite = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'BLOCKED-AZ',
            'name' => 'Blocked Site',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $otherProject = Project::query()->create([
            'company_id' => $company->id,
            'site_id' => $otherSite->id,
            'project_code' => 'BLOCKED-AZ-2026-001',
            'name' => 'Blocked Project',
            'construction_type' => 'equipment_setting',
            'project_stage' => 'awarded',
        ]);

        $this->actingAs($this->user('site_manager', 'site', $site->id))
            ->post(route('document-intelligence.upload'), [
                'project_id' => $otherProject->id,
                'files' => [UploadedFile::fake()->createWithContent('notice.txt', 'test')],
            ])
            ->assertSessionHasErrors('project_id');

        $this->actingAs($this->user('worker', 'self'))
            ->get(route('document-intelligence.index'))
            ->assertForbidden();
    }

    /**
     * 문은 하나 — 주소로 직접 오면(알림 링크) ERP 본체의 문서함으로 돌려보낸다.
     * 독립 화면이 따로 뜨면 왼쪽 메뉴가 두 벌이 된다. embed=1(ERP 안 iframe)만 그대로.
     */
    public function test_direct_visit_redirects_into_the_erp_shell(): void
    {
        $this->projectFixture();
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->get(route('document-intelligence.index', ['document' => 80]))
            ->assertRedirect('/?view=document-hub&document=80');

        $this->actingAs($admin)
            ->get(route('document-intelligence.index', ['embed' => 1]))
            ->assertOk();
    }

    public function test_unified_alert_status_updates_the_source_action_and_is_scope_filtered(): void
    {
        [$company, $site, $project] = $this->projectFixture();
        $admin = $this->user('admin');
        $document = $this->document($admin, $company, $site, $project, 'risk.txt', 'risk');
        $action = $document->actionItems()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_id' => $project->id,
            'action_type' => 'risk',
            'severity' => 'high',
            'status' => 'open',
            'title' => 'Confirm exclusion before mobilization',
            'due_at' => now()->addDays(2),
        ]);
        $service = app(UnifiedAlertService::class);
        $alert = $service->emit('document-action:'.$action->id, [
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_id' => $project->id,
            'source_module' => 'DOC',
            'source_type' => DocumentActionItem::class,
            'source_id' => (string) $action->id,
            'event_type' => 'risk',
            'severity' => 'critical',
            'title' => $action->title,
        ]);

        $result = $service->updateStatus($admin, $alert->alert_code, '완료');
        $this->assertTrue($result['success']);
        $this->assertSame('completed', $alert->fresh()->status);
        $this->assertSame('completed', $action->fresh()->status);
        $this->assertNotNull($action->fresh()->completed_at);

        $otherSite = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'SCOPE-OTHER',
            'name' => 'Scope Other',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $siteManager = $this->user('site_manager', 'site', $otherSite->id);
        $this->assertSame([], $service->forUser($siteManager));
    }

    /** @return array{Company, Site, Project} */
    private function projectFixture(): array
    {
        $company = Company::query()->create(['code' => 'XYZ', 'name' => 'XYZ MEP', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'LGES-AZ',
            'name' => 'LGES Arizona',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-001',
            'name' => 'LGES Arizona Module Installation',
            'construction_type' => 'equipment_setting',
            'project_stage' => 'awarded',
        ]);

        return [$company, $site, $project];
    }

    /** @param array<string, mixed> $overrides */
    private function document(
        User $user,
        Company $company,
        Site $site,
        Project $project,
        string $fileName,
        string $contents,
        array $overrides = [],
    ): IntelligentDocument {
        $uuid = (string) Str::uuid();
        $path = 'document-intelligence/inbox/'.$uuid.'/'.$fileName;
        Storage::disk('local')->put($path, $contents);

        return IntelligentDocument::query()->create([
            'uuid' => $uuid,
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_id' => $project->id,
            'uploaded_by' => $user->id,
            'disk' => 'local',
            'file_path' => $path,
            'original_file_name' => $fileName,
            'stored_file_name' => $fileName,
            'mime_type' => str_ends_with($fileName, '.pdf') ? 'application/pdf' : 'text/plain',
            'extension' => pathinfo($fileName, PATHINFO_EXTENSION),
            'file_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'title' => pathinfo($fileName, PATHINFO_FILENAME),
            'received_at' => now(),
            'ai_status' => 'queued',
            ...$overrides,
        ]);
    }

    private function user(string $role, string $scope = 'all_sites', ?int $siteId = null): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => $role,
            'access_scope' => $scope,
            'allowed_site_id' => $siteId,
            'account_status' => 'active',
        ]);
    }
}
