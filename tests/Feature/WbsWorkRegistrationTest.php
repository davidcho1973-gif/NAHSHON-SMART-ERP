<?php

namespace Tests\Feature;

use App\Models\SafetyWorkItem;
use App\Models\WbsItem;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "오늘 작업 등록": 공정관리(WBS)에서 작업을 골라 안전카드를 만들고,
 * 공정에 없는 작업은 "현장 추가(비계획)"로 WBS 에 삽입 + 카드 동시 생성.
 */
class WbsWorkRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function subtask(string $code, array $attr = []): WbsItem
    {
        return WbsItem::create(array_merge([
            'project_code' => 'REG-01', 'level' => 'subtask', 'wbs_code' => $code, 'node_no' => '1.1.1',
            'activity_id' => 'A010', 'name' => '천장 전기 배관', 'trade' => 'ELEC', 'status' => '검수완료',
            'planned_start' => '2026-08-10', 'planned_end' => '2026-08-12', 'crew_text' => '2 electricians',
        ], $attr));
    }

    public function test_pick_list_returns_subtasks_with_today_card_flag(): void
    {
        $this->subtask('REG-01-W-A010');
        $items = app(WbsService::class)->pickList('REG-01', 'ALL', '2026-08-10');

        $this->assertCount(1, $items);
        $this->assertSame('A010', $items[0]['activity_id']);
        $this->assertSame('ELEC', $items[0]['trade']);
        $this->assertFalse($items[0]['hasCardToday']);
    }

    public function test_pick_list_flags_when_card_exists_for_date(): void
    {
        $sub = $this->subtask('REG-01-W-A010');
        SafetyWorkItem::create(['work_code' => 'WRK-1', 'wbs_code' => $sub->wbs_code, 'work_date' => '2026-08-10', 'title' => 'x']);

        $items = app(WbsService::class)->pickList('REG-01', 'ALL', '2026-08-10');
        $this->assertTrue($items[0]['hasCardToday']);

        // 다른 날짜엔 카드 없음.
        $other = app(WbsService::class)->pickList('REG-01', 'ALL', '2026-08-11');
        $this->assertFalse($other[0]['hasCardToday']);
    }

    public function test_manual_work_is_inserted_under_field_added_stage_with_card(): void
    {
        $result = app(WbsService::class)->addManualActivity('REG-01', [
            'name' => '긴급 배수 처리', 'trade' => 'PLUMB', 'crew_text' => '1 plumber + 1 helper',
            'location' => 'Utility Room', 'date' => '2026-08-15',
        ], 'ALL', null);

        $this->assertTrue($result['success']);

        // "현장 추가(비계획)" Stage + 공종 Task + SubTask.
        $stage = WbsItem::where('project_code', 'REG-01')->where('level', 'stage')->where('name', '현장 추가(비계획)')->first();
        $this->assertNotNull($stage);
        $sub = WbsItem::where('wbs_code', $result['wbs_code'])->first();
        $this->assertSame('긴급 배수 처리', $sub->name);
        $this->assertSame('PLUMB', $sub->trade);
        $this->assertSame('manual', $sub->source);
        // 표시용 ID 는 짧아야 한다(M01…) — 길면 간트/목록 라벨칸을 넘쳐 작업명과 겹친다.
        $this->assertMatchesRegularExpression('/^M\d{2}$/', $sub->activity_id);
        $this->assertSame('2026-08-15', $sub->planned_start->toDateString());
        $this->assertSame(2.0, (float) $sub->crew_size); // CrewParser: 1 + 1

        // 안전카드가 동시에 생성됨.
        $this->assertTrue($result['card']['success']);
        $this->assertNotNull(SafetyWorkItem::where('wbs_code', $result['wbs_code'])->where('work_date', '2026-08-15')->first());
    }

    public function test_manual_work_reuses_field_added_stage_across_calls(): void
    {
        app(WbsService::class)->addManualActivity('REG-01', ['name' => 'A', 'trade' => 'ELEC'], 'ALL', null);
        app(WbsService::class)->addManualActivity('REG-01', ['name' => 'B', 'trade' => 'ELEC'], 'ALL', null);

        // Stage 하나, ELEC Task 하나, SubTask 둘.
        $this->assertSame(1, WbsItem::where('project_code', 'REG-01')->where('level', 'stage')->where('node_no', 'M')->count());
        $this->assertSame(1, WbsItem::where('project_code', 'REG-01')->where('level', 'task')->where('name', 'ELEC')->count());
        $this->assertSame(2, WbsItem::where('project_code', 'REG-01')->where('level', 'subtask')->where('source', 'manual')->count());
    }
}
