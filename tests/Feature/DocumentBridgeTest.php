<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\DocumentActionItem;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\UnifiedAlert;
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

    public function test_원본이_유실된_문서는_같은_파일_재업로드로_복원된다(): void
    {
        // Laravel Cloud 로컬 디스크는 배포마다 초기화된다. 원본이 사라진 레코드를
        // "중복"으로 거부하면 같은 파일을 다시 올릴 유일한 길이 막힌다.
        $this->actingAs($this->admin());
        $file = UploadedFile::fake()->create('08_배관_위생.pdf', 200, 'application/pdf');

        $this->post('/document-hub/api/upload', ['files' => [$file]])->assertSuccessful();
        $doc = IntelligentDocument::firstOrFail();

        // 배포가 지운 상황 재현: 원본 삭제 + 분석 실패 상태.
        Storage::disk($doc->disk)->delete($doc->file_path);
        $doc->update(['ai_status' => 'failed', 'ai_error' => '업로드된 원본 파일을 찾을 수 없습니다.']);

        $again = UploadedFile::fake()->create('08_배관_위생.pdf', 200, 'application/pdf');
        $res = $this->post('/document-hub/api/upload', ['files' => [$again]])->assertSuccessful();

        $doc->refresh();
        $this->assertSame(1, IntelligentDocument::count(), '새 레코드를 만들지 않고 기존 레코드에 복원한다');
        $this->assertTrue(Storage::disk($doc->disk)->exists($doc->file_path), '파일이 되살아나야 한다');
        $this->assertSame('queued', $doc->ai_status, '분석이 다시 예약된다');
        $this->assertNull($doc->ai_error);
        $this->assertSame([], $res->json('duplicates') ?: [], '유실 복원은 중복으로 세지 않는다');
    }

    public function test_원본이_살아있는_진짜_중복은_여전히_거부된다(): void
    {
        $this->actingAs($this->admin());

        $this->post('/document-hub/api/upload', [
            'files' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ])->assertSuccessful();
        $res = $this->post('/document-hub/api/upload', [
            'files' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ])->assertSuccessful();

        $this->assertSame(1, IntelligentDocument::count());
        $this->assertCount(1, $res->json('duplicates'));
    }

    public function test_저장소_쓰기_실패는_중복이_아니라_실패로_보고된다(): void
    {
        // s3 디스크는 config 에서 throw=false 라 쓰기 실패가 예외 없이 false 로 온다.
        // 그것을 "중복 N개 제외"로 뭉뚱그리면 사용자는 왜 안 올라가는지 영영 알 수 없다.
        config(['document-intelligence.disk' => 'broken']);
        config(['filesystems.disks.broken' => ['driver' => 'local', 'root' => '/proc/nope', 'throw' => false]]);
        $this->actingAs($this->admin());

        $res = $this->post('/document-hub/api/upload', [
            'files' => [UploadedFile::fake()->create('08_배관_위생.pdf', 200, 'application/pdf')],
        ])->assertSuccessful();

        // 설정 디스크가 죽어도 로컬로 받아 분석은 진행된다 — 업로드가 조용히 사라지지 않는다.
        $this->assertSame(1, IntelligentDocument::count());
        $this->assertSame('local', IntelligentDocument::firstOrFail()->disk);
        $this->assertSame([], $res->json('duplicates'));
    }

    public function test_분석_실패로_남은_문서를_다시_올리면_분석이_재개된다(): void
    {
        // 사용자가 같은 파일을 또 올리는 이유는 대개 "분석 실패"를 풀려는 것이다.
        // 원본이 멀쩡하다고 그냥 "중복"으로 돌려보내면 아무 일도 일어나지 않는다.
        $this->actingAs($this->admin());
        $make = fn () => UploadedFile::fake()->create('08_배관_위생.pdf', 200, 'application/pdf');

        $this->post('/document-hub/api/upload', ['files' => [$make()]])->assertSuccessful();
        $doc = IntelligentDocument::firstOrFail();
        $doc->update(['ai_status' => 'failed', 'ai_error' => '분석 실패']);

        $res = $this->post('/document-hub/api/upload', ['files' => [$make()]])->assertSuccessful();

        $doc->refresh();
        $this->assertSame('queued', $doc->ai_status, '재분석이 자동으로 예약된다');
        $this->assertNull($doc->ai_error);
        $this->assertTrue($res->json('duplicates.0.requeued'));
        $this->assertStringContainsString('다시 시작', $res->json('duplicates.0.reason'));
    }

    public function test_형식이_안_맞는_파일_하나가_나머지_업로드를_막지_않는다(): void
    {
        $this->actingAs($this->admin());

        $res = $this->post('/document-hub/api/upload', [
            'files' => [
                UploadedFile::fake()->create('floorplan.dwg', 100, 'application/acad'),
                UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
            ],
        ])->assertSuccessful();

        $this->assertSame(1, IntelligentDocument::count(), '멀쩡한 파일은 올라가야 한다');
        $this->assertCount(1, $res->json('failed'));
        $this->assertSame('floorplan.dwg', $res->json('failed.0.file'));
    }

    public function test_원본이_사라진_문서는_목록에서_표시되고_다운로드는_안내를_준다(): void
    {
        // 레코드는 남고 파일만 사라진 상태 — 사용자가 다운로드를 눌러 보고 나서야
        // 404 를 만나면 원인을 알 수 없다. 목록에서 미리 알려 주고, 눌렀을 때도
        // "다시 올리면 복원된다"고 말해 준다.
        $this->actingAs($this->admin());
        $this->post('/document-hub/api/upload', [
            'files' => [UploadedFile::fake()->create('06_전기.pdf', 300, 'application/pdf')],
        ])->assertSuccessful();

        $doc = IntelligentDocument::firstOrFail();
        $this->assertFalse($this->get('/document-hub/api/documents')->json('documents.0.fileMissing'));

        Storage::disk($doc->disk)->delete($doc->file_path);   // 배포가 지운 상황

        // 목록이 미리 알려 주므로 화면은 다운로드 버튼 대신 복원 안내를 띄운다.
        $this->assertTrue($this->get('/document-hub/api/documents')->json('documents.0.fileMissing'));
        // 그래도 직접 URL 로 들어오면 404 — 파일이 없는데 있는 척하지 않는다.
        $this->get("/document-hub/documents/{$doc->id}/download")->assertNotFound();
        $this->get("/document-hub/documents/{$doc->id}/preview")->assertNotFound();
    }

    public function test_문서_정보를_수정할_수_있다(): void
    {
        $this->actingAs($this->admin());
        $this->post('/document-hub/api/upload', [
            'files' => [UploadedFile::fake()->create('rfi.pdf', 100, 'application/pdf')],
        ])->assertSuccessful();
        $doc = IntelligentDocument::firstOrFail();

        $this->patchJson("/document-hub/api/documents/{$doc->id}/review", [
            'title' => 'RFI-023 케이블 트레이 간섭',
            'category' => 'drawing_spec',
            'document_type' => 'rfi',
            'document_number' => 'RFI-023',
            'revision' => 'A',
            'response_due_on' => '2026-09-01',
        ])->assertOk();

        $doc->refresh();
        $this->assertSame('RFI-023 케이블 트레이 간섭', $doc->title);
        $this->assertSame('RFI-023', $doc->document_number);
        $this->assertSame('ready', $doc->ai_status, '사람이 확정하면 검토 필요가 풀린다');
        $this->assertNotNull($doc->reviewed_at);
    }

    public function test_문서를_삭제하면_원본과_후속조치도_함께_지워진다(): void
    {
        $this->actingAs($this->admin());
        $this->post('/document-hub/api/upload', [
            'files' => [UploadedFile::fake()->create('버릴문서.pdf', 100, 'application/pdf')],
        ])->assertSuccessful();
        $doc = IntelligentDocument::firstOrFail();
        $path = $doc->file_path;

        $action = DocumentActionItem::create([
            'intelligent_document_id' => $doc->id,
            'action_type' => 'response_required', 'title' => '회신 필요', 'status' => 'open', 'severity' => 'warning',
        ]);
        UnifiedAlert::create([
            'alert_code' => 'DOC-TEST-1', 'fingerprint' => 'doc-action:'.$action->id,
            'source_module' => 'DOC', 'source_type' => DocumentActionItem::class,
            'source_id' => (string) $action->id, 'event_type' => 'document_action',
            'severity' => 'warning', 'status' => 'unresolved', 'title' => '회신 필요',
            'occurred_at' => now(), 'last_detected_at' => now(),
        ]);

        $this->deleteJson("/document-hub/api/documents/{$doc->id}")->assertOk();

        $this->assertSame(0, IntelligentDocument::count());
        Storage::disk($doc->disk)->assertMissing($path);
        $this->assertSame(0, DocumentActionItem::count(), '처리할 수 없는 조치가 미처리로 남으면 안 된다');
        $this->assertSame(0, UnifiedAlert::count());
    }

    public function test_권한_없는_사용자는_수정도_삭제도_못_한다(): void
    {
        $this->actingAs($this->admin());
        $this->post('/document-hub/api/upload', [
            'files' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ])->assertSuccessful();
        $doc = IntelligentDocument::firstOrFail();

        $this->actingAs(User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $this->patchJson("/document-hub/api/documents/{$doc->id}/review", [
            'title' => '몰래 수정', 'category' => 'general', 'document_type' => 'other',
        ])->assertForbidden();
        $this->deleteJson("/document-hub/api/documents/{$doc->id}")->assertForbidden();
        $this->assertSame(1, IntelligentDocument::count());
    }
}
