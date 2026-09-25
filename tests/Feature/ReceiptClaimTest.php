<?php

namespace Tests\Feature;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\MaterialClaimLink;
use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptLine;
use App\Models\ProjectContract;
use App\Models\User;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Finance\ContractSheetImportService;
use App\Services\Finance\ReceiptClaimConnector;
use App\Services\Inventory\MaterialReceiptService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 송장 → 반입 기록 — 사장 지시(2026-09-25) «송장 사진 올리면 반입 기록 자동으로 되게 해줘».
 *
 * 잠그는 것:
 *  - 입고를 확정하는 순간 송장 줄이 계약 줄의 «반입» 기록(확인 대기)이 된다. 근거는 송장 사진.
 *  - 사람이 한 번 연결한 품목은 기억해 AI 없이 바로 잇는다. 확신 없는 AI 추정은 쓰지 않는다.
 *  - 단위 환산(10FT 한 개 = 10)과 계약 수량 상한을 지킨다.
 *  - 확정을 풀면 대기 기록을 거둔다. 이미 확인된 기록이 있으면 풀지 못한다.
 *  - 송장 사진이 실제 파일로 확인돼야 기성이 된다.
 */
class ReceiptClaimTest extends TestCase
{
    use ContractWorkbookFixture { setUp as fixtureSetUp; }
    use RefreshDatabase;

    private ContractBoqLine $wall;

    /** @var array<int, array<string, mixed>> AI 가 돌려줄 짝 */
    private array $aiMatches = [];

    private int $aiCalls = 0;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        Carbon::setTestNow('2026-09-20 12:00:00');
        Storage::fake('public');
        $contract = ProjectContract::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'title' => 'Prime',
            'direction' => 'receivable', 'original_amount' => 3000, 'currency' => 'USD']);
        $this->assertTrue(app(ContractSheetImportService::class)->import(['documentId' => $this->workbook()->id, 'contractId' => $contract->id, 'confirmContract' => true])['success']);
        $this->wall = ContractBoqLine::where('line_no', '2')->firstOrFail();   // 100 LF2 · 자재 2.5 · 노무 7.5

        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andReturnUsing(function (array $images, string $prompt): array {
            $this->aiCalls++;
            $this->assertStringContainsString('Dry Wall', $prompt, 'AI 는 반입으로 받는 계약 줄 목록을 본다');

            return ['data' => ['matches' => $this->aiMatches]];
        });
        $engine->shouldReceive('name')->andReturn('fake');
        $this->app->instance(OcrEngine::class, $engine);
    }

    private function receipt(string $name = 'GYP BOARD 4X8', float $qty = 3, string $unit = 'SHT'): MaterialReceipt
    {
        Storage::disk('public')->put('material-receipts/inv.jpg', 'invoice-photo');
        $r = MaterialReceipt::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'received_on' => '2026-09-18',
            'vendor' => 'ABC Supply', 'status' => 'draft', 'photo_disk' => 'public', 'photo_path' => 'material-receipts/inv.jpg', 'photo_name' => 'inv.jpg']);
        MaterialReceiptLine::create(['material_receipt_id' => $r->id, 'name' => $name, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => 50, 'seq' => 1]);

        return $r;
    }

    private function confirm(MaterialReceipt $r, bool $on = true): array
    {
        return app(MaterialReceiptService::class)->confirm($r->id, $on);
    }

    public function test_confirming_an_invoice_creates_a_pending_delivery_record_with_the_photo_as_proof(): void
    {
        $r = $this->receipt();
        $line = $r->lines()->first();
        $this->aiMatches = [['receipt_line' => 'r'.$line->id, 'contract_line_id' => $this->wall->id, 'factor' => 32, 'confidence' => 0.9, 'reason' => '4x8 sheet = 32 sf']];

        $res = $this->confirm($r);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame(1, $res['claims']['created']);

        $record = ClaimWorkRecord::sole();
        $this->assertSame('stored', $record->stage);
        $this->assertSame('pending', $record->status, '청구 전에 사람이 확인한다');
        $this->assertEquals(96, $record->reported_qty, '3 장 × 32 sf');
        $this->assertSame('receipt', $record->evidence[0]['type']);
        $this->assertStringContainsString('AI 추정', $record->notes);
        $this->assertSame('2026-09-18', $record->work_date->toDateString(), '반입일 = 송장 날짜');

        // 송장 사진이 실제 파일로 확인되므로 기성이 된다.
        $v = app(ClaimEvidenceService::class)->reviewRecord(['id' => $record->id, 'action' => 'verify', 'verifiedQty' => 96, 'reviewNote' => 'invoice checked']);
        $this->assertTrue($v['success'], $v['error'] ?? '');
        $this->assertEquals(240, app(ClaimEvidenceService::class)->getLedger($this->wall->project_contract_id)['summary']['verifiedAmount'], '반입 96 × 자재 2.5');

        // 확인된 반입이 있으면 송장 확정을 못 푼다 — 근거가 사라지므로.
        $undo = $this->confirm($r, false);
        $this->assertFalse($undo['success']);
        $this->assertSame('confirmed', $r->fresh()->status);
    }

    public function test_unsure_matches_wait_for_a_person_and_the_person_link_is_remembered(): void
    {
        $r = $this->receipt('1/2 GYP 4X8 WR');
        $line = $r->lines()->first();
        $this->aiMatches = [['receipt_line' => 'r'.$line->id, 'contract_line_id' => $this->wall->id, 'factor' => 32, 'confidence' => 0.4, 'reason' => 'maybe']];
        $res = $this->confirm($r);
        $this->assertSame(0, $res['claims']['created']);
        $this->assertSame(1, $res['claims']['unmatched'], '확신 없는 추정은 쓰지 않는다');

        $view = app(ReceiptClaimConnector::class)->view($r->id);
        $this->assertTrue($view['success']);
        $this->assertNull($view['rows'][0]['record']);
        $this->assertSame([$this->wall->id], array_column($view['options'], 'id'), '반입으로 받는 줄만 고른다(자재 단가 없는 줄 제외)');

        $link = app(ReceiptClaimConnector::class)->link(['receiptLineId' => $line->id, 'contractLineId' => $this->wall->id, 'factor' => 32]);
        $this->assertTrue($link['success'], $link['error'] ?? '');
        $this->assertEquals(96, ClaimWorkRecord::sole()->reported_qty);
        $this->assertSame(1, MaterialClaimLink::count());

        // 다음 송장은 AI 없이 기억으로 잇는다.
        $calls = $this->aiCalls;
        $next = $this->receipt('1/2 gyp 4x8 wr', 1);
        $this->assertSame(1, $this->confirm($next)['claims']['created']);
        $this->assertSame($calls, $this->aiCalls, '기억한 품목은 AI 에게 다시 묻지 않는다');
        $this->assertStringContainsString('기억한 연결', ClaimWorkRecord::latest('id')->first()->notes);
    }

    public function test_quantity_is_capped_at_the_contract_and_unconfirm_withdraws_pending_records(): void
    {
        $r = $this->receipt('GYP BOARD 4X8', 10);
        $this->aiMatches = [['receipt_line' => 'r'.$r->lines()->first()->id, 'contract_line_id' => $this->wall->id, 'factor' => 32, 'confidence' => 0.95, 'reason' => 'x']];
        $this->confirm($r);
        $record = ClaimWorkRecord::sole();
        $this->assertEquals(100, $record->reported_qty, '320 을 들여왔어도 계약 수량 100 까지만');
        $this->assertStringContainsString('RFI', $record->notes);

        $this->assertTrue($this->confirm($r, false)['success']);
        $this->assertSame('rejected', $record->fresh()->status, '확정을 풀면 대기 기록을 거둔다');

        $this->assertTrue($this->confirm($r)['success']);
        $this->assertSame(1, ClaimWorkRecord::where('status', 'pending')->count(), '다시 확정하면 새로 만든다');
    }

    public function test_no_ai_answer_leaves_the_receipt_confirmed_and_everything_for_a_person(): void
    {
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andThrow(new \RuntimeException('AI down'));
        $this->app->instance(OcrEngine::class, $engine);
        $r = $this->receipt();

        $res = $this->confirm($r);
        $this->assertTrue($res['success']);
        $this->assertSame('confirmed', $r->fresh()->status);
        $this->assertSame(1, $res['claims']['unmatched']);
        $this->assertSame(0, ClaimWorkRecord::count());
    }

    public function test_a_foreman_confirmation_is_linked_when_the_billing_manager_opens_the_receipt(): void
    {
        $r = $this->receipt();
        $this->aiMatches = [['receipt_line' => 'r'.$r->lines()->first()->id, 'contract_line_id' => $this->wall->id, 'factor' => 32, 'confidence' => 0.9, 'reason' => 'x']];
        $manager = auth()->user();
        $this->actingAs(User::factory()->create(['access_role' => 'safety_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $res = $this->confirm($r);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame(0, ClaimWorkRecord::count(), '기성 권한 없는 사람의 확정은 기록을 만들지 않는다');

        $this->actingAs($manager);
        $view = app(ReceiptClaimConnector::class)->view($r->id);
        $this->assertNotNull($view['rows'][0]['record'], '담당자가 열면 그때 잇는다');
        $calls = $this->aiCalls;
        app(ReceiptClaimConnector::class)->view($r->id);
        $this->assertSame($calls, $this->aiCalls, '다시 열어도 다시 묻지 않는다');
    }
}
