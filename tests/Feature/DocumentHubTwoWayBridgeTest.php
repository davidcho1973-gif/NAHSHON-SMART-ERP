<?php

namespace Tests\Feature;

use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Services\Documents\IntelligentToIntegratedBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 어디에 올리든 두 문서함에 다 있는가.
 *
 * 문서함이 둘이었고, 다리는 한쪽 방향뿐이었다. 문서관리에 올린 것은 AI 문서함으로
 * 갔지만 반대는 아니어서, 쓰는 사람은 파일을 올릴 때마다 "어디에 올려야 하지" 를
 * 판단해야 했다. 틀리면 한쪽 화면에서 그 문서가 아예 안 보이는데, 없는 것인지 다른
 * 데 있는 것인지 구별할 방법이 없다.
 *
 * 여기서 지키는 것은 하나다 — <b>그 판단이 필요 없어야 한다.</b>
 */
class DocumentHubTwoWayBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $hubDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hubDisk = (string) config('document-intelligence.disk', 'local');
        Storage::fake($this->hubDisk);
        Storage::fake(IntegratedDocument::storageDisk());
    }

    /** AI 문서함에 파일이 하나 올라온 상태를 만든다. */
    private function uploadedToHub(array $attributes = []): IntelligentDocument
    {
        $path = 'document-intelligence/inbox/'.Str::uuid().'/spec.pdf';
        Storage::disk($this->hubDisk)->put($path, '%PDF-1.4 test contents');

        return IntelligentDocument::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'source' => 'dropzone',
            'disk' => $this->hubDisk,
            'file_path' => $path,
            'original_file_name' => '납품서 A.pdf',
            'stored_file_name' => 'spec.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 22,
            'sha256' => hash('sha256', 'spec'.Str::uuid()),
            'title' => '납품서 A',
            'received_at' => now(),
            'ai_status' => 'queued',
        ], $attributes));
    }

    // ── 되돌아오는가 ───────────────────────────────────────────────────

    public function test_a_file_uploaded_to_the_ai_hub_shows_up_in_document_management(): void
    {
        $doc = $this->uploadedToHub();

        $mirror = IntegratedDocument::query()->where('source_document_id', $doc->id)->first();

        $this->assertNotNull($mirror, 'AI 문서함에 올린 문서가 문서관리에 없습니다.');
        $this->assertSame('납품서 A.pdf', $mirror->original_name);
        $this->assertSame('납품서 A', $mirror->title);
    }

    public function test_the_copy_can_actually_be_opened(): void
    {
        // 목록에는 있는데 열리지 않는 것이 파일이 없는 것보다 나쁘다 — 사람이
        // 파일을 찾느라 시간을 쓰고 나서야 없다는 걸 알게 된다.
        $doc = $this->uploadedToHub();
        $mirror = IntegratedDocument::query()->where('source_document_id', $doc->id)->firstOrFail();

        $this->assertTrue(
            Storage::disk($mirror->disk)->exists($mirror->path),
            '문서관리 쪽 사본이 디스크에 없습니다.'
        );
    }

    public function test_the_site_and_project_come_along(): void
    {
        $site = Site::create([
            'code' => 'S1', 'name' => 'Site One',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'project_code' => 'PRJ-1', 'name' => '프로젝트 하나', 'site_id' => $site->id,
            'construction_type' => 'mechanical',
        ]);

        $doc = $this->uploadedToHub(['site_id' => $site->id, 'project_id' => $project->id]);
        $mirror = IntegratedDocument::query()->where('source_document_id', $doc->id)->firstOrFail();

        $this->assertSame($site->id, $mirror->site_id);
        $this->assertSame('PRJ-1', $mirror->project_code);
    }

    // ── 고리가 생기지 않는가 ───────────────────────────────────────────

    public function test_a_document_that_came_from_document_management_is_not_sent_back(): void
    {
        // 되돌리면 왔던 자리로 다시 가는 것이다. 그 고리가 한 번만 돌아도
        // 같은 문서가 목록에 계속 늘어난다.
        $doc = $this->uploadedToHub(['source' => 'integrated', 'external_id' => '999']);

        $this->assertSame(
            0,
            IntegratedDocument::query()->where('source_document_id', $doc->id)->count(),
            '문서관리에서 온 문서를 다시 문서관리로 보냈습니다.'
        );
    }

    public function test_running_the_bridge_twice_does_not_duplicate_the_row(): void
    {
        $doc = $this->uploadedToHub();

        app(IntelligentToIntegratedBridge::class)->file($doc);
        app(IntelligentToIntegratedBridge::class)->file($doc);

        $this->assertSame(1, IntegratedDocument::query()->where('source_document_id', $doc->id)->count());
    }

    // ── 분석 결과가 내려오는가 ─────────────────────────────────────────

    public function test_the_analysis_result_reaches_the_document_management_row(): void
    {
        // 안 내려오면 제목도 종류도 없는 줄이 남는다. 파일은 있는데 무엇인지 알 수
        // 없어서, 결국 사람이 열어 보고 손으로 채우게 된다.
        $doc = $this->uploadedToHub();

        $doc->forceFill([
            'document_type' => '납품서',
            'document_number' => 'DN-2026-001',
            'sender' => 'ACME 공급',
            'expires_on' => '2026-12-31',
            'ai_engine' => 'gemini',
            'ai_status' => 'ready',
            'analyzed_at' => now(),
        ])->save();

        $mirror = IntegratedDocument::query()->where('source_document_id', $doc->id)->firstOrFail();

        $this->assertSame('납품서', $mirror->document_type);
        $this->assertSame('DN-2026-001', $mirror->document_number);
        $this->assertSame('ACME 공급', $mirror->issuer);
        $this->assertSame('gemini', $mirror->engine);
        $this->assertNotNull($mirror->expires_on, '만료일이 안 내려왔습니다 — 만료 알림이 못 잡습니다.');
    }

    public function test_a_low_confidence_result_still_comes_down(): void
    {
        // review_required 도 "읽기는 끝났다" 는 뜻이다. 이것까지 기다리면
        // 확신이 낮은 문서는 영영 빈 줄로 남는다.
        $doc = $this->uploadedToHub();
        $doc->forceFill(['document_type' => '견적서', 'ai_status' => 'review_required'])->save();

        $mirror = IntegratedDocument::query()->where('source_document_id', $doc->id)->firstOrFail();

        $this->assertSame('견적서', $mirror->document_type);
    }

    public function test_a_failed_analysis_does_not_overwrite_with_blanks(): void
    {
        $doc = $this->uploadedToHub();
        $doc->forceFill(['ai_status' => 'failed', 'ai_error' => '읽지 못했습니다'])->save();

        $mirror = IntegratedDocument::query()->where('source_document_id', $doc->id)->firstOrFail();

        $this->assertSame('납품서 A', $mirror->title, '실패한 분석이 제목을 지웠습니다.');
    }

    // ── 원본 등록을 막지 않는가 ────────────────────────────────────────

    public function test_a_broken_bridge_does_not_stop_the_upload(): void
    {
        // 되돌리기가 실패해도 AI 문서함 등록 자체는 살아 있어야 한다. 부가 기능이
        // 주 기능을 막으면, 그날 올린 문서가 통째로 사라진다.
        //
        // 파일이 처음부터 없는 상태로 만든다 — 만든 뒤에 지우면 이미 훅이 돌아
        // 사본이 생긴 뒤라서 실패 경로를 못 본다.
        $orphan = IntelligentDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'source' => 'dropzone',
            'disk' => $this->hubDisk,
            'file_path' => 'document-intelligence/inbox/없는/파일.pdf',
            'original_file_name' => '없는 파일.pdf',
            'stored_file_name' => '파일.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 0,
            'sha256' => hash('sha256', 'missing'),
            'title' => '없는 파일',
            'received_at' => now(),
            'ai_status' => 'queued',
        ]);

        $this->assertDatabaseHas('intelligent_documents', ['id' => $orphan->id]);
        $this->assertNull(app(IntelligentToIntegratedBridge::class)->file($orphan));
        $this->assertSame(0, IntegratedDocument::query()->where('source_document_id', $orphan->id)->count());
    }
}
