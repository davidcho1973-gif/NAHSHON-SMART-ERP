<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\SubmittalEvent;
use App\Models\User;
use App\Services\Ocr\OcrEngine;
use App\Services\Takeoff\SpecSubmittalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpecSubmittalExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document-intelligence.disk' => 'local']);
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
    }

    public function test_repeated_or_duplicate_source_reuses_requirements_without_resetting_approval(): void
    {
        $project = $this->project();
        $document = $this->document($project, 'original specification');
        $item = $this->requirement();
        $other = array_replace($item, ['title' => 'Samples (1.4.B)']);
        $engine = $this->engine([$item, $item, $other], 1);
        $service = new SpecSubmittalService($engine);

        $first = $service->extract($document);
        $this->assertTrue($first['success']);
        $this->assertSame(2, $first['created'], 'Repeated obligations collapse; different requirements sharing a paragraph do not.');
        $approved = Submittal::where('title', $item['title'])->sole();
        $approved->update(['status' => '승인', 'approved_on' => '2026-09-01', 'notes' => 'Human approval retained']);
        SubmittalEvent::create(['submittal_id' => $approved->id, 'kind' => 'response_recorded', 'channel' => 'manual', 'subject' => '승인 · prior review']);

        $this->travel(31)->minutes();
        $again = $service->extract($document->fresh());
        $this->assertTrue($again['reused']);
        $this->assertSame(0, $again['created']);
        $duplicateUpload = $this->document($project, 'original specification', 'shared');
        $this->assertTrue($service->extract($duplicateUpload)['reused']);
        $this->assertSame(2, Submittal::count());
        $this->assertSame('승인', $approved->fresh()->status);
        $this->assertSame('Human approval retained', $approved->fresh()->notes);
        $this->assertSame(1, $approved->events()->count());
    }

    public function test_source_revision_and_project_are_not_deduplicated_into_another_jobs_approval(): void
    {
        $firstProject = $this->project();
        $secondProject = $this->project('SECOND');
        $engine = $this->engine([$this->requirement()], 3);
        $service = new SpecSubmittalService($engine);
        $first = $this->document($firstProject, 'version one');
        $this->assertSame(1, $service->extract($first)['created']);
        $approved = Submittal::sole();
        $approved->update(['status' => '승인', 'approved_on' => '2026-09-01']);

        $otherJob = $this->document($secondProject, 'version one');
        $revision = $this->document($firstProject, 'version two');
        $this->assertSame(1, $service->extract($otherJob)['created']);
        $this->assertSame(1, $service->extract($revision)['created']);
        $this->assertSame(3, Submittal::count());
        $this->assertSame(2, Submittal::where('status', '미착수')->count());
        $this->assertSame('승인', $approved->fresh()->status);
    }

    public function test_legacy_requirement_identity_preserves_manual_review_and_changed_bytes_require_new_revision(): void
    {
        $project = $this->project();
        $document = $this->document($project, 'legacy specification');
        $item = $this->requirement();
        $existing = Submittal::create([
            'company_id' => $project->company_id, 'site_id' => $project->site_id, 'project_id' => $project->id,
            'seq' => 1, 'source_document_id' => $document->id,
            'csi' => $item['csi'], 'category' => $item['category'], 'title' => $item['title'],
            'source_excerpt' => $item['source_excerpt'], 'status' => '조건부승인', 'approved_on' => '2026-09-01',
            'notes' => 'Do not replace this condition', 'gate' => true,
        ]);
        $service = new SpecSubmittalService($this->engine([$item], 1));
        $this->assertSame(0, $service->extract($document)['created']);
        $this->assertSame('조건부승인', $existing->fresh()->status);
        $this->assertSame('Do not replace this condition', $existing->fresh()->notes);
        $this->assertTrue($existing->fresh()->gate);

        Storage::disk('local')->put($document->file_path, 'silently replaced bytes');
        $document->refresh()->update(['sha256' => hash('sha256', 'silently replaced bytes')]);
        $result = $service->extract($document->fresh());
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('개정', $result['error']);
        $this->assertSame(1, Submittal::count());
    }

    private function engine(array $items, int $calls): OcrEngine
    {
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->times($calls)->andReturn(['data' => ['items' => $items]]);
        $engine->shouldReceive('name')->andReturn('fake');

        return $engine;
    }

    private function requirement(): array
    {
        return [
            'csi' => '08 7100', 'section' => 'Hardware', 'category' => 'Action 제출물',
            'title' => 'Product Data (1.4.A)', 'source_excerpt' => '1.4 Submit product data and samples.',
            'gate' => false, 'confidence' => 95, 'timing' => 'Before installation',
        ];
    }

    private function project(string $code = 'FIRST'): Project
    {
        $company = Company::firstOrCreate(['code' => 'SUBMIT'], ['name' => 'MEP', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => $code, 'name' => $code, 'status' => 'active']);

        return Project::create([
            'company_id' => $company->id, 'site_id' => $site->id, 'project_code' => $code.'-JOB',
            'name' => $code, 'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);
    }

    private function document(Project $project, string $bytes, string $accessLevel = 'scope'): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $path = 'submittal-specs/'.$uuid.'.pdf';
        Storage::disk('local')->put($path, $bytes);

        return IntelligentDocument::create([
            'uuid' => $uuid, 'company_id' => $project->company_id, 'site_id' => $project->site_id,
            'project_id' => $project->id, 'uploaded_by' => auth()->id(), 'access_level' => $accessLevel, 'disk' => 'local', 'file_path' => $path,
            'original_file_name' => 'spec.pdf', 'stored_file_name' => 'spec.pdf', 'title' => 'Specification',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'received_at' => now(), 'ai_status' => 'ready',
        ]);
    }
}
