<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IntegratedDocument;
use App\Models\ProcurementItem;
use App\Models\ProjectContract;
use App\Models\ProjectContractDocument;
use App\Models\Site;
use App\Services\IntegratedDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 4) 흩어진 문서(조달·계약) 자동 편철 + 5) 본문 전문검색.
 */
class DocumentSiloFilingAndFullTextTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['filesystems.documents_disk' => 'public']);
        IntegratedDocument::forgetFolderMap();

        $this->company = Company::create(['code' => 'AUTORICA', 'name' => 'AUTORICA LLC', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'LG-PH', 'name' => 'LG Phoenix', 'status' => 'active']);
    }

    private function service(): IntegratedDocumentService
    {
        return app(IntegratedDocumentService::class);
    }

    // ── 4) 조달 자동 편철 ───────────────────────────────────

    public function test_procurement_attachment_is_filed_into_material_folder_and_linked(): void
    {
        Storage::disk('public')->put('procurement/po-118.pdf', 'PO BYTES');

        $item = ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'LG-01-W-A100',
            'status' => '발주', 'vendor' => 'Graybar', 'po_no' => 'PO-2026-118', 'amount' => 15200,
            'document_disk' => 'public', 'document_path' => 'procurement/po-118.pdf', 'document_name' => 'po-118.pdf',
        ]);

        $doc = IntegratedDocument::query()->whereJsonContains('fields->source', 'procurement')->first();
        $this->assertNotNull($doc, '발주 첨부는 문서함에 자동 편철돼야 한다.');
        $this->assertSame('06', $doc->folder_code);              // 자재·구매
        $this->assertSame($item->id, $doc->procurement_item_id); // 발주 건에 연결
        $this->assertSame('LG-01-W-A100', $doc->wbs_code);
        $this->assertStringContainsString('PO-2026-118', $doc->title);
        Storage::disk('public')->assertExists($doc->path);
    }

    public function test_filed_copy_is_independent_from_the_procurement_original(): void
    {
        Storage::disk('public')->put('procurement/po-1.pdf', 'PO BYTES');
        ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'W-1',
            'status' => '발주', 'vendor' => 'V', 'po_no' => 'PO-1',
            'document_disk' => 'public', 'document_path' => 'procurement/po-1.pdf', 'document_name' => 'po-1.pdf',
        ]);
        $doc = IntegratedDocument::query()->whereJsonContains('fields->source', 'procurement')->firstOrFail();

        $doc->delete();

        Storage::disk('public')->assertExists('procurement/po-1.pdf'); // 조달 원본은 살아 있어야 한다
    }

    public function test_procurement_filing_is_idempotent(): void
    {
        Storage::disk('public')->put('procurement/po-2.pdf', 'X');
        $item = ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'W-1',
            'status' => '발주', 'vendor' => 'V', 'po_no' => 'PO-2',
            'document_disk' => 'public', 'document_path' => 'procurement/po-2.pdf', 'document_name' => 'po-2.pdf',
        ]);
        $item->update(['status' => '입고']);                       // 다른 필드 수정 → 재편철 금지
        $this->service()->fileProcurementDocument($item->fresh()); // 직접 재호출도 멱등

        $this->assertSame(1, IntegratedDocument::query()->whereJsonContains('fields->source', 'procurement')->count());
    }

    public function test_procurement_without_attachment_is_not_filed(): void
    {
        ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'W-1',
            'status' => '발주', 'vendor' => 'V', 'po_no' => 'PO-3',
        ]);

        $this->assertSame(0, IntegratedDocument::count());
    }

    // ── 4) 계약 자동 편철 ───────────────────────────────────

    private function contractDoc(array $overrides = []): ProjectContractDocument
    {
        $contract = ProjectContract::create([
            'site_id' => $this->site->id, 'company_id' => $this->company->id,
            'counterparty_company_id' => $this->company->id,
            'contract_code' => 'CT-1', 'title' => '전기공사 계약', 'contract_role' => 'subcontractor',
        ]);
        Storage::disk('local')->put('contracts/ct-1.pdf', 'CONTRACT BYTES');

        return ProjectContractDocument::create(array_merge([
            'project_contract_id' => $contract->id,
            'document_type' => 'executed_contract', 'title' => '전기공사 계약서', 'document_number' => 'CT-1',
            'status' => 'approved', 'disk' => 'local', 'file_path' => 'contracts/ct-1.pdf',
            'original_file_name' => 'ct-1.pdf', 'mime_type' => 'application/pdf',
            'expires_on' => now()->addDays(30)->toDateString(),
        ], $overrides));
    }

    public function test_contract_document_is_filed_into_contract_folder_with_expiry(): void
    {
        $this->contractDoc();

        $doc = IntegratedDocument::query()->whereJsonContains('fields->source', 'contract')->first();
        $this->assertNotNull($doc);
        $this->assertSame('01', $doc->folder_code);   // 계약·행정
        $this->assertSame($this->company->id, $doc->company_id);
        // 만료일이 넘어와야 2단계(만료 알림)가 이 계약서를 감시한다.
        $this->assertSame(now()->addDays(30)->toDateString(), $doc->expires_on->toDateString());
    }

    public function test_confidential_contract_document_is_not_filed(): void
    {
        // 문서함에 폴더별 열람권한이 아직 없으므로 기밀 계약서는 편철하지 않는다.
        $this->contractDoc(['is_confidential' => true]);

        $this->assertSame(0, IntegratedDocument::query()->whereJsonContains('fields->source', 'contract')->count());
    }

    // ── 5) 본문 전문검색 ────────────────────────────────────

    private function docWithBody(string $title, ?string $body): IntegratedDocument
    {
        return IntegratedDocument::create([
            'site_id' => $this->site->id, 'folder_code' => '01', 'status' => 'confirmed',
            'title' => $title, 'body_text' => $body, 'type_confidence' => 90, 'folder_confidence' => 90,
            'disk' => 'public', 'path' => 'integrated-documents/' . uniqid() . '.pdf',
        ]);
    }

    public function test_search_finds_text_inside_the_document_body(): void
    {
        $this->docWithBody('제목에 없는 문서', '본 계약의 하자보수 보증기간은 준공일로부터 12개월로 한다.');
        $this->docWithBody('무관 문서', '전혀 다른 내용');

        $r = $this->service()->search(null, '하자보수');

        $this->assertSame(1, $r['count'], '제목에 없어도 본문으로 찾아야 한다.');
        $this->assertSame('제목에 없는 문서', $r['hits'][0]['title']);
    }

    public function test_search_returns_snippet_around_the_match(): void
    {
        $this->docWithBody('계약서', str_repeat('앞부분 ', 40) . '지체상금은 1일당 0.1% 로 한다.' . str_repeat(' 뒷부분', 40));

        $hit = $this->service()->search(null, '지체상금')['hits'][0];

        $this->assertNotNull($hit['snippet']);
        $this->assertStringContainsString('지체상금', $hit['snippet']);
        $this->assertStringContainsString('…', $hit['snippet'], '앞뒤가 잘렸음을 표시해야 한다.');
    }

    public function test_documents_without_body_still_search_by_title(): void
    {
        $this->docWithBody('스캔 도면 A100', null);

        $r = $this->service()->search(null, 'A100');

        $this->assertSame(1, $r['count']);
        $this->assertNull($r['hits'][0]['snippet']);
    }
}
