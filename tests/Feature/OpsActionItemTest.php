<?php

namespace Tests\Feature;

use App\Models\OpsActionItem;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\OpsLaborReport;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\DailyClosingService;
use App\Services\Ops\OpsActionService;
use App\Services\Ops\OpsModuleRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "반영할 모듈이 없는" 내용의 종착지 — 원청 지시·승인·의사결정·준비물.
 *
 * 실제 현장 대화(2026-07-27 LG Phoenix)를 기준으로 삼는다. 그 대화의 절반은 공정·자재·인원
 * 어디에도 안 들어갔고, 예전 구조에서는 통째로 버려졌다.
 */
class OpsActionItemTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->today = Carbon::now('America/Phoenix')->toDateString();
    }

    private function user(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
    }

    private function api(string $method, array $args = []): TestResponse
    {
        return $this->postJson('/smart-company-api/'.$method, ['args' => $args, 'siteId' => (string) $this->site->id]);
    }

    private function batch(): OpsIntakeBatch
    {
        return OpsIntakeBatch::create([
            'site_id' => $this->site->id, 'source' => 'paste',
            'raw_text' => '카톡 대화 원문', 'status' => 'done',
        ]);
    }

    private function item(OpsIntakeBatch $batch, string $category, string $summary, array $proposed): OpsIntakeItem
    {
        return OpsIntakeItem::create([
            'site_id' => $this->site->id,
            'ops_intake_batch_id' => $batch->id,
            'category' => $category,
            'summary' => $summary,
            'raw_text' => $summary,
            'confidence' => 85,
            'proposed' => $proposed,
            'status' => 'pending',
        ]);
    }

    public function test_an_owner_instruction_becomes_an_action_item(): void
    {
        $batch = $this->batch();
        // 원청 Sungwook Kim: "화기작업 승인 받으세요."
        $item = $this->item($batch, 'request', '화기작업 승인 신청 필요', [
            'title' => '화기작업 승인 신청',
            'requester' => 'Sungwook Kim',
            'assignee' => 'M.C.KANG',
            'due_on' => Carbon::parse($this->today)->addDay()->toDateString(),
            'is_blocker' => true,
        ]);

        $this->assertSame(1, app(OpsModuleRouter::class)->autoRoute($batch, $item)['action']);

        $a = OpsActionItem::first();
        $this->assertSame('화기작업 승인 신청', $a->title);
        $this->assertSame('Sungwook Kim', $a->requester);
        $this->assertSame('M.C.KANG', $a->assignee);
        $this->assertTrue($a->is_blocker, '이게 안 되면 커팅을 못 하므로 막힘으로 표시돼야 한다');
        $this->assertSame('open', $a->status);
        $this->assertSame('applied', $item->fresh()->status);
    }

    public function test_an_already_granted_approval_lands_as_done(): void
    {
        $batch = $this->batch();
        // "연장작업 신청합니다" → Kimbo: "네, 그러시죠"
        $item = $this->item($batch, 'approval', '연장작업 승인(플러밍, Square tube ~21시)', [
            'title' => '연장작업 승인 (플러밍 · Square tube)',
            'approved' => true,
        ]);

        app(OpsModuleRouter::class)->autoRoute($batch, $item);

        $a = OpsActionItem::first();
        $this->assertSame('done', $a->status, '이미 승인된 건은 할 일 목록을 어지럽히지 않아야 한다');
        $this->assertNotNull($a->done_at);
    }

    public function test_a_decision_waiting_on_someone_stays_open(): void
    {
        $batch = $this->batch();
        // "Underground pipe 29,000불 냈다는데, 네고하고 오케이하시죠?"
        $item = $this->item($batch, 'decision', 'Underground pipe 29,000불 네고 여부 결정', [
            'title' => 'Underground pipe 29,000불 네고 결정',
            'assignee' => '권대표',
        ]);

        app(OpsModuleRouter::class)->autoRoute($batch, $item);

        $a = OpsActionItem::first();
        $this->assertSame(OpsActionItem::KIND_DECISION, $a->kind);
        $this->assertSame('open', $a->status);
    }

    public function test_a_labor_report_without_a_headcount_asks_instead_of_vanishing(): void
    {
        $batch = $this->batch();
        // "플러밍팀 도착" — 인원수가 없다. 예전에는 통째로 사라졌다.
        $item = $this->item($batch, 'labor', '플러밍팀 도착', ['company' => '플러밍팀']);

        $this->assertSame(0, app(OpsModuleRouter::class)->autoRoute($batch, $item)['labor']);

        $item->refresh();
        $this->assertSame('needs_input', $item->status, '인원 보고가 사라지는 것이 가장 나쁘다');
        $this->assertStringContainsString('플러밍팀', (string) $item->question);
        $this->assertStringContainsString('몇 명', (string) $item->question);
        $this->assertSame(0, OpsLaborReport::count(), '답을 듣기 전에는 인원을 만들지 않는다');
    }

    public function test_the_board_splits_by_when_and_puts_blockers_first(): void
    {
        $tomorrow = Carbon::parse($this->today)->addDay()->toDateString();
        $yesterday = Carbon::parse($this->today)->subDay()->toDateString();

        OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'todo', 'title' => '보안경 구매', 'due_on' => $tomorrow]);
        OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'request', 'title' => '화기작업 승인', 'due_on' => $tomorrow, 'is_blocker' => true]);
        OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'todo', 'title' => '용접가스 확보', 'due_on' => $yesterday]);
        OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'decision', 'title' => '29,000불 네고 결정']);
        OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'approval', 'title' => '연장작업 승인', 'status' => 'done', 'done_at' => now()]);

        $b = app(OpsActionService::class)->board($this->site->id, $this->today);

        $this->assertCount(1, $b['overdue']);
        $this->assertSame('용접가스 확보', $b['overdue'][0]['title']);
        $this->assertCount(2, $b['tomorrow']);
        $this->assertCount(1, $b['undated'], '기한 없는 것은 따로 모아야 놓치지 않는다');
        $this->assertCount(1, $b['doneToday']);
        $this->assertSame('화기작업 승인', $b['blockers'][0]['title']);
        $this->assertSame(4, $b['openTotal']);
    }

    public function test_completing_toggles_and_can_be_undone(): void
    {
        $user = $this->user();
        $a = OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'todo', 'title' => '보안경 구매']);

        $this->actingAs($user)->api('api_completeOpsAction', [$a->id])->assertJson(['status' => 'done']);
        $this->assertNotNull($a->fresh()->done_at);

        // 잘못 눌렀을 때 되돌릴 수 있어야 한다.
        $this->actingAs($user)->api('api_completeOpsAction', [$a->id])->assertJson(['status' => 'open']);
        $this->assertNull($a->fresh()->done_at);
    }

    public function test_a_manager_can_add_and_delete_by_hand(): void
    {
        $user = $this->user();

        $this->actingAs($user)->api('api_saveOpsAction', [['title' => 'Pallet jack 준비', 'dueOn' => $this->today]])
            ->assertJson(['success' => true]);
        $this->assertSame(1, OpsActionItem::count());

        $id = OpsActionItem::first()->id;
        $this->actingAs($user)->api('api_deleteOpsAction', [$id])->assertJson(['success' => true]);
        $this->assertSame(0, OpsActionItem::count());
    }

    public function test_hand_entry_requires_a_title(): void
    {
        $this->assertFalse(app(OpsActionService::class)->save($this->site->id, ['title' => '  '])['success']);
    }

    public function test_the_closing_report_carries_todays_work_and_tomorrows_list(): void
    {
        $tomorrow = Carbon::parse($this->today)->addDay()->toDateString();

        OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'approval', 'title' => '연장작업 승인', 'status' => 'done', 'done_at' => now()]);
        OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'request', 'title' => '화기작업 승인 신청', 'due_on' => $tomorrow, 'is_blocker' => true]);
        OpsActionItem::create(['site_id' => $this->site->id, 'kind' => 'todo', 'title' => '보안경 구매']);

        $m = app(DailyClosingService::class)->metrics($this->site->id, $this->today);

        $this->assertSame('연장작업 승인', $m['actions']['doneToday'][0]['title']);
        $this->assertSame('화기작업 승인 신청', $m['actions']['tomorrow'][0]['title']);
        $this->assertSame('보안경 구매', $m['actions']['undated'][0]['title']);
        $this->assertCount(1, $m['actions']['blockers']);
        $this->assertSame(2, $m['actions']['openTotal']);
    }

    public function test_action_categories_are_accepted_by_the_parser_whitelist(): void
    {
        $batch = $this->batch();

        foreach (OpsIntakeItem::ACTION_CATEGORIES as $cat) {
            $item = $this->item($batch, $cat, $cat.' 항목', ['title' => $cat.' 제목']);
            $this->assertSame(1, app(OpsModuleRouter::class)->autoRoute($batch, $item)['action'], $cat.' 는 액션 아이템으로 가야 한다');
        }

        $this->assertSame(count(OpsIntakeItem::ACTION_CATEGORIES), OpsActionItem::count());
    }
}
