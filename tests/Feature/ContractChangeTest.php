<?php

namespace Tests\Feature;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\ContractChange;
use App\Models\IntelligentDocument;
use App\Models\ProjectContract;
use App\Models\User;
use App\Models\WorkSection;
use App\Services\Drawings\SectionDrawingService;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Finance\ContractChangeService;
use App\Services\Finance\ContractSheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RFI — 사장 지시(2026-09-25): «추가되면 RFI 제출해서 승인받고 뺄건 빼고.»
 *
 * 잠그는 것:
 *  - 추가 RFI 의 줄은 승인 전에는 청구되지 않는다(기록은 모을 수 있다). 승인하면 확정 줄이 되고 계약 금액이 는다.
 *  - 감액 RFI 는 승인 때 기존 줄의 수량을 줄이고, 원래 수량을 남긴다. 확인된 수량보다 적게는 못 줄인다.
 *  - 승인에는 원청 승인 문서가 있어야 한다. 반려·취소하면 기록 없는 줄은 사라진다.
 *  - 감액된 계약서를 다시 올려도 RFI 가 바꾼 수량을 되돌리지 않는다.
 */
class ContractChangeTest extends TestCase
{
    use ContractWorkbookFixture { setUp as fixtureSetUp; }
    use RefreshDatabase;

    private ProjectContract $prime;

    private IntelligentDocument $sheetDoc;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        $this->prime = ProjectContract::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'title' => 'Prime',
            'direction' => 'receivable', 'original_amount' => 3000, 'currency' => 'USD']);
        $this->sheetDoc = $this->workbook();
        $r = app(ContractSheetImportService::class)->import(['documentId' => $this->sheetDoc->id, 'contractId' => $this->prime->id, 'confirmContract' => true]);
        $this->assertTrue($r['success'], $r['error'] ?? '');
    }

    private function doc(string $name = 'rfi-approval.pdf'): IntelligentDocument
    {
        $bytes = '%PDF '.$name.Str::random(8);
        Storage::disk('local')->put('docs/'.$name, $bytes);

        return IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'source' => 'dropzone', 'disk' => 'local', 'file_path' => 'docs/'.$name,
            'original_file_name' => $name, 'stored_file_name' => $name, 'extension' => 'pdf', 'mime_type' => 'application/pdf',
            'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'title' => $name, 'received_at' => now(), 'ai_status' => 'ready',
            'site_id' => $this->site->id, 'company_id' => $this->company->id, 'access_level' => 'shared']);
    }

    private function drywall(): WorkSection
    {
        return WorkSection::where('site_id', $this->site->id)->where('name', '3) DRYWALL')->firstOrFail();
    }

    private function rfi(): ContractChangeService
    {
        return app(ContractChangeService::class);
    }

    public function test_an_added_rfi_is_not_billable_until_the_gc_approves_it(): void
    {
        $r = $this->rfi()->submit(['sectionId' => $this->drywall()->id, 'kind' => 'add', 'rfiNo' => '003', 'title' => 'Extra wall at pantry',
            'submittedOn' => '2026-09-10', 'items' => [
                ['description' => 'Dry Wall extra', 'spec' => '6in', 'unit' => 'LF2', 'qty' => 50, 'materialPrice' => 2, 'laborPrice' => 6],
                ['description' => 'Corner guard', 'unit' => 'EA', 'qty' => 4, 'laborPrice' => 25],
            ]]);
        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertEquals(500, $r['amount']);

        $lines = ContractBoqLine::where('line_no', 'like', 'RFI-003-%')->orderBy('id')->get();
        $this->assertSame(['RFI-003-1', 'RFI-003-2'], $lines->pluck('line_no')->all());
        $this->assertTrue($lines->every(fn ($l) => $l->status === 'draft' && $l->work_section_id === $this->drywall()->id));
        $this->assertEquals(['stored' => 25, 'installation' => 75], $lines[0]->stage_weights, '계약서 줄과 같은 반입·설치 규칙');
        $this->assertEquals(3000, $this->prime->fresh()->current_amount, '승인 전에는 계약이 그대로');

        $board = app(SectionDrawingService::class)->board((string) $this->site->id);
        $card = collect($board['sections'])->firstWhere('name', '3) DRYWALL');
        $this->assertEquals(500, $card['billing']['rfiPendingAmount']);
        $this->assertEquals(1600, $card['billing']['amount'], '승인 전 RFI 줄은 계약 금액에 없다');
        $this->assertSame('submitted', $card['changes'][0]['status']);

        // 승인 전에도 현장 기록은 모을 수 있지만, 확인(청구)은 안 된다.
        $ledger = app(ClaimEvidenceService::class);
        $rec = $ledger->saveRecord(['lineId' => $lines[1]->id, 'recordKind' => 'actual', 'workDate' => '2026-09-11', 'location' => 'Pantry', 'stage' => 'installed',
            'reportedQty' => 4, 'sourceRef' => 'rfi-rec', 'evidence' => [['type' => 'document', 'id' => $this->sheetDoc->id, 'locator' => 'photo']]]);
        $this->assertTrue($rec['success'], $rec['error'] ?? '');
        $this->assertFalse($ledger->reviewRecord(['id' => $rec['id'], 'action' => 'verify', 'verifiedQty' => 4, 'reviewNote' => 'x'])['success']);

        $change = ContractChange::firstOrFail();
        $noDoc = $this->rfi()->decide(['id' => $change->id, 'action' => 'approve']);
        $this->assertFalse($noDoc['success']);
        $this->assertStringContainsString('승인 문서', $noDoc['error']);

        $ok = $this->rfi()->decide(['id' => $change->id, 'action' => 'approve', 'approvalDocumentId' => $this->doc()->id, 'decidedOn' => '2026-09-15']);
        $this->assertTrue($ok['success'], $ok['error'] ?? '');
        $this->assertTrue(ContractBoqLine::where('line_no', 'like', 'RFI-003-%')->get()->every(fn ($l) => $l->status === 'accepted'));
        $contract = $this->prime->fresh();
        $this->assertEquals(500, $contract->approved_change_amount);
        $this->assertEquals(3500, $contract->current_amount);
        $this->assertTrue($ledger->reviewRecord(['id' => $rec['id'], 'action' => 'verify', 'verifiedQty' => 4, 'reviewNote' => 'approved RFI'])['success'], '승인 뒤에는 모아 둔 기록을 청구할 수 있다');
    }

    public function test_a_deduction_lowers_the_line_on_approval_and_never_below_verified_work(): void
    {
        $paint = ContractBoqLine::where('line_no', '4')->firstOrFail();   // 200 LF2 × 3
        $r = $this->rfi()->submit(['sectionId' => $this->drywall()->id, 'kind' => 'deduct', 'rfiNo' => '004', 'title' => 'Paint scope reduced',
            'items' => [['lineId' => $paint->id, 'qty' => 50]]]);
        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertEquals(-150, $r['amount']);
        $this->assertSame('200.0000', $paint->fresh()->contract_qty, '승인 전에는 그대로');

        $ledger = app(ClaimEvidenceService::class);
        $rec = $ledger->saveRecord(['lineId' => $paint->id, 'recordKind' => 'actual', 'workDate' => '2026-09-02', 'location' => 'Kitchen', 'stage' => 'installed',
            'reportedQty' => 180, 'sourceRef' => 'paint', 'evidence' => [['type' => 'document', 'id' => $this->sheetDoc->id, 'locator' => 'photo']]]);
        $this->assertTrue($ledger->reviewRecord(['id' => $rec['id'], 'action' => 'verify', 'verifiedQty' => 180, 'reviewNote' => 'done'])['success']);

        $change = ContractChange::firstOrFail();
        $blocked = $this->rfi()->decide(['id' => $change->id, 'action' => 'approve', 'approvalDocumentId' => $this->doc()->id]);
        $this->assertFalse($blocked['success'], '확인된 180 보다 적게(150) 줄일 수 없다');
        $this->assertSame('200.0000', $paint->fresh()->contract_qty);

        $this->assertTrue($ledger->reviewRecord(['id' => $rec['id'], 'action' => 'reopen', 'reviewNote' => 'recount'])['success']);
        $this->assertTrue($ledger->reviewRecord(['id' => $rec['id'], 'action' => 'verify', 'verifiedQty' => 140, 'reviewNote' => 'recount'])['success']);
        $ok = $this->rfi()->decide(['id' => $change->id, 'action' => 'approve', 'approvalDocumentId' => $this->doc('deduct.pdf')->id]);
        $this->assertTrue($ok['success'], $ok['error'] ?? '');
        $this->assertSame('150.0000', $paint->fresh()->contract_qty);
        $this->assertSame('200.0000', $change->items()->first()->qty_before, '원래 계약 수량은 남는다');
        $this->assertEquals(2850, $this->prime->fresh()->current_amount);

        $again = app(ContractSheetImportService::class)->import(['documentId' => $this->sheetDoc->id, 'contractId' => $this->prime->id, 'confirmContract' => true]);
        $this->assertTrue($again['success'], $again['error'] ?? '');
        $this->assertSame('150.0000', $paint->fresh()->contract_qty, '계약서를 다시 올려도 RFI 가 바꾼 수량은 그대로');
    }

    public function test_rejecting_or_withdrawing_removes_unused_rfi_lines(): void
    {
        $submit = fn (string $no) => $this->rfi()->submit(['sectionId' => $this->drywall()->id, 'kind' => 'add', 'rfiNo' => $no, 'title' => 'Maybe',
            'items' => [['description' => 'Extra', 'unit' => 'EA', 'qty' => 1, 'laborPrice' => 100]]]);
        $this->assertTrue($submit('010')['success']);
        $this->assertTrue($submit('011')['success']);
        $this->assertFalse($submit('010')['success'], '같은 RFI 번호는 두 번 못 낸다');

        $rejected = ContractChange::where('rfi_no', '010')->firstOrFail();
        $this->assertTrue($this->rfi()->decide(['id' => $rejected->id, 'action' => 'reject', 'note' => 'GC declined'])['success']);
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertFalse(ContractBoqLine::where('line_no', 'RFI-010-1')->exists());

        $withdrawn = ContractChange::where('rfi_no', '011')->firstOrFail();
        $this->assertTrue($this->rfi()->decide(['id' => $withdrawn->id, 'action' => 'withdraw'])['success']);
        $this->assertNull($withdrawn->fresh());
        $this->assertEquals(3000, $this->prime->fresh()->current_amount);
        $this->assertSame(0, ClaimWorkRecord::count());
    }

    public function test_only_billing_managers_can_submit_rfis(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'safety_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $r = $this->rfi()->submit(['sectionId' => $this->drywall()->id, 'kind' => 'add', 'rfiNo' => '020', 'title' => 'x',
            'items' => [['description' => 'x', 'unit' => 'EA', 'qty' => 1, 'laborPrice' => 1]]]);
        $this->assertFalse($r['success']);
        $this->assertSame(0, ContractChange::count());
    }
}
