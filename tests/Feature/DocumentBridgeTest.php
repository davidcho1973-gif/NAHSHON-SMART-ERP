<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 문서관리(a) → AI 문서함(b) 자동 인덱싱.
 *
 * 두 문서함은 서로를 모르는 평행 시스템이라 같은 발주서를 두 화면에 두 번
 * 올려야 했다 — 문서관리에만 올리면 AI 액션 큐(기한·위험)가 못 보고,
 * AI 문서함에만 올리면 조달·계약 화면의 첨부가 비었다. 이제 문서관리에
 * 들어온 문서는 자동으로 AI 문서함에도 등록된다.
 */
class DocumentBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        Site::create(['code' => 'AZ-01', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
    }

    private function admin(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
    }

    private function fileDocument(array $overrides = []): IntegratedDocument
    {
        $disk = IntegratedDocument::storageDisk();
        $path = 'integrated-documents/'.uniqid().'.pdf';
        Storage::disk($disk)->put($path, $overrides['__bytes'] ?? '%PDF-1.4 test purchase order');
        unset($overrides['__bytes']);

        return IntegratedDocument::create(array_merge([
            'title' => 'PO-2026-001 발주서',
            'original_name' => 'PO-2026-001.pdf',
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 100,
            'folder_code' => '06',
            'document_type' => 'purchase_order',
            'status' => 'completed',
        ], $overrides));
    }

    public function test_문서관리_등록이_a_i_문서함에도_자동_인덱싱된다(): void
    {
        $doc = $this->fileDocument();

        $indexed = IntelligentDocument::firstOrFail();
        $this->assertSame('integrated', $indexed->source);
        $this->assertSame((string) $doc->id, $indexed->external_id);
        $this->assertSame('PO-2026-001 발주서', $indexed->title);
        Storage::disk(config('document-intelligence.disk', 'local'))->assertExists($indexed->file_path);
        // 기한·위험을 뽑는 AI 분석이 자동으로 예약된다 — 두 번째 업로드가 필요 없는 이유.
        Queue::assertPushed(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_같은_내용은_두_번_인덱싱되지_않는다(): void
    {
        $this->fileDocument(['__bytes' => 'same-bytes']);
        $this->fileDocument(['__bytes' => 'same-bytes', 'title' => '같은 파일 재등록']);

        $this->assertSame(1, IntelligentDocument::count(), 'sha256 이 같으면 하나만 남는다');
    }

    public function test_a_i_문서함이_다루지_않는_형식은_건너뛴다(): void
    {
        $this->fileDocument(['original_name' => 'floorplan.dwg', 'mime_type' => 'application/acad']);

        $this->assertSame(0, IntelligentDocument::count());
    }

    public function test_파일_없는_메타_등록은_건너뛴다(): void
    {
        IntegratedDocument::create([
            'title' => '외부 링크 문서', 'status' => 'completed', 'folder_code' => '09',
        ]);

        $this->assertSame(0, IntelligentDocument::count());
    }

    public function test_문서함_업로드_한_번으로_양쪽에_모두_생긴다(): void
    {
        $this->actingAs($this->admin());

        $this->post('/docs-api/upload', [
            'file' => UploadedFile::fake()->create('계약서_회신기한.pdf', 120, 'application/pdf'),
        ])->assertSuccessful();

        $this->assertSame(1, IntegratedDocument::count());
        $this->assertSame(1, IntelligentDocument::count(), '한 번 올리면 문서관리와 AI 문서함 양쪽에 모두 등록된다');
    }
}
