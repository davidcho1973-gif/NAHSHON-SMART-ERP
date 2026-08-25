<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SafetyWorkItem;
use App\Models\WbsItem;
use App\Services\Wbs\ScheduleImporter;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * 외부 CPM 공정표(엑셀) → WBS 임포트.
 *
 * 실제 LG ESS Ph10 공정표와 같은 형태(계층번호 없는 평평한 액티비티 + 마일스톤 구간)를
 * 코드로 재현해 검증한다 — 외부 파일에 의존하지 않게.
 */
class ScheduleImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sched') . '.xlsx';

        $w = new Writer();
        $w->openToFile($path);
        $w->addRow(Row::fromValues([
            'ID', '작업명', 'Activity', '공기(일)', '선행작업', 'ES일자', 'EF일자',
            'LS일자', 'LF일자', '여유', 'CP', '배분원가', '공종', '투입조',
        ]));
        // A010 → A020 → A030. A010/A030 은 임계경로.
        $w->addRow(Row::fromValues(['A010', '착공지시', 'NTP', 5, '', '2026-08-03', '2026-08-07', '2026-08-03', '2026-08-07', 0, '★', 24000, 'GC', 'PM/PE']));
        $w->addRow(Row::fromValues(['A020', '가설 방진벽', 'Dust barrier', 5, 'A010', '2026-08-10', '2026-08-14', '2026-08-17', '2026-08-21', 5, '', 20040, 'GC', '2 carpenters + 3 laborers']));
        $w->addRow(Row::fromValues(['A030', '천장 전기 배관', 'Conduit', 10, 'A010,A020', '2026-08-17', '2026-08-28', '2026-08-17', '2026-08-28', 0, '★', 25000, 'ELEC', '2 electricians + lifts']));
        $w->addRow(Row::fromValues(['◆ 마일스톤']));
        $w->addRow(Row::fromValues(['', '인허가 발급', '', '', '', '2026-08-07']));
        $w->addRow(Row::fromValues(['', '전기 배관 완료', '', '', '', '2026-08-28']));
        $w->close();

        return $path;
    }

    private function import(string $code = 'TST-IMP'): array
    {
        Project::create(['project_code' => $code, 'name' => '임포트 테스트', 'construction_type' => 'equipment_setting']);

        return app(ScheduleImporter::class)->importFromXlsx($this->makeXlsx(), $code);
    }

    public function test_builds_stage_task_subtask_tree_from_flat_activity_list(): void
    {
        $res = $this->import();

        // 마일스톤 2개 = Stage 2개. 액티비티 3개는 EF 기준으로 구간에 배정된다.
        $this->assertSame(2, $res['stages']);
        $this->assertSame(3, $res['subtasks']);
        $this->assertSame(3, $res['activities']);
        $this->assertSame([], $res['warnings']);

        $stages = WbsItem::where('project_code', 'TST-IMP')->where('level', 'stage')->orderBy('sort_order')->get();
        $this->assertSame('인허가 발급', $stages[0]->name);
        $this->assertSame('전기 배관 완료', $stages[1]->name);
    }

    public function test_groups_activities_by_trade_under_each_milestone(): void
    {
        $this->import();

        $stage2 = WbsItem::where('wbs_code', 'TST-IMP-S-2')->first();
        $tasks = WbsItem::where('parent_id', $stage2->id)->orderBy('sort_order')->pluck('name')->all();

        // 두 번째 구간에는 GC(방진벽)와 ELEC(배관)이 들어간다.
        $this->assertEqualsCanonicalizing(['GC', 'ELEC'], $tasks);
    }

    public function test_preserves_predecessors_and_recomputes_float_critical_path(): void
    {
        $this->import();

        $a030 = WbsItem::where('activity_id', 'A030')->first();
        $this->assertSame(['A010', 'A020'], $a030->preds);
        $this->assertSame(0, $a030->float_days);
        $this->assertTrue($a030->is_critical);

        // 여유·LS/LF 는 엑셀 값을 복사하지 않고 사내 CPM 엔진이 재계산한다(정본 하나 원칙).
        // A020(종료 08-14)은 A030 시작(08-17) 전날인 08-16 까지 밀려도 준공이 안 움직인다 → 여유 2일.
        $a020 = WbsItem::where('activity_id', 'A020')->first();
        $this->assertSame(2, $a020->float_days);
        $this->assertFalse($a020->is_critical);
        $this->assertSame('2026-08-16', $a020->late_end->toDateString());

        // 날짜 자체는 시트 그대로다 — 엔진은 수입 시 날짜를 옮기지 않는다.
        $this->assertSame('2026-08-10', $a020->planned_start->toDateString());
        $this->assertSame('2026-08-14', $a020->planned_end->toDateString());
    }

    public function test_parses_crew_into_headcount_and_equipment(): void
    {
        $this->import();

        $a020 = WbsItem::where('activity_id', 'A020')->first();
        $this->assertSame('5.0', (string) $a020->crew_size);
        $this->assertSame('2 carpenters + 3 laborers', $a020->crew_text);

        $a030 = WbsItem::where('activity_id', 'A030')->first();
        $this->assertSame(['lifts'], $a030->equipment);
        $this->assertSame('high', $a030->ehs); // 고소작업 → 고위험 초기값
    }

    public function test_derives_manhours_from_crew_and_duration(): void
    {
        $this->import();

        // 5명 × 5일 × 8h = 200MH. 진척률이 공수 가중이라 이 값이 있어야 집계가 성립한다.
        $this->assertSame('200.0', (string) WbsItem::where('activity_id', 'A020')->value('manhours'));
    }

    public function test_keeps_planned_cost_matching_the_source(): void
    {
        $this->import();

        $total = WbsItem::where('project_code', 'TST-IMP')->where('level', 'subtask')->sum('planned_cost');
        $this->assertSame(69040.0, (float) $total);
    }

    public function test_reimport_preserves_field_progress_by_activity_id(): void
    {
        $this->import();

        // 현장이 실제로 완료 처리하려면 그날 안전카드 + TBM 완료를 거쳐야 한다.
        app(WbsService::class)->createSafetyCard('TST-IMP-W-A020');
        SafetyWorkItem::where('wbs_code', 'TST-IMP-W-A020')->update(['tbm_status' => '완료']);
        app(WbsService::class)->markStatus('TST-IMP-W-A020', '완료');

        // 공정표를 다시 받아도 현장이 입력한 진행 상태는 살아남아야 한다.
        app(ScheduleImporter::class)->importFromXlsx($this->makeXlsx(), 'TST-IMP');

        $a020 = WbsItem::where('activity_id', 'A020')->first();
        $this->assertSame('완료', $a020->status);
        $this->assertSame(100, $a020->progress);
    }

    public function test_wbs_code_is_stable_across_reimport(): void
    {
        $this->import();
        $before = WbsItem::where('activity_id', 'A030')->value('wbs_code');

        app(ScheduleImporter::class)->importFromXlsx($this->makeXlsx(), 'TST-IMP');

        $this->assertSame($before, WbsItem::where('activity_id', 'A030')->value('wbs_code'));
        $this->assertSame('TST-IMP-W-A030', $before);
    }

    public function test_subtask_exposes_cpm_and_crew_to_the_frontend(): void
    {
        $this->import();

        $tree = app(WbsService::class)->tree('TST-IMP');
        $subs = collect($tree['stages'])->flatMap(fn ($s) => collect($s['tasks'])->flatMap(fn ($t) => $t['sub_tasks']));
        $a030 = $subs->firstWhere('activity_id', 'A030');

        $this->assertTrue($a030['isCritical']);
        $this->assertSame(['A010', 'A020'], $a030['preds']);
        $this->assertSame(['lifts'], $a030['equipment']);
        $this->assertSame(2.0, $a030['crewSize']);
    }

    public function test_daily_safety_cards_gate_only_their_own_day(): void
    {
        $this->import();

        // 같은 공정에 이틀치 카드: 8/17 은 TBM 완료, 8/18 은 대기.
        SafetyWorkItem::create([
            'work_code' => 'WRK-D1', 'title' => '배관 1일차', 'wbs_code' => 'TST-IMP-W-A030',
            'work_date' => '2026-08-17', 'tbm_status' => '완료',
            'plan_status' => '승인완료', 'close_status' => '시작전', 'progress_status' => '미분석',
        ]);
        SafetyWorkItem::create([
            'work_code' => 'WRK-D2', 'title' => '배관 2일차', 'wbs_code' => 'TST-IMP-W-A030',
            'work_date' => '2026-08-18', 'tbm_status' => '대기',
            'plan_status' => '승인완료', 'close_status' => '시작전', 'progress_status' => '미분석',
        ]);

        $item = WbsItem::where('activity_id', 'A030')->with('safetyWorkItems.signatures')->first();

        $this->assertCount(2, $item->safetyWorkItems);
        $this->assertSame('WRK-D1', $item->safetyCardFor('2026-08-17')->work_code);
        $this->assertTrue($item->safetyCardFor('2026-08-17')->isTbmCleared());
        $this->assertFalse($item->safetyCardFor('2026-08-18')->isTbmCleared());
        $this->assertNull($item->safetyCardFor('2026-08-19'));
    }

    public function test_creating_a_safety_card_carries_crew_and_equipment_from_the_plan(): void
    {
        $this->import();

        // "작업을 시작한다" = 그날의 안전계획을 세운다. 인원·장비가 계획에서 넘어와야 한다.
        $res = app(WbsService::class)->createSafetyCard('TST-IMP-W-A030', '2026-08-17');

        $this->assertTrue($res['success']);
        $this->assertTrue($res['created']);

        $card = SafetyWorkItem::where('work_code', $res['work_code'])->with('signatures')->first();
        $this->assertSame('TST-IMP-W-A030', $card->wbs_code);
        $this->assertSame('2026-08-17', $card->work_date->toDateString());
        $this->assertSame(2, $card->crew);
        $this->assertStringContainsString('2 electricians + lifts', $card->work_text);
        $this->assertSame(['lifts'], $card->plan_payload['equipment']);
        $this->assertSame('A030', $card->plan_payload['activity_id']);

        // 계획 인원수만큼 서명란이 미리 준비된다 — TBM 때 이름만 채우면 되게.
        $this->assertCount(2, $card->signatures);
        $this->assertSame('electrician', $card->signatures[0]->role);
        $this->assertFalse((bool) $card->signatures[0]->signed);
    }

    public function test_creating_a_safety_card_twice_on_the_same_day_is_idempotent(): void
    {
        $this->import();

        $first = app(WbsService::class)->createSafetyCard('TST-IMP-W-A030', '2026-08-17');
        $second = app(WbsService::class)->createSafetyCard('TST-IMP-W-A030', '2026-08-17');

        $this->assertTrue($second['success']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['work_code'], $second['work_code']);
        $this->assertSame(1, SafetyWorkItem::where('wbs_code', 'TST-IMP-W-A030')->count());
    }

    public function test_safety_card_cannot_be_created_on_a_stage_or_task(): void
    {
        $this->import();

        $res = app(WbsService::class)->createSafetyCard('TST-IMP-S-1');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('SubTask', $res['error']);
    }

    public function test_starting_work_is_blocked_until_that_days_tbm_clears(): void
    {
        $this->import();
        $date = now()->toDateString();

        // 안전계획을 세우면(카드 생성) TBM 은 아직 '대기' → 공정 시작이 막혀야 한다.
        app(WbsService::class)->createSafetyCard('TST-IMP-W-A030', $date);
        $blocked = app(WbsService::class)->markStatus('TST-IMP-W-A030', '진행중');

        $this->assertFalse($blocked['success']);
        $this->assertTrue($blocked['gated']);

        // TBM 이 완료되면 통과.
        SafetyWorkItem::where('wbs_code', 'TST-IMP-W-A030')->update(['tbm_status' => '완료']);
        $allowed = app(WbsService::class)->markStatus('TST-IMP-W-A030', '진행중');

        $this->assertTrue($allowed['success']);
    }

    public function test_field_work_cannot_start_without_a_safety_plan(): void
    {
        $this->import();

        // "안전 없이 공정 없다" — 인원이 투입되는 작업은 카드 없이 시작할 수 없다.
        $res = app(WbsService::class)->markStatus('TST-IMP-W-A030', '진행중');

        $this->assertFalse($res['success']);
        $this->assertTrue($res['gated']);
        $this->assertStringContainsString('안전계획 미수립', $res['error']);
    }

    public function test_work_without_field_crew_needs_no_safety_plan(): void
    {
        // 조달/발주처럼 현장 인원이 없는 작업(crew "-")은 TBM 대상이 아니다.
        $this->import();
        WbsItem::where('activity_id', 'A010')->update(['crew_size' => null]);

        $res = app(WbsService::class)->markStatus('TST-IMP-W-A010', '진행중');

        $this->assertTrue($res['success']);
    }

    public function test_safety_card_date_comes_from_the_server_not_the_caller(): void
    {
        // 클라이언트가 UTC 로 계산한 날짜를 넘기면 현장 시간대와 어긋나 게이트가 무력화된다.
        // 날짜를 넘기지 않으면 서버(현장 시간대)가 오늘을 정해야 한다.
        $this->import();

        $res = app(WbsService::class)->createSafetyCard('TST-IMP-W-A030');

        $this->assertSame(now()->toDateString(), $res['date']);
        $this->assertSame(
            now()->toDateString(),
            SafetyWorkItem::where('work_code', $res['work_code'])->value('work_date')->toDateString()
        );
    }

    public function test_progress_rolls_up_from_quantities_across_daily_cards(): void
    {
        $this->import();

        // 하루치씩 쌓인 실적: 12m + 14m = 26m / 총 40m = 65%
        foreach ([['WRK-Q1', '2026-08-17', 12], ['WRK-Q2', '2026-08-18', 14]] as [$code, $date, $done]) {
            SafetyWorkItem::create([
                'work_code' => $code, 'title' => '포설', 'wbs_code' => 'TST-IMP-W-A030',
                'work_date' => $date, 'done_qty' => $done, 'total_qty' => 40, 'unit' => 'm',
                'plan_status' => '승인완료', 'tbm_status' => '완료', 'close_status' => '저장완료', 'progress_status' => '미분석',
            ]);
        }

        $item = WbsItem::where('activity_id', 'A030')->with('safetyWorkItems.signatures')->first();

        $this->assertSame(65, $item->effectiveProgress());
    }
}
