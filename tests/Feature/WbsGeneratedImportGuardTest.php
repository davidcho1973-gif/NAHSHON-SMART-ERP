<?php

namespace Tests\Feature;

use App\Models\WbsItem;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * AI 가 «지어낸» 공정표는 사람이 맞춘 공정표를 덮어쓰지 못한다.
 *
 * 「AI 메뉴얼 분석」 에 공정표가 아닌 문서(도면·시방서)를 넣으면 AI 는 표준 WBS 를
 * 추측으로 만들고, 그 경로는 프로젝트의 기존 트리를 통째로 지운 뒤 새로 쓴다.
 * 703K 는 원청 공정표를 들여와 손으로 62건을 맞춘 것이다 — 도면 한 장에 그것이
 * 교과서 공정표로 바뀌면 안 된다.
 */
class WbsGeneratedImportGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<string, mixed>> */
    private function generated(): array
    {
        return [[
            'stage_no' => '1', 'stage_name' => '준비',
            'tasks' => [[
                'task_no' => '1.1', 'task_name' => '가설',
                'sub_tasks' => [['sub_no' => '1.1.1', 'sub_name' => '가설 울타리', 'trade' => 'GC', 'manhours' => 16, 'days' => 2, 'ehs' => 'low']],
            ]],
        ]];
    }

    public function test_an_imported_schedule_is_not_overwritten_by_a_generated_one(): void
    {
        WbsItem::create(['project_code' => '703K-KITCHEN', 'level' => 'subtask', 'wbs_code' => '703K-KITCHEN-W-A010',
            'activity_id' => 'A010', 'name' => '착공 준비', 'source' => 'import']);

        try {
            app(WbsService::class)->importGenerated('703K-KITCHEN', $this->generated(), 'ALL');
            $this->fail('들여온 공정표 위에 AI 생성 공정표가 덮어써졌다.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('덮어쓰지 않았습니다', $e->getMessage());
        }

        $this->assertNotNull(WbsItem::query()->where('wbs_code', '703K-KITCHEN-W-A010')->first(), '원래 줄이 그대로 있어야 한다.');
        $this->assertSame(1, WbsItem::query()->where('project_code', '703K-KITCHEN')->count());
    }

    public function test_a_hand_fixed_schedule_is_protected_too(): void
    {
        WbsItem::create(['project_code' => '703K-KITCHEN', 'level' => 'subtask', 'wbs_code' => '703K-KITCHEN-W-P040',
            'activity_id' => 'P040', 'name' => '급탕기 패키지', 'source' => 'gap-fill']);

        $this->expectException(RuntimeException::class);
        app(WbsService::class)->importGenerated('703K-KITCHEN', $this->generated(), 'ALL');
    }

    public function test_a_schedule_the_ai_made_can_still_be_regenerated(): void
    {
        // 원래 이 경로의 용도 — AI 가 만든 것을 AI 가 다시 만드는 것 — 는 그대로다.
        WbsItem::create(['project_code' => 'NEW-1', 'level' => 'subtask', 'wbs_code' => 'NEW-1-W-1.1.1',
            'node_no' => '1.1.1', 'name' => '옛 세부작업', 'source' => 'ai']);

        $counts = app(WbsService::class)->importGenerated('NEW-1', $this->generated(), 'ALL');

        $this->assertSame(1, $counts['subtasks']);
        $this->assertSame('가설 울타리', WbsItem::query()->where('project_code', 'NEW-1')->where('level', 'subtask')->value('name'));
    }

    public function test_an_empty_project_takes_the_generated_schedule(): void
    {
        $counts = app(WbsService::class)->importGenerated('NEW-2', $this->generated(), 'ALL');

        $this->assertSame(['stages' => 1, 'tasks' => 1, 'subtasks' => 1], $counts);
    }
}
