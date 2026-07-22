<?php

namespace Tests\Feature;

use App\Models\IntegratedDocument;
use App\Services\IntegratedDocumentService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 문서통합관리 — AI 분석 위임 + 9폴더 자동분류 + 중복감지 + 대시보드/검색/확정.
 */
class IntegratedDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEngine(array $data): void
    {
        $this->app->instance(OcrEngine::class, new class($data) implements OcrEngine {
            public function __construct(private array $data) {}

            public function analyze(array $images, string $prompt, array $schema): array
            {
                return ['data' => $this->data, 'model' => 'gemini-test'];
            }

            public function name(): string
            {
                return 'gemini';
            }
        });
    }

    private function makeDoc(string $title = '무제'): IntegratedDocument
    {
        Storage::fake('public');
        $path = 'integrated-documents/sample.png';
        Storage::disk('public')->put($path, "\x89PNG fake bytes");

        return IntegratedDocument::create([
            'title' => $title,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'sample.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'status' => 'analyzing',
        ]);
    }

    public function test_folder_classification_maps_known_types(): void
    {
        $this->assertSame('08', IntegratedDocument::classifyFolder(['document_type' => 'certificate_of_insurance'])['code']);
        $this->assertSame('05', IntegratedDocument::classifyFolder(['document_type' => 'safety_plan'])['code']);
        $this->assertSame('07', IntegratedDocument::classifyFolder(['document_type' => 'visa'])['code']);
        $this->assertSame('01', IntegratedDocument::classifyFolder(['document_type' => 'executed_contract'])['code']);
    }

    public function test_folder_classification_uses_keyword_heuristics_for_other(): void
    {
        $receipt = IntegratedDocument::classifyFolder([
            'document_type' => 'other', 'title' => 'LED조명 구매 영수증', 'summary' => ['자재 납품'], 'fields' => [],
        ]);
        $this->assertSame('06', $receipt['code']);

        $test = IntegratedDocument::classifyFolder([
            'document_type' => 'other', 'title' => '접지저항 시험성적서', 'summary' => ['측정값 0.8Ω 합격'], 'fields' => [],
        ]);
        $this->assertSame('04', $test['code']);
    }

    public function test_analyze_and_classify_persists_ai_result(): void
    {
        $this->fakeEngine([
            'document_type' => 'certificate_of_insurance', 'title' => 'COI - Acme', 'document_number' => 'GL-1',
            'issued_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'issuer' => 'Acme', 'counterparty' => 'NAHSHON',
            'amount' => 2000000, 'currency' => 'USD', 'summary' => '일반배상책임 200만불 보장.',
            'fields' => [['label' => '보장한도', 'value' => '$2,000,000']],
        ]);
        $doc = $this->makeDoc();

        $out = app(IntegratedDocumentService::class)->analyzeAndClassify($doc);

        $this->assertSame('needs_review', $out->status);
        $this->assertSame('08', $out->folder_code);             // COI → 재무·정산
        $this->assertSame('certificate_of_insurance', $out->document_type);
        $this->assertSame('2026-12-31', $out->expires_on->toDateString());
        $this->assertSame('gemini', $out->engine);
        $this->assertNotEmpty($out->summary);
        $this->assertNotEmpty($out->tags);
    }

    public function test_duplicate_detection_flags_same_document_number(): void
    {
        $this->fakeEngine([
            'document_type' => 'pay_application', 'title' => '기성청구 3회차', 'document_number' => 'PAY-003',
            'amount' => 50000, 'currency' => 'USD', 'summary' => '3회차 기성', 'fields' => [],
        ]);
        $first = app(IntegratedDocumentService::class)->analyzeAndClassify($this->makeDoc('첫 업로드'));
        $second = app(IntegratedDocumentService::class)->analyzeAndClassify($this->makeDoc('중복 업로드'));

        $this->assertNull($first->duplicate_of_id);
        $this->assertSame($first->id, $second->duplicate_of_id);
        $this->assertNotNull($second->duplicate_note);
    }

    public function test_register_without_ai_files_cad_drawing_to_construction_folder(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('integrated-documents/plan.dwg', 'DWG binary');
        $doc = IntegratedDocument::create([
            'title' => '3F 전기배관 도면', 'disk' => 'public', 'path' => 'integrated-documents/plan.dwg',
            'original_name' => 'plan.dwg', 'mime_type' => 'application/acad', 'size' => 2048, 'status' => 'analyzing',
        ]);

        $out = app(IntegratedDocumentService::class)->registerWithoutAi($doc, 'dwg');

        $this->assertSame('needs_review', $out->status);
        $this->assertSame('drawing', $out->document_type);
        $this->assertSame('03', $out->folder_code);     // 도면 → 시공·공정
        $this->assertNull($out->engine);                 // AI 미분석
        $this->assertNotEmpty($out->summary);
        $this->assertContains('DWG', $out->tags);
    }

    public function test_dashboard_and_search_and_confirm(): void
    {
        $this->fakeEngine([
            'document_type' => 'bond', 'title' => 'Performance Bond', 'document_number' => 'B-1',
            'issuer' => 'Surety Co', 'amount' => 100000, 'currency' => 'USD', 'summary' => '이행보증', 'fields' => [],
        ]);
        $doc = app(IntegratedDocumentService::class)->analyzeAndClassify($this->makeDoc());

        $svc = app(IntegratedDocumentService::class);
        $dash = $svc->dashboard(null);
        $this->assertSame(1, $dash['stats'][0]['value']);       // 전체 1건
        $this->assertCount(9, $dash['dist']);                   // 9개 폴더 분포

        $search = $svc->search(null, 'Surety');
        $this->assertSame(1, $search['count']);

        $confirm = $svc->confirm($doc->id, '01');
        $this->assertTrue($confirm['success']);
        $this->assertSame('01', $confirm['folder_code']);       // 사람이 폴더 변경
        $this->assertSame('confirmed', $doc->fresh()->status);
    }
}
