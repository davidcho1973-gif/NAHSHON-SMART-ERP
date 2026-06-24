<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SafetyWorkItem;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WbsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a small Stage→Task→SubTask tree for project TST-01.
     *
     * @param  array<int, array<string, mixed>>  $subs  extra subtask overrides
     */
    private function seedTree(array $subs = []): void
    {
        $stage = WbsItem::create([
            'project_code' => 'TST-01', 'level' => 'stage', 'wbs_code' => 'TST-01-S-1',
            'node_no' => '1', 'name' => '설치', 'sort_order' => 0,
        ]);
        $task = WbsItem::create([
            'project_code' => 'TST-01', 'parent_id' => $stage->id, 'level' => 'task', 'wbs_code' => 'TST-01-T-1.1',
            'node_no' => '1.1', 'name' => '패널 설치', 'sort_order' => 0,
        ]);

        $defaults = [
            ['no' => '1.1.1', 'name' => '앵커 설치', 'mh' => 40, 'status' => '완료', 'progress' => 100],
            ['no' => '1.1.2', 'name' => '트레이 보강', 'mh' => 10, 'status' => 'AI생성', 'progress' => 0],
        ];

        foreach (array_merge($defaults, $subs) as $i => $s) {
            WbsItem::create([
                'project_code' => 'TST-01', 'parent_id' => $task->id, 'level' => 'subtask',
                'wbs_code' => 'TST-01-W-' . $s['no'], 'node_no' => $s['no'], 'name' => $s['name'],
                'company' => $s['company'] ?? 'NAHSHON', 'manhours' => $s['mh'], 'days' => 1,
                'ehs' => $s['ehs'] ?? 'medium', 'status' => $s['status'], 'progress' => $s['progress'],
                'safety_work_code' => $s['safety'] ?? null, 'sort_order' => $i,
            ]);
        }
    }

    public function test_tree_returns_stage_task_subtask_hierarchy(): void
    {
        $this->seedTree();

        $tree = app(WbsService::class)->tree('TST-01');

        $this->assertTrue($tree['success']);
        $this->assertCount(1, $tree['stages']);
        $this->assertSame('설치', $tree['stages'][0]['stage_name']);
        $this->assertSame('패널 설치', $tree['stages'][0]['tasks'][0]['task_name']);
        $this->assertCount(2, $tree['stages'][0]['tasks'][0]['sub_tasks']);
        $this->assertSame('앵커 설치', $tree['stages'][0]['tasks'][0]['sub_tasks'][0]['sub_name']);
    }

    public function test_subtask_progress_rolls_up_from_linked_safety_card(): void
    {
        // The merge: a WBS subtask linked to a safety work card reflects the field-measured progress.
        SafetyWorkItem::create([
            'work_code' => 'WRK-LINK-1', 'title' => '앵커 설치 작업', 'progress' => 70,
            'plan_status' => '검토중', 'tbm_status' => '대기', 'close_status' => '시작전', 'progress_status' => '추천완료',
        ]);

        $this->seedTree([
            ['no' => '1.1.3', 'name' => '앵커 현장작업', 'mh' => 20, 'status' => '진행중', 'progress' => 20, 'safety' => 'WRK-LINK-1'],
        ]);

        $tree = app(WbsService::class)->tree('TST-01');
        $subs = $tree['stages'][0]['tasks'][0]['sub_tasks'];
        $linked = collect($subs)->firstWhere('wbs_id', 'TST-01-W-1.1.3');

        // own progress 20 < safety card 70 → rolls up to 70.
        $this->assertSame(70, $linked['progress']);
    }

    public function test_progress_summary_is_manhour_weighted(): void
    {
        // 40MH @100% + 10MH @0% = 4000/50 = 80%.
        $this->seedTree();

        $sum = app(WbsService::class)->progressSummary('TST-01');

        $this->assertSame(80, $sum['progress']);
        $this->assertSame(2, $sum['totalWbsCount']);
        $this->assertSame(1, $sum['completedCount']);
        // single stage → its weighted progress equals the overall (80%).
        $this->assertSame(80, $sum['stages'][0]['progress']);
    }

    public function test_mark_status_toggles_and_syncs_progress(): void
    {
        $this->seedTree();

        app(WbsService::class)->markStatus('TST-01-W-1.1.2', '완료');
        $this->assertSame(100, (int) WbsItem::where('wbs_code', 'TST-01-W-1.1.2')->value('progress'));

        app(WbsService::class)->markStatus('TST-01-W-1.1.2', 'AI생성');
        $this->assertSame(0, (int) WbsItem::where('wbs_code', 'TST-01-W-1.1.2')->value('progress'));
    }

    public function test_update_row_maps_korean_keys(): void
    {
        $this->seedTree();

        app(WbsService::class)->updateRow('TST-01-W-1.1.1', [
            '담당사' => 'M-SOL', '상태' => '진행중', '종료예정' => '2026-07-01',
        ]);

        $item = WbsItem::where('wbs_code', 'TST-01-W-1.1.1')->first();
        $this->assertSame('M-SOL', $item->company);
        $this->assertSame('진행중', $item->status);
        $this->assertSame('2026-07-01', $item->planned_end->format('Y-m-d'));
    }

    public function test_process_manual_generates_and_persists_wbs(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'stages' => [[
                    'stage_no' => '1', 'stage_name' => '반입', 'tasks' => [[
                        'task_no' => '1.1', 'task_name' => '하역', 'sub_tasks' => [
                            ['sub_no' => '1.1.1', 'sub_name' => '장비 하역', 'company' => 'NAHSHON', 'manhours' => 24, 'days' => 2, 'ehs' => 'medium'],
                        ],
                    ]],
                ]],
            ])]]]]],
        ])]);

        Project::create(['project_code' => 'TST-01', 'name' => '테스트 설치', 'construction_type' => 'equipment_setting', 'scope_of_work' => '장비 반입 및 설치']);

        $res = app(\App\Services\Wbs\GeminiWbsAnalyzer::class)->processManual('TST-01');

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['results'][0]['subTasks']);
        $this->assertDatabaseHas('wbs_items', ['wbs_code' => 'TST-01-W-1.1.1', 'name' => '장비 하역', 'source' => 'ai']);
    }

    public function test_process_manual_preserves_existing_progress_on_regen(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'stages' => [['stage_no' => '1', 'stage_name' => '설치', 'tasks' => [[
                    'task_no' => '1.1', 'task_name' => '패널 설치', 'sub_tasks' => [
                        ['sub_no' => '1.1.1', 'sub_name' => '앵커 설치', 'company' => 'NAHSHON', 'manhours' => 40, 'days' => 3, 'ehs' => 'high'],
                    ],
                ]]]],
            ])]]]]],
        ])]);

        // Pre-existing field progress on 1.1.1 must survive an AI regen.
        $this->seedTree();
        app(\App\Services\Wbs\GeminiWbsAnalyzer::class)->processManual('TST-01');

        $this->assertSame('완료', WbsItem::where('wbs_code', 'TST-01-W-1.1.1')->value('status'));
        $this->assertSame(100, (int) WbsItem::where('wbs_code', 'TST-01-W-1.1.1')->value('progress'));
    }

    public function test_api_get_wbs_tree_endpoint(): void
    {
        $this->seedTree();
        $user = User::factory()->create(['access_role' => 'site_manager', 'account_status' => 'active']);

        $res = $this->actingAs($user)->postJson('/smart-company-api/api_getProjectWbsTree', [
            'args' => ['TST-01'], 'siteId' => 'ALL',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('stages.0.stage_name', '설치');
    }
}
