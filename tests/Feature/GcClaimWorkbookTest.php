<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\ContractChange;
use App\Models\IntelligentDocument;
use App\Models\PayApplication;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkSection;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Finance\ContractChangeService;
use App\Services\Finance\GcClaimWorkbookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 원청 청구서 엑셀 — 사장 지시(2026-09-25) «원청 청구서 엑셀 내보내기도 만들어줘».
 * 실제 703K 원청 양식(database/seed-files)으로 1차·2차 기성을 만들어 본다.
 *
 * 잠그는 것:
 *  - 원청 양식 그대로, 줄마다 전회·금회 수량은 대장이 그 회차에 배정한 확인 수량이다.
 *  - 반입했지만 설치 안 한 자재는 따로 만든 칸(X·Y)에, 표지 OTHERS 줄은 «MATERIALS STORED».
 *  - 원청 양식의 틀린 소계 범위(7) MISCELLANEOUS 등)를 바로잡는다 — 2차부터 금액이 부풀지 않게.
 *  - 승인된 RFI 줄은 별도 시트로, 표지 V/O 줄이 그 합계를 가리킨다.
 *  - 설치가 반입보다 많으면 멈춘다(대장 금액과 양식 금액이 어긋나므로).
 */
class GcClaimWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private ProjectContract $contract;

    private IntelligentDocument $proof;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        Storage::fake('local');
        config(['document-intelligence.disk' => 'local']);
        Queue::fake();
        $company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $site = Site::create(['code' => '703K', 'name' => 'Savannah', 'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $company->id]);
        $this->actingAs(User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        (require database_path('migrations/2026_09_25_000101_seed_703k_work_sections.php'))->up();
        (require database_path('migrations/2026_09_25_000105_load_703k_contract_lines.php'))->up();
        $this->contract = ProjectContract::where('site_id', $site->id)->sole();

        Storage::disk('local')->put('proof/photo.jpg', 'photo-bytes');
        $this->proof = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'source' => 'dropzone', 'disk' => 'local', 'file_path' => 'proof/photo.jpg',
            'original_file_name' => 'photo.jpg', 'stored_file_name' => 'photo.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'file_size' => 11,
            'sha256' => hash('sha256', 'photo-bytes'), 'title' => 'photo', 'received_at' => now(), 'ai_status' => 'ready',
            'site_id' => $site->id, 'company_id' => $company->id, 'access_level' => 'shared']);
    }

    private function line(string $no): ContractBoqLine
    {
        return ContractBoqLine::where('project_contract_id', $this->contract->id)->where('line_no', $no)->firstOrFail();
    }

    private function work(ContractBoqLine $line, string $stage, float $qty, string $date): void
    {
        $ledger = app(ClaimEvidenceService::class);
        $r = $ledger->saveRecord(['lineId' => $line->id, 'recordKind' => 'actual', 'workDate' => $date, 'location' => 'Kitchen', 'stage' => $stage,
            'reportedQty' => $qty, 'sourceRef' => 't:'.Str::random(8), 'evidence' => [['type' => 'document', 'id' => $this->proof->id, 'locator' => 'photo']]]);
        $this->assertTrue($r['success'], $r['error'] ?? '');
        $v = $ledger->reviewRecord(['id' => $r['id'], 'action' => 'verify', 'verifiedQty' => $qty, 'reviewNote' => 'ok']);
        $this->assertTrue($v['success'], $v['error'] ?? '');
    }

    private function draft(string $end): PayApplication
    {
        $d = app(ClaimEvidenceService::class)->draft($this->contract->id, $end);
        $this->assertTrue($d['success'], $d['error'] ?? '');

        return PayApplication::findOrFail($d['id']);
    }

    private function open(int $appId): Spreadsheet
    {
        $built = app(GcClaimWorkbookService::class)->build($appId);
        $this->assertTrue($built['success'], $built['error'] ?? '');
        $book = IOFactory::load($built['path']);
        @unlink($built['path']);

        return $book;
    }

    public function test_first_and_second_claims_fill_the_gc_form_from_verified_quantities(): void
    {
        $wall = $this->line('15');      // 행 58 · 2,088 LF2 · 자재 2.75 + 노무 7.24
        $pipe = $this->line('189');     // 행 232 · 설비 배관(자재 0 → 수량 기준)
        $this->work($wall, 'stored', 1000, '2026-09-10');
        $this->work($wall, 'installation', 600, '2026-09-20');
        $this->work($pipe, 'installed', 50, '2026-09-22');
        $first = $this->draft('2026-09-30');

        $preview = app(GcClaimWorkbookService::class)->preview($first->id);
        $this->assertTrue($preview['success'], $preview['error'] ?? '');
        $fixed = array_map(fn ($f) => strtok($f, ' '), $preview['fixes']);
        sort($fixed);
        $this->assertSame(['M103', 'M121', 'M169', 'T103', 'T121', 'T169'], $fixed, '원청 양식에서 범위가 틀린 소계 6칸만 바로잡는다');

        $book = $this->open($first->id);
        $cont = $book->getSheetByName('3_CONTINUATION SHEET');
        $this->assertNull($cont->getCell('L58')->getValue(), '1차에는 전회가 없다');
        $this->assertEquals(600, $cont->getCell('N58')->getValue(), '금회 = 설치 확인 수량');
        $this->assertEquals(400, $cont->getCell('X58')->getValue(), '반입 1000 중 설치 안 한 400 은 반입 자재 칸');
        $this->assertEqualsWithDelta(400 * 2.75, $cont->getCell('Y58')->getCalculatedValue(), 0.001);
        $this->assertEquals(50, $cont->getCell('N232')->getValue());
        $this->assertNull($cont->getCell('N45')->getValue(), '원본의 예상치는 지운다');
        $this->assertSame(1, $cont->getCell('U2')->getValue());
        $this->assertSame('=SUM(M91:M101)', $cont->getCell('M103')->getValue());
        $this->assertSame('=SUM(M45:M47)', $cont->getCell('M49')->getValue(), '맞는 수식은 그대로 둔다');

        $cover = $book->getSheetByName('1_PROGRESS PAYMENT');
        $this->assertSame('MATERIALS STORED', $cover->getCell('C22')->getValue());
        $this->assertEqualsWithDelta(1100, $cover->getCell('G22')->getCalculatedValue(), 0.001);
        $this->assertEquals(0, $cover->getCell('E19')->getValue(), '1차에 선급금을 청구한다');
        // 양식 금액(설치 × 단가 + 반입 자재)이 대장 금액과 같다.
        $formTotal = 600 * 9.99 + 50 * 46.51 + 400 * 2.75;
        $this->assertEqualsWithDelta($formTotal, (float) $first->this_period_amount, 0.02);

        // 2차: 1차를 제출하고, 나머지 400 설치.
        $first->update(['status' => 'submitted']);
        $this->work($wall, 'installation', 400, '2026-10-10');
        $second = $this->draft('2026-10-19');
        $this->assertSame(2, (int) $second->application_no);

        $book2 = $this->open($second->id);
        $cont2 = $book2->getSheetByName('3_CONTINUATION SHEET');
        $this->assertEquals(600, $cont2->getCell('L58')->getValue(), '전회 = 1차 배정 수량');
        $this->assertEquals(400, $cont2->getCell('N58')->getValue());
        $this->assertNull($cont2->getCell('X58')->getValue(), '반입분을 다 설치했으니 반입 자재 칸은 비었다');
        $this->assertEquals(50, $cont2->getCell('L232')->getValue());
        $cover2 = $book2->getSheetByName('1_PROGRESS PAYMENT');
        $this->assertEqualsWithDelta(1100, $cover2->getCell('E22')->getValue(), 0.001, '전회 반입 자재');
        $this->assertEqualsWithDelta(0, $cover2->getCell('G22')->getCalculatedValue(), 0.001);
        $this->assertEquals(357800, $cover2->getCell('E19')->getValue(), '선급금은 1차에 받았다');
        $this->assertSame(2, $cont2->getCell('U2')->getValue());
        // 금회 공사 금액 = 400 × 9.99 (천 달러 절사 전 줄 금액)
        $this->assertEqualsWithDelta(400 * 9.99, $cont2->getCell('O58')->getCalculatedValue(), 0.001);
    }

    public function test_approved_rfi_work_goes_to_its_own_sheet_and_the_vo_line(): void
    {
        $section = WorkSection::where('code', 'A3')->firstOrFail();
        $rfi = app(ContractChangeService::class);
        $this->assertTrue($rfi->submit(['sectionId' => $section->id, 'kind' => 'add', 'rfiNo' => '003', 'title' => 'Extra wall',
            'items' => [['description' => 'Extra partition', 'unit' => 'EA', 'qty' => 2, 'laborPrice' => 500]]])['success']);
        $change = ContractChange::firstOrFail();
        $this->assertTrue($rfi->decide(['id' => $change->id, 'action' => 'approve', 'approvalDocumentId' => $this->proof->id])['success']);
        $this->work(ContractBoqLine::where('line_no', 'RFI-003-1')->firstOrFail(), 'installed', 1, '2026-09-15');
        $app = $this->draft('2026-09-30');

        $book = $this->open($app->id);
        $sheet = $book->getSheetByName('4_RFI CHANGE ORDERS');
        $this->assertNotNull($sheet);
        $this->assertSame('RFI-003-1', $sheet->getCell('A4')->getValue());
        $cover = $book->getSheetByName('1_PROGRESS PAYMENT');
        $this->assertEqualsWithDelta(500, $cover->getCell('G21')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(1000, $book->getSheetByName('2_APPLICATION')->getCell('F18')->getValue(), 0.001, '변경 계약 금액');
    }

    public function test_installed_more_than_delivered_stops_the_export(): void
    {
        $wall = $this->line('15');
        $this->work($wall, 'stored', 100, '2026-09-10');
        $this->work($wall, 'installation', 300, '2026-09-20');
        $app = $this->draft('2026-09-30');

        $r = app(GcClaimWorkbookService::class)->preview($app->id);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('#15', $r['error']);
    }

    public function test_only_ledger_applications_export_and_viewers_need_billing_access(): void
    {
        $manual = PayApplication::create(['project_contract_id' => $this->contract->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => 'draft', 'type' => 'progress']);
        $this->assertFalse(app(GcClaimWorkbookService::class)->preview($manual->id)['success']);

        $this->actingAs(User::factory()->create(['access_role' => 'safety_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $this->assertFalse(app(GcClaimWorkbookService::class)->applications($this->contract->id)['success']);
        $this->get(route('billing-export.gc-claim', ['application' => $manual->id]))->assertStatus(422);
    }
}
