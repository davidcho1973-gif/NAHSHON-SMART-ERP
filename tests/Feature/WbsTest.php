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
                'company' => $s['company'] ?? 'ABC ENG', 'manhours' => $s['mh'], 'days' => 1,
                'ehs' => $s['ehs'] ?? 'medium', 'status' => $s['status'], 'progress' => $s['progress'],
                // 기본 픽스처는 조달성 작업(현장 인원 0) — 게이트가 개입하지 않는다.
                // 안전 게이트를 검증하는 테스트는 'crew' 를 명시해 현장작업으로 만든다.
                'crew_size' => $s['crew'] ?? 0,
                'sort_order' => $i,
            ]);

            // 안전카드는 이제 공정을 가리킨다(1:N). 링크는 카드 쪽에서 건다.
            if (isset($s['safety'])) {
                SafetyWorkItem::where('work_code', $s['safety'])->update([
                    'wbs_code' => 'TST-01-W-' . $s['no'],
                    'work_date' => $s['safety_date'] ?? now()->toDateString(),
                ]);
            }
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
            ['no' => '1.1.3', 'name' => '앵커 현장작업', 'mh' => 20, 'status' => '진행중', 'progress' => 20, 'safety' => 'WRK-LINK-1', 'crew' => 2],
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

    public function test_update_row_clears_assignment_fields_with_empty_string(): void
    {
        $this->seedTree();
        app(WbsService::class)->updateRow('TST-01-W-1.1.1', ['종료예정' => '2026-07-01']);

        // 담당사/예정일은 빈 값('')으로 배정 해제, 그 외('작업명' 등)는 ''를 무시.
        $res = app(WbsService::class)->updateRow('TST-01-W-1.1.1', [
            '담당사' => '', '종료예정' => '', '작업명' => '',
        ]);

        $this->assertTrue($res['success']);
        $item = WbsItem::where('wbs_code', 'TST-01-W-1.1.1')->first();
        $this->assertNull($item->company);
        $this->assertNull($item->planned_end);
        $this->assertSame('앵커 설치', $item->name);
    }

    public function test_update_row_does_not_gate_when_status_unchanged(): void
    {
        // 이미 진행중인 TBM 미완료 연동 행이라도, 상태가 그대로면 공수/일정 편집이 가능해야 한다.
        SafetyWorkItem::create([
            'work_code' => 'WRK-GATE-EDIT', 'title' => '게이트 편집 검증', 'progress' => 0,
            'plan_status' => '검토중', 'tbm_status' => '대기', 'close_status' => '시작전', 'progress_status' => '미분석',
        ]);
        $this->seedTree([
            ['no' => '1.1.9', 'name' => '게이트 행', 'mh' => 8, 'status' => '진행중', 'progress' => 10, 'safety' => 'WRK-GATE-EDIT', 'crew' => 2],
        ]);

        $res = app(WbsService::class)->updateRow('TST-01-W-1.1.9', ['상태' => '진행중', '공수' => 12]);

        $this->assertTrue($res['success']);
        $this->assertSame(12.0, (float) WbsItem::where('wbs_code', 'TST-01-W-1.1.9')->value('manhours'));

        // 상태를 실제로 완료로 "바꾸는" 것은 여전히 게이트에 막힌다.
        $gated = app(WbsService::class)->updateRow('TST-01-W-1.1.9', ['상태' => '완료']);
        $this->assertFalse($gated['success']);
        $this->assertTrue($gated['gated']);
    }

    public function test_tree_reports_unscoped_total_for_site_scope_distinction(): void
    {
        $this->seedTree();

        $tree = app(WbsService::class)->tree('TST-01');

        $this->assertSame(2, $tree['unscopedTotal']);
    }

    public function test_process_manual_generates_and_persists_wbs(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'stages' => [[
                    'stage_no' => '1', 'stage_name' => '반입', 'tasks' => [[
                        'task_no' => '1.1', 'task_name' => '하역', 'sub_tasks' => [
                            ['sub_no' => '1.1.1', 'sub_name' => '장비 하역', 'company' => 'ABC ENG', 'manhours' => 24, 'days' => 2, 'ehs' => 'medium'],
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
                        ['sub_no' => '1.1.1', 'sub_name' => '앵커 설치', 'company' => 'ABC ENG', 'manhours' => 40, 'days' => 3, 'ehs' => 'high'],
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

    public function test_tbm_gate_blocks_progress_when_tbm_not_cleared(): void
    {
        // Linked safety card with TBM not done → cannot advance the WBS subtask.
        $card = SafetyWorkItem::create([
            'work_code' => 'WRK-GATE-1', 'title' => '앵커 작업', 'progress' => 0,
            'plan_status' => '검토중', 'tbm_status' => '대기', 'close_status' => '시작전', 'progress_status' => '미분석',
        ]);
        $card->signatures()->create(['name' => '김반장', 'role' => '반장', 'signed' => false, 'sort_order' => 0]);

        $this->seedTree([
            ['no' => '1.1.3', 'name' => '앵커 현장작업', 'mh' => 20, 'status' => '검수완료', 'progress' => 0, 'safety' => 'WRK-GATE-1', 'crew' => 2],
        ]);

        $res = app(WbsService::class)->markStatus('TST-01-W-1.1.3', '완료');

        $this->assertFalse($res['success']);
        $this->assertTrue($res['gated']);
        $this->assertSame('검수완료', WbsItem::where('wbs_code', 'TST-01-W-1.1.3')->value('status'));
    }

    public function test_tbm_gate_allows_progress_when_tbm_cleared(): void
    {
        SafetyWorkItem::create([
            'work_code' => 'WRK-GATE-2', 'title' => '앵커 작업', 'progress' => 0,
            'plan_status' => '승인완료', 'tbm_status' => '완료', 'close_status' => '시작전', 'progress_status' => '미분석',
        ]);

        $this->seedTree([
            ['no' => '1.1.3', 'name' => '앵커 현장작업', 'mh' => 20, 'status' => '검수완료', 'progress' => 0, 'safety' => 'WRK-GATE-2', 'crew' => 2],
        ]);

        $res = app(WbsService::class)->markStatus('TST-01-W-1.1.3', '완료');

        $this->assertTrue($res['success']);
        $this->assertSame('완료', WbsItem::where('wbs_code', 'TST-01-W-1.1.3')->value('status'));
    }

    public function test_tbm_gate_ignores_subtask_without_field_crew(): void
    {
        // 조달/발주처럼 현장 인원이 없는 작업은 TBM 대상이 아니다 → 게이트 없음.
        $this->seedTree();

        $res = app(WbsService::class)->markStatus('TST-01-W-1.1.2', '완료');

        $this->assertTrue($res['success']);
    }

    public function test_field_work_without_a_safety_card_cannot_advance(): void
    {
        // 예전 계약은 "안전카드 링크가 없으면 무조건 통과"였다 — 이것이 곧 안전 구멍이었다.
        // 이제는 현장 인원이 투입되는 작업이면 그날의 안전카드가 반드시 있어야 한다.
        $this->seedTree([
            ['no' => '1.1.4', 'name' => '카드 없는 현장작업', 'mh' => 16, 'status' => '검수완료', 'progress' => 0, 'crew' => 3],
        ]);

        $res = app(WbsService::class)->markStatus('TST-01-W-1.1.4', '진행중');

        $this->assertFalse($res['success']);
        $this->assertTrue($res['gated']);
        $this->assertStringContainsString('안전계획 미수립', $res['error']);
        $this->assertSame('검수완료', WbsItem::where('wbs_code', 'TST-01-W-1.1.4')->value('status'));
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

    public function test_api_update_row_clears_company_through_http_middleware(): void
    {
        // ConvertEmptyStringsToNull 미들웨어가 '' 를 null 로 바꿔도 배정 해제가 동작해야 한다.
        $this->seedTree();
        $user = User::factory()->create(['access_role' => 'site_manager', 'account_status' => 'active']);

        $res = $this->actingAs($user)->postJson('/smart-company-api/api_updateWbsRow', [
            'args' => ['TST-01-W-1.1.1', ['담당사' => '']], 'siteId' => 'ALL',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true);
        $this->assertNull(WbsItem::where('wbs_code', 'TST-01-W-1.1.1')->value('company'));
    }
}
