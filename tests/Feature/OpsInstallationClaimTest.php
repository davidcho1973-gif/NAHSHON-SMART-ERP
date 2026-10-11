<?php

namespace Tests\Feature;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\DailyTradeReport;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\Project;
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

    private Project $project;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        Carbon::setTestNow('2026-09-20 15:00:00');
        $this->manager = auth()->user();
        $this->project = Project::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'project_code' => 'OPS-CLAIM-1', 'name' => 'Kitchen claim project',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded']);
        $contract = ProjectContract::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'project_id' => $this->project->id, 'title' => 'Prime',
            'direction' => 'receivable', 'original_amount' => 3000, 'currency' => 'USD', 'payload' => ['claim_profile' => $this->confirmedClaimProfile()]]);
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
        $report = DailyTradeReport::create(['site_id' => $this->site->id, 'work_date' => '2026-09-18',
            'trade' => 'Drywall', 'kind' => 'trade', 'status' => 'open']);
        $batch->update(['daily_trade_report_id' => $report->id]);

        $r = app(OpsClaimReflector::class)->reflect($batch);
        $this->assertSame(['proposed' => 1, 'applied' => 1], $r);

        $item = OpsIntakeItem::where('category', 'installation')->sole();
        $this->assertSame('applied', $item->status);
        $this->assertSame($this->project->project_code, $item->project_code, '근거는 선택한 계약의 프로젝트에 속한다');
        $this->assertSame('2026-09-18', $item->occurred_on->toDateString(), '보고일은 접수일로 덮어쓰지 않는다');
        $install = ClaimWorkRecord::where('stage', 'installation')->sole();
        $this->assertSame('pending', $install->status, '확인 대기 — 담당자가 확인해야 기성');
        $this->assertEquals(40, $install->reported_qty);
        $this->assertSame('2026-09-18', $install->work_date->toDateString());
        $this->assertSame('2026-09-18', ClaimWorkRecord::where('stage', 'stored')->sole()->work_date->toDateString());
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

    public function test_undated_report_uses_site_received_date_and_requires_work_date_review(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-09-20T01:00:00Z'));
        $this->reply = [['contract_line_id' => $this->duct->id, 'quantity' => 4, 'confidence' => 0.9]];
        $batch = $this->report('덕트 4 m2 설치', $this->manager);

        $this->assertSame(['proposed' => 1, 'applied' => 1], app(OpsClaimReflector::class)->reflect($batch));

        $item = OpsIntakeItem::where('category', 'installation')->sole();
        $record = ClaimWorkRecord::sole();
        $this->assertSame('2026-09-19', $item->occurred_on->toDateString(), '뉴욕 접수일은 UTC 날짜와 다르다');
        $this->assertSame('2026-09-19', $record->work_date->toDateString());
        $this->assertSame('pending', $record->status, '접수일을 확인된 작업일로 취급하지 않는다');
        $this->assertStringContainsString('작업일 확인 필요', $item->summary);
        $this->assertStringContainsString('작업일 미기재 — 현장 접수일 2026-09-19', $record->notes);
    }

    public function test_intake_evidence_cannot_follow_a_contract_into_another_project_at_the_same_site(): void
    {
        $foreman = User::factory()->create(['access_role' => 'safety_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->reply = [['contract_line_id' => $this->duct->id, 'quantity' => 4, 'confidence' => 0.9]];
        $this->actingAs($foreman);
        $this->assertSame(['proposed' => 1, 'applied' => 0],
            app(OpsClaimReflector::class)->reflect($this->report('덕트 4 m2 설치', $foreman)));
        $item = OpsIntakeItem::where('category', 'installation')->sole();
        $this->assertSame($this->project->project_code, $item->project_code);

        $other = Project::create(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'project_code' => 'OPS-CLAIM-2', 'name' => 'Other project at the same site',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded']);
        $this->duct->contract->update(['project_id' => $other->id]);
        $this->actingAs($this->manager);

        $res = app(OpsIntakeService::class)->apply($item->id, null, $this->manager->id);
        $this->assertFalse($res['success'], '같은 현장이어도 다른 프로젝트의 근거는 기성에 쓸 수 없다');
        $this->assertSame(0, ClaimWorkRecord::count());
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertSame($this->project->project_code, $item->fresh()->project_code, '기존 근거 범위를 고쳐서 통과시키지 않는다');
    }
}
