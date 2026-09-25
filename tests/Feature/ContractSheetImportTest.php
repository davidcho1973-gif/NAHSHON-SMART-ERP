<?php

namespace Tests\Feature;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkSection;
use App\Models\WorkSectionSheet;
use App\Services\Drawings\SectionDrawingService;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Finance\ContractSheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 원청 계약 기성표 → 기성 근거 대장 — 사장 지시(2026-09-25):
 * «계약서의 줄이 곧 일의 단위다. 기성관리가 공정관리다. 계약이 기준선이고 변경은 RFI 로만.»
 *
 * 잠그는 것:
 *  - 섹션은 계약서 요약표의 수식(=C21 · =K26)이 가리키는 대로 읽는다. 소계가 안 맞으면 올리지 않는다.
 *  - 줄은 확정 계약 조건으로 올라가고 자기 공정(work_sections)에 매달린다. 사람이 고른 도면은 그대로다.
 *  - 자재·노무 단가가 둘 다 있는 줄은 반입(자재 단가)·설치(나머지)로 나눠 받는다. 둘을 받으면 줄 전액이다.
 *  - 같은 계약서를 다시 올려도 줄이 늘지 않고, 수량·단가가 다른 줄은 덮지 않는다(RFI 로만 바뀐다).
 */
class ContractSheetImportTest extends TestCase
{
    use ContractWorkbookFixture;
    use RefreshDatabase;

    private function contract(float $amount = 3000): ProjectContract
    {
        return ProjectContract::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'title' => 'Prime',
            'direction' => 'receivable', 'original_amount' => $amount, 'currency' => 'USD']);
    }

    private function service(): ContractSheetImportService
    {
        return app(ContractSheetImportService::class);
    }

    public function test_preview_reads_sections_from_the_summary_formulas_and_matches_existing_sections(): void
    {
        $drywall = WorkSection::create(['site_id' => $this->site->id, 'division' => '건축공사', 'code' => 'A3', 'name' => '3) DRYWALL', 'contract_amount' => 1600, 'sort_order' => 1]);
        $contract = $this->contract();
        $p = $this->service()->preview($this->workbook()->id);

        $this->assertTrue($p['success'], $p['error'] ?? '');
        $this->assertSame('Test Project', $p['project']);
        $this->assertSame('Finish PKG-1', $p['scope']);
        $this->assertEquals(3000, $p['contractTotal']);
        $this->assertEquals(3105.5, $p['lineTotal']);
        $this->assertEquals(-105.5, $p['roundOff'], '계약서의 천 달러 절사는 계약액과 줄 합계의 차이로 보인다');
        $this->assertSame(3, $p['lineCount']);
        $this->assertSame(1, $p['storedLineCount'], '자재·노무가 둘 다 있는 줄만 반입 자재를 따로 받는다');
        $this->assertSame(['3) DRYWALL', 'M1-2. Duct Work'], array_column($p['sections'], 'name'));
        $this->assertSame($drywall->id, $p['sections'][0]['matches']['id']);
        $this->assertNull($p['sections'][1]['matches']);
        $this->assertSame($contract->id, $p['suggestedContractId'], '계약서 합계와 같은 금액의 계약을 권한다');
    }

    public function test_import_creates_accepted_lines_under_their_sections_and_keeps_drawing_picks(): void
    {
        $drywall = WorkSection::create(['site_id' => $this->site->id, 'division' => '건축공사', 'code' => 'A3', 'name' => '3) Drywall', 'sort_order' => 1]);
        WorkSectionSheet::create(['work_section_id' => $drywall->id, 'sheet_no' => '703K-A01-01', 'sort_order' => 1]);
        $contract = $this->contract();
        $doc = $this->workbook();

        $r = $this->service()->import(['documentId' => $doc->id, 'contractId' => $contract->id, 'confirmContract' => true]);
        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame(3, $r['created']);

        $lines = ContractBoqLine::orderBy('id')->get();
        $this->assertSame(['2', '4', '8'], $lines->pluck('line_no')->all(), '줄 번호는 계약서의 ITEM NO 다');
        $this->assertTrue($lines->every(fn ($l) => $l->status === 'accepted' && $l->source_document_id === $doc->id));

        $wall = $lines[0];
        $this->assertSame($drywall->id, $wall->work_section_id);
        $this->assertSame('6in stud', $wall->spec);
        $this->assertSame('milestone', $wall->recognition_basis);
        $this->assertEquals(['stored' => 25, 'installation' => 75], $wall->stage_weights);
        $this->assertSame('2.5000', $wall->material_price);
        $this->assertSame('Paint group', $lines[1]->group_label);
        $this->assertSame('quantity', $lines[1]->recognition_basis, '자재 단가가 0 이면 수량 기준');

        $duct = WorkSection::where('site_id', $this->site->id)->where('code', 'M1-2')->firstOrFail();
        $this->assertSame($duct->id, $lines[2]->work_section_id);
        $this->assertEquals(1505.5, $duct->contract_amount);
        $this->assertSame('3) Drywall', $drywall->fresh()->name, '사람이 고친 공정 이름은 두고');
        $this->assertEquals(1600, $drywall->fresh()->contract_amount, '금액은 계약서 소계로');
        $this->assertSame(1, $drywall->sheets()->count(), '고른 도면은 그대로');
        $this->assertSame($contract->id, $doc->fresh()->project_contract_id, '계약서 파일은 그 계약의 원문 근거가 된다');
        $this->assertSame($contract->id, $duct->project_contract_id);

        $again = $this->service()->import(['documentId' => $doc->id, 'contractId' => $contract->id, 'confirmContract' => true]);
        $this->assertTrue($again['success'], $again['error'] ?? '');
        $this->assertSame(0, $again['created']);
        $this->assertSame(3, ContractBoqLine::count(), '다시 올려도 줄이 늘지 않는다');
    }

    public function test_stored_and_installed_halves_add_up_to_the_full_line_value(): void
    {
        $contract = $this->contract();
        $doc = $this->workbook();
        $this->assertTrue($this->service()->import(['documentId' => $doc->id, 'contractId' => $contract->id, 'confirmContract' => true])['success']);
        $wall = ContractBoqLine::where('line_no', '2')->firstOrFail();
        $ledger = app(ClaimEvidenceService::class);

        foreach (['stored', 'installation'] as $stage) {
            $saved = $ledger->saveRecord(['lineId' => $wall->id, 'recordKind' => 'actual', 'workDate' => '2026-09-01', 'location' => 'Kitchen west wall',
                'stage' => $stage, 'reportedQty' => 10, 'sourceRef' => 'test:'.$stage,
                'evidence' => [['type' => 'document', 'id' => $doc->id, 'locator' => 'row 22']]]);
            $this->assertTrue($saved['success'], $saved['error'] ?? '');
            $review = $ledger->reviewRecord(['id' => $saved['id'], 'action' => 'verify', 'verifiedQty' => 10, 'reviewNote' => 'checked']);
            $this->assertTrue($review['success'], $review['error'] ?? '');
            $amounts[$stage] = $ledger->getLedger($contract->id)['summary']['verifiedAmount'];
        }

        $this->assertEquals(25.0, $amounts['stored'], '반입 10 = 자재 단가 2.5 × 10');
        $this->assertEquals(100.0, $amounts['installation'], '설치까지 하면 줄 단가 10 × 10');
        $this->assertSame(2, ClaimWorkRecord::where('status', 'verified')->count());
    }

    public function test_import_needs_confirmation_the_right_amount_and_never_overwrites_a_contract_line(): void
    {
        $doc = $this->workbook();
        $contract = $this->contract(2500);
        $this->assertFalse($this->service()->import(['documentId' => $doc->id, 'contractId' => $contract->id])['success'], '계약 내역인지 확인해야 올린다');
        $wrong = $this->service()->import(['documentId' => $doc->id, 'contractId' => $contract->id, 'confirmContract' => true]);
        $this->assertFalse($wrong['success']);
        $this->assertStringContainsString('다릅니다', $wrong['error']);

        $right = $this->contract();
        ContractBoqLine::create(['project_contract_id' => $right->id, 'line_no' => '4', 'description' => 'Paint', 'unit' => 'LF2',
            'contract_qty' => 150, 'unit_price' => 3, 'recognition_basis' => 'quantity', 'stage_weights' => ['installed' => 100], 'status' => 'draft']);
        $conflict = $this->service()->import(['documentId' => $doc->id, 'contractId' => $right->id, 'confirmContract' => true]);
        $this->assertFalse($conflict['success']);
        $this->assertStringContainsString('RFI', $conflict['error']);
        $this->assertSame(1, ContractBoqLine::count(), '하나라도 어긋나면 아무것도 올리지 않는다');
        $this->assertSame('150.0000', ContractBoqLine::first()->contract_qty);
        $this->assertNull($doc->fresh()->project_contract_id);
    }

    public function test_can_create_the_prime_contract_from_the_sheet(): void
    {
        $doc = $this->workbook();
        $r = $this->service()->import(['documentId' => $doc->id, 'contractId' => 0, 'createContract' => true, 'confirmContract' => true]);
        $this->assertTrue($r['success'], $r['error'] ?? '');

        $contract = ProjectContract::findOrFail($r['contractId']);
        $this->assertSame('receivable', $contract->direction);
        $this->assertEquals(3000, $contract->current_amount);
        $this->assertSame($this->site->id, $contract->site_id);
        $this->assertSame('Test Project · Finish PKG-1', $contract->title);
        $this->assertSame(3, ContractBoqLine::where('project_contract_id', $contract->id)->count());
    }

    public function test_section_screen_shows_progress_from_the_ledger_and_hides_money_from_non_billing_roles(): void
    {
        $contract = $this->contract();
        $doc = $this->workbook();
        $this->assertTrue($this->service()->import(['documentId' => $doc->id, 'contractId' => $contract->id, 'confirmContract' => true])['success']);
        $wall = ContractBoqLine::where('line_no', '2')->firstOrFail();
        $ledger = app(ClaimEvidenceService::class);
        // 반입 40 확인 · 설치 60 은 기록만(확인 전).
        $stored = $ledger->saveRecord(['lineId' => $wall->id, 'recordKind' => 'actual', 'workDate' => '2026-09-01', 'location' => 'Kitchen', 'stage' => 'stored',
            'reportedQty' => 40, 'sourceRef' => 't:s', 'evidence' => [['type' => 'document', 'id' => $doc->id, 'locator' => 'row 22']]]);
        $this->assertTrue($ledger->reviewRecord(['id' => $stored['id'], 'action' => 'verify', 'verifiedQty' => 40, 'reviewNote' => 'invoice'])['success']);
        $ledger->saveRecord(['lineId' => $wall->id, 'recordKind' => 'actual', 'workDate' => '2026-09-02', 'location' => 'Kitchen', 'stage' => 'installation',
            'reportedQty' => 60, 'sourceRef' => 't:i', 'evidence' => [['type' => 'document', 'id' => $doc->id, 'locator' => 'row 22']]]);

        $board = app(SectionDrawingService::class)->board((string) $this->site->id);
        $this->assertTrue($board['success']);
        $this->assertSame($contract->id, $board['billing']['contractId']);
        $this->assertEquals(3105.5, $board['billing']['lineTotal']);
        $this->assertEquals(100.0, $board['billing']['earned'], '반입 40 × 자재 2.5');
        $this->assertEquals(100.0, $board['billing']['storedOnSite'], '설치 전 반입 자재는 청구서에 따로 적는다');
        $this->assertSame(1, $board['billing']['pendingCount']);
        $drywall = collect($board['sections'])->firstWhere('name', '3) DRYWALL');
        $this->assertSame(2, $drywall['billing']['lineCount']);
        $this->assertCount(2, $drywall['billing']['lines'], '공정 카드가 줄을 바로 보여 준다');
        $this->assertEquals(round(100 / 1600 * 100, 1), $drywall['billing']['percent']);

        $lines = app(SectionDrawingService::class)->sectionLines($drywall['id']);
        $this->assertTrue($lines['success']);
        $row = collect($lines['lines'])->firstWhere('lineNo', '2');
        $this->assertEquals(40, $row['storedQty']);
        $this->assertEquals(0, $row['installedQty'], '확인 전 설치는 진행률에 넣지 않는다');
        $this->assertFalse($row['installGap']);

        $this->actingAs(User::factory()->create(['access_role' => 'safety_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $blind = app(SectionDrawingService::class)->board((string) $this->site->id);
        $this->assertNull($blind['billing'], '기성 권한이 없으면 금액을 보내지 않는다');
        $this->assertNull(collect($blind['sections'])->firstWhere('name', '3) DRYWALL')['billing']);
        $this->assertFalse(app(SectionDrawingService::class)->sectionLines($drywall['id'])['success']);
    }

    public function test_deploy_loads_the_703k_contract_into_its_sections_once(): void
    {
        config(['document-intelligence.disk' => 'local']);
        Queue::fake();
        $site = Site::create(['code' => '703K', 'name' => 'Savannah', 'country' => 'US', 'timezone' => 'America/New_York',
            'status' => 'active', 'company_id' => $this->company->id]);
        User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        (require database_path('migrations/2026_09_25_000101_seed_703k_work_sections.php'))->up();
        $migration = require database_path('migrations/2026_09_25_000105_load_703k_contract_lines.php');

        $migration->up();

        $contract = ProjectContract::where('site_id', $site->id)->where('direction', 'receivable')->sole();
        $this->assertEquals(1789000, $contract->current_amount);
        $this->assertEquals(5, $contract->retainage_percent);
        $lines = ContractBoqLine::where('project_contract_id', $contract->id)->get();
        $this->assertCount(350, $lines);
        $this->assertTrue($lines->every(fn ($l) => $l->status === 'accepted' && $l->work_section_id !== null));
        $this->assertSame(199, $lines->where('recognition_basis', 'milestone')->count());
        // 계약서처럼 줄 금액을 반올림 없이 더하면 계약서 합계와 같다(줄마다 센트로 반올림하면 2센트 차이가 난다).
        $this->assertEquals(1789820.36, round($lines->sum(fn ($l) => (float) $l->contract_qty * (float) $l->unit_price), 2));
        $drywall = WorkSection::where('site_id', $site->id)->where('code', 'A3')->firstOrFail();
        $this->assertSame(['15', '16', '17', '18', '19', '20'], $lines->where('work_section_id', $drywall->id)->pluck('line_no')->values()->all());
        $this->assertSame(3, $drywall->sheets()->count(), '미리 골라 둔 도면이 그대로다');
        $this->assertSame(24, WorkSection::where('site_id', $site->id)->count(), '계약서 공정이 이미 있는 공정에 붙는다');
        $doc = IntelligentDocument::findOrFail($lines->first()->source_document_id);
        $this->assertSame('contract', $doc->category);
        $this->assertSame($contract->id, $doc->project_contract_id);

        $migration->up();
        $this->assertSame(350, ContractBoqLine::count(), '다시 돌아도 줄이 늘지 않는다');
        $this->assertSame(1, ProjectContract::where('site_id', $site->id)->count());
    }

    public function test_a_subtotal_that_does_not_match_its_lines_stops_the_import(): void
    {
        $doc = $this->workbook(['K26' => '=SUM(K22:K22)']);
        $p = $this->service()->preview($doc->id);
        $this->assertFalse($p['success']);
        $this->assertStringContainsString('3) DRYWALL', $p['error']);
        $this->assertSame(0, ContractBoqLine::count());
    }
}
