<?php

namespace Tests\Feature;

use App\Models\WbsItem;
use App\Services\Wbs\CpmEngine;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 사내 CPM 엔진 — 일정 편집이 후속 공정과 준공일에 즉시 전파되는가.
 */
class CpmEngineTest extends TestCase
{
    use RefreshDatabase;

    private const P = 'CPM-01';

    /**
     * A(01-05~01-09) → B(01-10~01-14) → C(01-15~01-16), 병렬 D(01-05~01-06, 후속 없음).
     *
     * @param  array<string, array<string, mixed>>  $overrides  activity_id => 속성 덮어쓰기
     * @return array<string, WbsItem>
     */
    private function seedChain(array $overrides = []): array
    {
        $stage = WbsItem::query()->create([
            'project_code' => self::P, 'level' => WbsItem::LEVEL_STAGE,
            'wbs_code' => self::P.'-S-1', 'node_no' => '1', 'name' => '골조', 'sort_order' => 0,
        ]);
        $task = WbsItem::query()->create([
            'project_code' => self::P, 'level' => WbsItem::LEVEL_TASK, 'parent_id' => $stage->id,
            'wbs_code' => self::P.'-T-1.1', 'node_no' => '1.1', 'name' => 'GC', 'sort_order' => 1,
        ]);

        $rows = [
            'A' => ['planned_start' => '2026-01-05', 'planned_end' => '2026-01-09', 'preds' => []],
            'B' => ['planned_start' => '2026-01-10', 'planned_end' => '2026-01-14', 'preds' => ['A']],
            'C' => ['planned_start' => '2026-01-15', 'planned_end' => '2026-01-16', 'preds' => ['B']],
            'D' => ['planned_start' => '2026-01-05', 'planned_end' => '2026-01-06', 'preds' => []],
        ];

        $items = [];
        $order = 0;
        foreach ($rows as $id => $attrs) {
            $items[$id] = WbsItem::query()->create(array_merge([
                'project_code' => self::P, 'level' => WbsItem::LEVEL_SUBTASK, 'parent_id' => $task->id,
                'wbs_code' => self::P.'-W-'.$id, 'node_no' => '1.1.'.(++$order), 'activity_id' => $id,
                'name' => '작업 '.$id, 'status' => '검수완료', 'sort_order' => $order,
            ], $attrs, $overrides[$id] ?? []));
        }

        return $items;
    }

    private function fresh(string $activityId): WbsItem
    {
        return WbsItem::query()->where('project_code', self::P)->where('activity_id', $activityId)->firstOrFail();
    }

    public function test_기준선_계산은_날짜를_움직이지_않고_여유와_주공정을_채운다(): void
    {
        $this->seedChain();

        $r = app(CpmEngine::class)->recompute(self::P);

        $this->assertTrue($r['success']);
        $this->assertFalse($r['skipped']);
        $this->assertSame(0, $r['movedCount'], '가져온 날짜 그대로면 아무것도 움직이면 안 된다');
        $this->assertSame('2026-01-16', $r['projectedEnd']);

        // A→B→C 사슬이 준공을 쥔다.
        foreach (['A', 'B', 'C'] as $id) {
            $row = $this->fresh($id);
            $this->assertTrue((bool) $row->is_critical, "{$id} 는 주공정이어야 한다");
            $this->assertSame(0, (int) $row->float_days);
        }

        // 병렬 D 는 준공(01-16)까지 여유가 있다.
        $d = $this->fresh('D');
        $this->assertFalse((bool) $d->is_critical);
        $this->assertGreaterThan(0, (int) $d->float_days);
        $this->assertSame('2026-01-05', $d->planned_start->toDateString());
    }

    public function test_선행이_밀리면_후속과_준공일이_같이_밀린다(): void
    {
        $this->seedChain();
        app(CpmEngine::class)->recompute(self::P); // 기준선 포착

        // A 종료를 3일 늦춘다 (01-09 → 01-12). K-TALK 반영도 같은 updateRow 를 탄다.
        $res = app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-12']);

        $this->assertTrue($res['success']);
        $this->assertIsArray($res['cpm']);
        $this->assertSame(2, $res['cpm']['movedCount'], 'B·C 두 건이 이동해야 한다');
        $this->assertSame('2026-01-19', $res['cpm']['projectedEnd']);

        $b = $this->fresh('B');
        $this->assertSame('2026-01-13', $b->planned_start->toDateString(), '간격(하루 뒤 시작)이 유지된 채 밀린다');
        $this->assertSame('2026-01-17', $b->planned_end->toDateString(), '작업 폭(5일)은 그대로다');
        $this->assertSame('2026-01-19', $this->fresh('C')->planned_end->toDateString());
    }

    public function test_선행이_제자리로_돌아오면_후속도_돌아온다(): void
    {
        $this->seedChain();
        app(CpmEngine::class)->recompute(self::P);

        app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-12']);
        app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-09']);

        $this->assertSame('2026-01-10', $this->fresh('B')->planned_start->toDateString(), '한 번 밀리면 못 돌아오는 톱니가 되면 안 된다');
        $this->assertSame('2026-01-16', $this->fresh('C')->planned_end->toDateString());
    }

    public function test_같은_날_이어받는_공정표는_그_논리가_보존된다(): void
    {
        // B 가 A 종료일과 같은 날 시작하는 공정표 — 시트에 적힌 그대로가 논리다.
        $this->seedChain(['B' => ['planned_start' => '2026-01-09', 'planned_end' => '2026-01-13']]);
        $r = app(CpmEngine::class)->recompute(self::P);

        $this->assertSame(0, $r['movedCount'], '같은 날 이어받기를 하루 뒤로 밀어 버리면 안 된다');

        app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-11']);

        $this->assertSame('2026-01-11', $this->fresh('B')->planned_start->toDateString(), '같은 날 간격(0일)을 유지한 채 밀린다');
    }

    public function test_진행중_완료_작업의_날짜는_실적이라_움직이지_않는다(): void
    {
        $this->seedChain(['B' => ['status' => '진행중']]);
        app(CpmEngine::class)->recompute(self::P);

        app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-12']);

        $b = $this->fresh('B');
        $this->assertSame('2026-01-10', $b->planned_start->toDateString(), '이미 시작한 작업을 엔진이 옮기면 안 된다');
        // C 는 B(고정)의 끝에서 흘러나오므로 그대로다.
        $this->assertSame('2026-01-15', $this->fresh('C')->planned_start->toDateString());
    }

    public function test_선행관계가_하나도_없으면_계산하지_않고_가져온_값을_지킨다(): void
    {
        $this->seedChain([
            'A' => ['preds' => [], 'is_critical' => true, 'float_days' => 0],
            'B' => ['preds' => [], 'is_critical' => false, 'float_days' => 7],
            'C' => ['preds' => []],
        ]);

        $r = app(CpmEngine::class)->recompute(self::P);

        $this->assertTrue($r['skipped']);
        $this->assertTrue((bool) $this->fresh('A')->is_critical, '엑셀이 준 주공정 표시를 지워 버리면 안 된다');
        $this->assertSame(7, (int) $this->fresh('B')->float_days);
    }

    public function test_순환_선행은_해당_행만_빼고_경고한다(): void
    {
        $this->seedChain(['A' => ['preds' => ['B']]]); // A↔B 순환

        $r = app(CpmEngine::class)->recompute(self::P);

        $this->assertTrue($r['success'], '순환이 있어도 계산 전체가 죽으면 안 된다');
        $this->assertNotEmpty($r['warnings']);
        $this->assertStringContainsString('서로 물려', implode(' ', $r['warnings']));
        // 순환 밖의 D 는 정상 계산된다.
        $this->assertNotNull($this->fresh('D')->float_days);
    }

    public function test_없는_선행_ID_는_경고하고_무시한다(): void
    {
        $this->seedChain(['B' => ['preds' => ['A', 'X999']]]);

        $r = app(CpmEngine::class)->recompute(self::P);

        $this->assertTrue($r['success']);
        $this->assertStringContainsString('X999', implode(' ', $r['warnings']));
        $this->assertTrue((bool) $this->fresh('B')->is_critical, '남은 선행(A)으로는 정상 계산된다');
    }

    public function test_사람이_날짜를_고치면_그_날짜가_새_기준선이_된다(): void
    {
        $this->seedChain();
        app(CpmEngine::class)->recompute(self::P);

        // 사람이 B 를 이틀 뒤로 옮겼다(버퍼 확보). 엔진이 되돌려 놓으면 안 된다.
        app(WbsService::class)->updateRow(self::P.'-W-B', ['시작예정' => '2026-01-12', '종료예정' => '2026-01-16']);

        $this->assertSame('2026-01-12', $this->fresh('B')->planned_start->toDateString());

        // A 가 3일 밀리면: 버퍼 2일이 먼저 흡수하고 1일만 밀린다 (01-12 → 01-13).
        app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-12']);
        $this->assertSame('2026-01-13', $this->fresh('B')->planned_start->toDateString());

        // A 가 제자리로 오면 B 는 사람이 정한 새 기준선(01-12)으로 돌아온다.
        app(WbsService::class)->updateRow(self::P.'-W-A', ['종료예정' => '2026-01-09']);
        $this->assertSame('2026-01-12', $this->fresh('B')->planned_start->toDateString());
    }

    public function test_상태_완료가_되면_그_날짜에서_후속이_흘러나온다(): void
    {
        $this->seedChain();
        app(CpmEngine::class)->recompute(self::P);

        $res = app(WbsService::class)->markStatus(self::P.'-W-A', '완료');

        $this->assertTrue($res['success']);
        $this->assertIsArray($res['cpm'], '상태 변경도 재계산을 부른다');
        $this->assertSame('2026-01-10', $this->fresh('B')->planned_start->toDateString());
    }

    public function test_진척_요약에_예상_준공이_실린다(): void
    {
        $this->seedChain();
        app(CpmEngine::class)->recompute(self::P);

        $sum = app(WbsService::class)->progressSummary(self::P);

        $this->assertSame('2026-01-16', $sum['projectedEnd']);
    }
}
