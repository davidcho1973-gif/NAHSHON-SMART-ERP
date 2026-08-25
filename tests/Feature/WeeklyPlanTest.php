<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Wbs\WeeklyPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 공정 P2 — 주간 리듬(LPS): 3주 선행 뷰 · 이번 주 약속 · PPC · 표준 사유.
 */
class WeeklyPlanTest extends TestCase
{
    use RefreshDatabase;

    private const P = 'LPS-01';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['code' => 'S1', 'name' => '현장', 'status' => 'active']);
    }

    private function sub(string $id, array $attrs = []): WbsItem
    {
        static $order = 0;
        $stage = WbsItem::firstOrCreate(
            ['project_code' => self::P, 'level' => 'stage', 'wbs_code' => self::P.'-S-1'],
            ['name' => 'S', 'sort_order' => 0, 'site_id' => $this->site->id],
        );
        $task = WbsItem::firstOrCreate(
            ['project_code' => self::P, 'level' => 'task', 'wbs_code' => self::P.'-T-1'],
            ['parent_id' => $stage->id, 'name' => 'T', 'sort_order' => 0, 'site_id' => $this->site->id],
        );

        return WbsItem::create(array_merge([
            'project_code' => self::P, 'level' => 'subtask', 'parent_id' => $task->id,
            'wbs_code' => self::P.'-W-'.$id, 'activity_id' => $id, 'name' => '작업 '.$id,
            'status' => '검수완료', 'sort_order' => ++$order, 'site_id' => $this->site->id,
        ], $attrs));
    }

    private function svc(): WeeklyPlanService
    {
        return app(WeeklyPlanService::class);
    }

    public function test_선행_미완과_자재_미입고가_시작_제약으로_잡힌다(): void
    {
        $monday = Carbon::now()->startOfWeek()->toDateString();
        $this->sub('A', ['planned_start' => $monday, 'planned_end' => $monday, 'status' => '진행중']);
        $b = $this->sub('B', ['planned_start' => $monday, 'planned_end' => $monday, 'preds' => ['A']]);
        ProcurementItem::create(['project_code' => self::P, 'site_id' => $this->site->id,
            'wbs_code' => $b->wbs_code, 'po_no' => 'PO-9', 'status' => '발주완료']);

        $plan = $this->svc()->lookahead(self::P);

        $rows = collect($plan['weeks'][0]['items'])->keyBy('wbs_id');
        $bRow = $rows[$b->wbs_code];
        $this->assertFalse($bRow['ready']);
        $this->assertContains('선행 A 미완', $bRow['constraints']);
        $this->assertContains('자재 미입고 (PO PO-9)', $bRow['constraints']);

        // A 를 끝내고 자재가 들어오면 제약이 사라진다 — 제약 제거가 관리의 본질.
        WbsItem::query()->where('wbs_code', self::P.'-W-A')->update(['status' => '완료']);
        ProcurementItem::query()->update(['status' => '입고완료']);
        $again = collect($this->svc()->lookahead(self::P)['weeks'][0]['items'])->keyBy('wbs_id');
        $this->assertTrue($again[$b->wbs_code]['ready']);
    }

    public function test_이번주_약속_자동_제안은_사람이_뺀_것을_다시_덮지_않는다(): void
    {
        $monday = Carbon::now()->startOfWeek()->toDateString();
        $a = $this->sub('A', ['planned_start' => $monday, 'planned_end' => $monday]);
        $this->sub('B', ['planned_start' => $monday, 'planned_end' => $monday]);

        $this->assertSame(2, $this->svc()->commitThisWeek(self::P));

        // 사장이 A 를 약속에서 뺐다.
        $this->svc()->toggleCommit($a->wbs_code);
        $this->assertNull($a->fresh()->committed_week);

        // 자동 제안을 다시 돌려도 사람이 뺀 A 는 돌아오지 않는다 — 자동이 검토를 이기면 안 된다.
        $added = $this->svc()->commitThisWeek(self::P);
        $this->assertSame(0, $added);
        $this->assertNull($a->fresh()->committed_week);

        // 사람이 다시 넣는 것은 언제든 가능하다.
        $this->svc()->toggleCommit($a->wbs_code);
        $this->assertNotNull($a->fresh()->committed_week);
    }

    public function test_PPC_와_표준_사유_통계가_계산된다(): void
    {
        $lastWeek = WeeklyPlanService::weekKey(Carbon::now()->subWeek());
        $this->sub('A', ['committed_week' => $lastWeek, 'status' => '완료']);
        $this->sub('B', ['committed_week' => $lastWeek, 'status' => '진행중', 'incomplete_reason' => 'materials']);
        $this->sub('C', ['committed_week' => $lastWeek, 'status' => '검수완료', 'incomplete_reason' => 'materials']);
        $this->sub('D', ['committed_week' => $lastWeek, 'status' => '검수완료']);

        $ppc = $this->svc()->ppc(self::P, $lastWeek);

        $this->assertSame(25, $ppc['ppc'], '4건 약속 중 1건 완료');
        $this->assertSame(2, $ppc['reasons']['materials'], '자재 지연이 최다 사유로 집계된다');
        $this->assertSame(1, $ppc['reasons']['unspecified']);
    }

    public function test_월요일_명령은_상황실_방에_요약을_남긴다(): void
    {
        $room = CommunicationRoom::create([
            'site_id' => $this->site->id, 'type' => CommunicationRoom::TYPE_SITE_OPS,
            'name' => '상황실', 'status' => 'active',
        ]);
        $lastWeek = WeeklyPlanService::weekKey(Carbon::now()->subWeek());
        $this->sub('A', ['committed_week' => $lastWeek, 'status' => '완료']);
        $this->sub('B', ['committed_week' => $lastWeek, 'status' => '진행중', 'incomplete_reason' => 'manpower']);

        $this->artisan('wbs:weekly-plan', ['project' => self::P])->assertSuccessful();

        $message = CommunicationMessage::query()->where('communication_room_id', $room->id)->first();
        $this->assertNotNull($message, '요약이 방으로 가야 월요일 회의가 그걸로 시작한다');
        $this->assertStringContainsString('약속 이행률(PPC) 50%', $message->body);
        $this->assertStringContainsString('인력 부족', $message->body);
        $this->assertSame('weekly_plan', $message->payload['bot'] ?? null);
    }

    public function test_표준_코드가_아닌_사유는_저장되지_않는다(): void
    {
        $item = $this->sub('A');

        app(\App\Services\Wbs\WbsService::class)->updateRow($item->wbs_code, ['미완료사유' => 'materials']);
        $this->assertSame('materials', $item->fresh()->incomplete_reason);

        app(\App\Services\Wbs\WbsService::class)->updateRow($item->wbs_code, ['미완료사유' => '아무말']);
        $this->assertNull($item->fresh()->incomplete_reason, '자유 텍스트는 통계가 안 된다 — 표준 코드만');
    }
}
