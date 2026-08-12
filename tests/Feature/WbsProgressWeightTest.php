<?php

namespace Tests\Feature;

use App\Models\WbsItem;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 공정률이 "완료" 를 어떻게 반영하는지 — 무게를 무엇으로 잡느냐가 전부다.
 *
 * 현장에서 올라온 실제 간트에는 투입조 칸이 없어 81개 작업 전부 공수가 비어 있었다.
 * 그러면 예전 코드는 단순 평균으로 떨어지고, 17일짜리 배관공사와 발주서 한 장이
 * 똑같은 1/81 이 된다. 조달 23건만 발주완료해도 공정률이 28% 로 뛰었다 — 실제로
 * 현장에서 한 일은 0일인데도.
 *
 * 이 테스트들은 그 상황을 그대로 재현한다.
 */
class WbsProgressWeightTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array{name: string, days?: int|null, mh?: float|null, done?: bool}>  $rows
     */
    private function seedRows(array $rows, string $projectCode = 'WGT-01'): void
    {
        $stage = WbsItem::create([
            'project_code' => $projectCode, 'level' => 'stage', 'wbs_code' => "{$projectCode}-S-1",
            'node_no' => '1', 'name' => '1구간', 'sort_order' => 0,
        ]);
        $task = WbsItem::create([
            'project_code' => $projectCode, 'parent_id' => $stage->id, 'level' => 'task',
            'wbs_code' => "{$projectCode}-T-1-1", 'node_no' => '1.1', 'name' => '공종', 'sort_order' => 0,
        ]);

        foreach (array_values($rows) as $i => $r) {
            $done = $r['done'] ?? false;
            WbsItem::create([
                'project_code' => $projectCode, 'parent_id' => $task->id, 'level' => 'subtask',
                'wbs_code' => "{$projectCode}-W-".($i + 1), 'node_no' => '1.1.'.($i + 1),
                'name' => $r['name'],
                'days' => $r['days'] ?? null,
                'manhours' => $r['mh'] ?? null,
                'status' => $done ? '완료' : '검수완료',
                'progress' => $done ? 100 : 0,
                'crew_size' => 0,
                'sort_order' => $i,
            ]);
        }
    }

    private function progress(string $projectCode = 'WGT-01'): int
    {
        return app(WbsService::class)->progressSummary($projectCode)['progress'];
    }

    public function test_공수가_없으면_공기를_무게로_쓴다(): void
    {
        // 17일 + 3일 중 17일짜리 하나 완료 → 17/20 = 85%.
        // 단순 평균이었다면 50% 로 나온다.
        $this->seedRows([
            ['name' => '배관 설치', 'days' => 17, 'done' => true],
            ['name' => '마감 점검', 'days' => 3],
        ]);

        $this->assertSame(85, $this->progress());
    }

    public function test_공기가_없는_조달행은_공정률을_올리지_않는다(): void
    {
        // 발주 23건은 공기가 없다 — 무게 0 이라 계산에서 저절로 빠진다.
        $rows = [];
        for ($i = 0; $i < 23; $i++) {
            $rows[] = ['name' => "발주 {$i}", 'days' => null, 'done' => true];
        }
        $rows[] = ['name' => '배관 설치', 'days' => 17];

        $this->seedRows($rows);

        // 예전 규칙(단순 평균)이었다면 23/24 = 96%.
        $this->assertSame(0, $this->progress());
    }

    public function test_공사를_전부_끝내면_조달이_남아도_100퍼센트다(): void
    {
        // 분모가 실제 공사만 세므로 남은 발주가 100% 를 막지 않는다.
        $this->seedRows([
            ['name' => '발주', 'days' => null],
            ['name' => '배관 설치', 'days' => 17, 'done' => true],
            ['name' => '전기 결선', 'days' => 5, 'done' => true],
        ]);

        $this->assertSame(100, $this->progress());
    }

    public function test_공수가_있으면_공수가_이긴다(): void
    {
        // 공수는 사람이 확정한 값이다. 공기보다 정확하므로 먼저 쓴다.
        // 40MH @100% + 10MH @0% = 80%. (공기로 쟀다면 1/11 = 9%)
        $this->seedRows([
            ['name' => '앵커 설치', 'days' => 1, 'mh' => 40, 'done' => true],
            ['name' => '트레이 보강', 'days' => 10, 'mh' => 10],
        ]);

        $this->assertSame(80, $this->progress());
    }

    public function test_공수도_공기도_없으면_균등_평균으로_간다(): void
    {
        $this->seedRows([
            ['name' => '작업 A', 'done' => true],
            ['name' => '작업 B'],
            ['name' => '작업 C'],
            ['name' => '작업 D'],
        ]);

        $this->assertSame(25, $this->progress());
    }

    public function test_구간_진척률도_같은_규칙을_쓴다(): void
    {
        $this->seedRows([
            ['name' => '배관 설치', 'days' => 17, 'done' => true],
            ['name' => '마감 점검', 'days' => 3],
        ]);

        $stages = app(WbsService::class)->progressSummary('WGT-01')['stages'];

        $this->assertSame(85, $stages[0]['progress']);
    }

    public function test_공기가_음수여도_무게로_끌려들어가지_않는다(): void
    {
        // 잘못 적힌 값이 분모를 갉아먹으면 100% 를 넘는 진척률이 나온다.
        $this->seedRows([
            ['name' => '오기입', 'days' => -5, 'done' => true],
            ['name' => '배관 설치', 'days' => 10, 'done' => true],
        ]);

        $this->assertSame(100, $this->progress());
    }
}
