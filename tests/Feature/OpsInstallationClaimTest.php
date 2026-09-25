<?php

namespace Tests\Feature;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\ProjectContract;
use App\Models\User;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Finance\ContractSheetImportService;
use App\Services\Ocr\OcrEngine;
use App\Services\Ops\OpsClaimReflector;
use App\Services\Ops\OpsIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 상황실 글 → 설치 기성 — 사장 지시(2026-09-25) «상황실 글·사진으로 설치 기록도 자동으로».
 *
 * 잠그는 것:
 *  - 글이 계약 줄을 몇 만큼 설치했다고 하면 «설치 기성» 제안이 생기고, 기성 권한이 있는 글쓴이면 바로 기록된다.
 *  - 기록은 확인 대기이고, 근거는 그 상황실 글이다. 빠진 반입은 설치 규칙대로 함께 적힌다.
 *  - 반장 글은 제안으로 남아 관리자가 반영한다. 되돌리면 대기 기록은 반려, 확인된 것은 못 되돌린다.
 *  - 확신이 낮거나 수량이 없으면 만들지 않는다. 계약 수량을 넘지 않는다. 같은 글을 두 번 읽지 않는다.
 */
class OpsInstallationClaimTest extends TestCase
{
    use ContractWorkbookFixture { setUp as fixtureSetUp; }
    use RefreshDatabase;

    private ContractBoqLine $wall;

    private ContractBoqLine $duct;

    /** @var array<int, array<string, mixed>> */
    private array $reply = [];

    private User $manager;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        Carbon::setTestNow('2026-09-20 15:00:00');
        $this->manager = auth()->user();
        $contract = ProjectContract::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'title' => 'Prime',
            'direction' => 'receivable', 'original_amount' => 3000, 'currency' => 'USD']);
        $this->assertTrue(app(ContractSheetImportService::class)->import(['documentId' => $this->workbook()->id, 'contractId' => $contract->id, 'confirmContract' => true])['success']);
        $this->wall = ContractBoqLine::where('line_no', '2')->firstOrFail();   // 100 LF2 · 반입/설치 분리
        $this->duct = ContractBoqLine::where('line_no', '8')->firstOrFail();   // 10 M2 · 수량 기준

        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andReturnUsing(function (array $images, string $prompt): array {
            $this->assertStringContainsString('Dry Wall', $prompt);

            return ['data' => ['installations' => $this->reply]];
        });
        $engine->shouldReceive('name')->andReturn('fake');
        $this->app->instance(OcrEngine::class, $engine);
    }

    private function report(string $text, User $author): OpsIntakeBatch
    {
        return OpsIntakeBatch::create(['site_id' => $this->site->id, 'created_by_id' => $author->id, 'source' => 'paste', 'raw_text' => $text, 'image_count' => 0]);
    }

    public function test_a_billing_managers_post_records_installation_and_the_missing_delivery(): void
    {
        $this->reply = [['contract_line_id' => $this->wall->id, 'quantity' => 40, 'location' => 'Pantry north wall', 'quote' => 'Pantry 북쪽 벽 드라이월 40 SF 마감', 'confidence' => 0.9]];
        $batch = $this->report('Pantry 북쪽 벽 드라이월 40 SF 마감했습니다', $this->manager);

        $r = app(OpsClaimReflector::class)->reflect($batch);
        $this->assertSame(['proposed' => 1, 'applied' => 1], $r);

        $item = OpsIntakeItem::where('category', 'installation')->sole();
        $this->assertSame('applied', $item->status);
        $install = ClaimWorkRecord::where('stage', 'installation')->sole();
        $this->assertSame('pending', $install->status, '확인 대기 — 담당자가 확인해야 기성');
        $this->assertEquals(40, $install->reported_qty);
        $this->assertSame('intake', $install->evidence[0]['type']);
        $this->assertSame($item->id, $install->evidence[0]['id']);
        $this->assertEquals(40, ClaimWorkRecord::where('stage', 'stored')->sole()->reported_qty, '설치한 자재는 들어온 것 — 빠진 반입도 함께');

        $this->assertSame(['proposed' => 0, 'applied' => 0], app(OpsClaimReflector::class)->reflect($batch), '같은 글은 두 번 읽지 않는다');

        // 상황실 글이 근거라 확인(청구)까지 된다.
        $v = app(ClaimEvidenceService::class)->reviewRecord(['id' => $install->id, 'action' => 'verify', 'verifiedQty' => 40, 'reviewNote' => 'photo ok']);
        $this->assertTrue($v['success'], $v['error'] ?? '');
        $this->assertFalse(app(OpsIntakeService::class)->revert($item->id, $this->manager->id)['success'], '확인된 설치는 되돌리지 않는다');
    }

    public function test_a_foremans_post_waits_for_a_manager_and_can_be_reverted(): void
    {
        $foreman = User::factory()->create(['access_role' => 'safety_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->reply = [['contract_line_id' => $this->duct->id, 'quantity' => 4, 'location' => 'Kitchen hood', 'quote' => '덕트 4 m2', 'confidence' => 0.85]];
        $batch = $this->report('주방 후드 덕트 4 m2 설치', $foreman);

        $this->actingAs($foreman);
        $this->assertSame(['proposed' => 1, 'applied' => 0], app(OpsClaimReflector::class)->reflect($batch));
        $item = OpsIntakeItem::where('category', 'installation')->sole();
        $this->assertSame('pending', $item->status);
        $this->assertSame(0, ClaimWorkRecord::count());

        $this->actingAs($this->manager);
        $res = app(OpsIntakeService::class)->apply($item->id, null, $this->manager->id);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $record = ClaimWorkRecord::sole();
        $this->assertSame('installed', $record->stage, '수량 기준 줄은 시공 단계, 반입 없음');

        $undo = app(OpsIntakeService::class)->revert($item->id, $this->manager->id);
        $this->assertTrue($undo['success'], $undo['error'] ?? '');
        $this->assertSame('rejected', $record->fresh()->status);
        $this->assertSame('dismissed', $item->fresh()->status);
    }

    public function test_unsure_or_quantityless_reports_make_nothing_and_quantity_is_capped(): void
    {
        $this->reply = [
            ['contract_line_id' => $this->wall->id, 'quantity' => 30, 'confidence' => 0.5],
            ['contract_line_id' => $this->duct->id, 'quantity' => 0, 'confidence' => 0.95],
            ['contract_line_id' => 999999, 'quantity' => 3, 'confidence' => 0.95],
        ];
        $this->assertSame(['proposed' => 0, 'applied' => 0], app(OpsClaimReflector::class)->reflect($this->report('드라이월 좀 했어요', $this->manager)));

        $this->reply = [['contract_line_id' => $this->duct->id, 'quantity' => 25, 'confidence' => 0.95, 'location' => 'x']];
        app(OpsClaimReflector::class)->reflect($this->report('덕트 25 m2 전부 끝', $this->manager));
        $this->assertEquals(10, ClaimWorkRecord::sole()->reported_qty, '계약 10 까지만 — 넘는 것은 RFI');
        $this->assertStringContainsString('RFI', OpsIntakeItem::where('category', 'installation')->sole()->proposed['note']);
    }
}
