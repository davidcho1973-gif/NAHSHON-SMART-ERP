<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Services\IntegratedDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 문서 ↔ 업무 연결 — "이 발주 건 서류 일체", "이 작업자 서류"를 답할 수 있어야 한다.
 */
class DocumentWorkLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'AUTORICA', 'name' => 'AUTORICA LLC', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'LG-PH', 'name' => 'LG Phoenix', 'status' => 'active']);
    }

    private function service(): IntegratedDocumentService
    {
        return app(IntegratedDocumentService::class);
    }

    private function doc(array $attrs = []): IntegratedDocument
    {
        return IntegratedDocument::create(array_merge([
            'site_id' => $this->site->id, 'folder_code' => '06', 'status' => 'needs_review',
            'title' => '문서', 'type_confidence' => 90, 'folder_confidence' => 90,
            'disk' => 'public', 'path' => 'integrated-documents/' . uniqid() . '.pdf',
        ], $attrs));
    }

    public function test_document_auto_links_to_procurement_by_po_number(): void
    {
        $po = ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'LG-01-W-A100',
            'status' => '발주', 'vendor' => 'Graybar', 'po_no' => 'PO-2026-118',
        ]);
        $doc = $this->doc(['document_number' => 'PO-2026-118', 'document_type' => 'purchase_order']);

        $this->service()->autoLink($doc);

        $doc->refresh();
        $this->assertSame($po->id, $doc->procurement_item_id);
        $this->assertSame('LG-01-W-A100', $doc->wbs_code, '발주에 걸린 공정도 함께 연결돼야 한다.');
    }

    public function test_document_auto_links_to_company_by_issuer(): void
    {
        $doc = $this->doc(['issuer' => 'AUTORICA LLC', 'document_type' => 'certificate_of_insurance']);

        $this->service()->autoLink($doc);

        $this->assertSame($this->company->id, $doc->refresh()->company_id);
    }

    public function test_personal_document_auto_links_to_employee_by_name(): void
    {
        $emp = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id, 'name' => 'Kim Chulsoo',
            'first_name' => 'Kim', 'last_name' => 'Chulsoo', 'email' => 'kim@x.com', 'employment_status' => 'active',
        ]);
        $doc = $this->doc(['title' => 'Visa Approval - Kim Chulsoo', 'document_type' => 'visa', 'folder_code' => '07']);

        $this->service()->autoLink($doc);

        $doc->refresh();
        $this->assertSame($emp->id, $doc->employee_id);
        $this->assertSame($this->company->id, $doc->company_id);
    }

    public function test_manual_link_wins_and_blocks_later_auto_link(): void
    {
        $po = ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'LG-01-W-A200',
            'status' => '발주', 'vendor' => 'Graybar', 'po_no' => 'PO-2026-118',
        ]);
        // 사람이 직접 연결(발주번호가 다른데도)
        $doc = $this->doc(['document_number' => 'PO-2026-118']);
        $this->service()->linkDocument($doc->id, ['company_id' => $this->company->id, 'procurement_item_id' => null]);

        $this->service()->autoLink($doc->refresh()); // 자동 연결이 덮어쓰면 안 됨

        $doc->refresh();
        $this->assertTrue($doc->link_locked);
        $this->assertNull($doc->procurement_item_id, '사람이 지정한 연결을 자동 추정이 덮어쓰면 안 된다.');
        $this->assertSame($this->company->id, $doc->company_id);
        $this->assertNotNull($po);
    }

    public function test_for_entity_returns_all_documents_of_that_target(): void
    {
        $po = ProcurementItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'wbs_code' => 'LG-01-W-A300',
            'status' => '발주', 'vendor' => 'Graybar', 'po_no' => 'PO-1',
        ]);
        $this->doc(['title' => '발주서', 'procurement_item_id' => $po->id]);
        $this->doc(['title' => '납품확인서', 'procurement_item_id' => $po->id]);
        $this->doc(['title' => '무관 문서']);

        $r = $this->service()->forEntity('procurement', $po->id);

        $this->assertTrue($r['success']);
        $this->assertSame(2, $r['count']);
        $this->assertSame('발주', $r['label']);
    }

    public function test_unknown_entity_type_is_rejected(): void
    {
        $this->assertFalse($this->service()->forEntity('nonsense', 1)['success']);
    }

    public function test_detail_exposes_links(): void
    {
        $doc = $this->doc(['company_id' => $this->company->id]);

        $detail = $this->service()->detail($doc->id);

        $this->assertSame('협력사', $detail['links'][0]['label']);
        $this->assertSame('AUTORICA LLC', $detail['links'][0]['name']);
    }
}
