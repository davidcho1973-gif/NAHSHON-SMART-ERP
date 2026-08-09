<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\SafetyWorkSignature;
use App\Models\WbsItem;
use App\Services\Wbs\LaborAllocationService;
use App\Services\Wbs\WbsLaborService;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 공정별 인력 배치 현황 — 인사/출퇴근을 WBS(공정관리)와 연동한다.
 *
 * "오늘 어느 작업에 사람이 몇 명 필요한데(crew_size) 실제로 몇 명 들어왔나(안전카드 서명)"를
 * 한 줄씩 대조하고, 인력이 모자란 임계경로 작업을 맨 위로 끌어올린다.
 */
class LaborAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedProject(): void
    {
        Project::firstOrCreate(['project_code' => 'ALLOC-01'], ['name' => '배치 현황', 'construction_type' => 'equipment_setting']);
    }

    private function seedWork(string $activity, float $crew, bool $critical = false, ?string $trade = 'ELEC'): WbsItem
    {
        $this->seedProject();

        return WbsItem::create([
            'project_code' => 'ALLOC-01', 'level' => 'subtask',
            'wbs_code' => 'ALLOC-01-W-' . $activity, 'activity_id' => $activity, 'node_no' => '1.1',
            'name' => $activity . ' 작업', 'company' => $trade, 'trade' => $trade, 'crew_size' => $crew,
            'status' => '검수완료', 'progress' => 0, 'is_critical' => $critical, 'float_days' => $critical ? 0 : 5,
            'crew_roles' => [['count' => $crew, 'role' => 'worker', 'external' => false, 'note' => null]],
            'planned_start' => now()->toDateString(), 'planned_end' => now()->addDays(2)->toDateString(),
        ]);
    }

    /** 안전카드 서명란 중 앞에서 $n 개를 실제 서명 처리한다 = 실투입 $n 명. */
    private function deploy(WbsItem $item, int $n): void
    {
        app(WbsService::class)->createSafetyCard($item->wbs_code);
        $company = Company::firstOrCreate(['code' => 'DASOL PRISM'], ['name' => 'DASOL PRISM']);
        $sigs = SafetyWorkSignature::whereHas('workItem', fn ($q) => $q->where('wbs_code', $item->wbs_code))
            ->orderBy('id')->take($n)->get();
        foreach ($sigs as $i => $sig) {
            $emp = Employee::create([
                'company_id' => $company->id, 'name' => '작업자' . $item->activity_id . $i,
                'employee_number' => 'E-' . fake()->unique()->numberBetween(1000, 99999),
                'employment_status' => 'active',
            ]);
            app(WbsLaborService::class)->assignEmployee($sig->id, $emp->id);
            $sig->update(['signed' => true, 'signed_at' => now()]);
        }
    }

    public function test_planned_crew_is_compared_against_actual_signed_crew(): void
    {
        $item = $this->seedWork('A100', crew: 3);
        $this->deploy($item, 2); // 3명 계획, 2명 실투입

        $res = app(LaborAllocationService::class)->forSite('ALL');

        $this->assertTrue($res['success']);
        $this->assertCount(1, $res['items']);
        $row = $res['items'][0];
        $this->assertSame(3, $row['planned']);
        $this->assertSame(2, $row['actual']);
        $this->assertSame(1, $row['shortBy']);
        $this->assertSame(67, $row['fillPct']); // round(2/3*100)
        $this->assertSame('short', $row['status']);
        $this->assertSame('전기', $row['tradeLabel']);
    }

    public function test_fully_staffed_activity_reads_as_ok(): void
    {
        $item = $this->seedWork('A110', crew: 2);
        $this->deploy($item, 2);

        $row = app(LaborAllocationService::class)->forSite('ALL')['items'][0];

        $this->assertSame('ok', $row['status']);
        $this->assertSame(0, $row['shortBy']);
        $this->assertSame(100, $row['fillPct']);
    }

    public function test_critical_path_shortfall_is_flagged_and_sorted_first(): void
    {
        // 비임계 부족 작업 먼저 등록, 임계 부족 작업 나중 등록 — 그래도 임계가 맨 위로 와야 한다.
        $plain = $this->seedWork('A200', crew: 2, critical: false);
        $this->deploy($plain, 0);
        $crit = $this->seedWork('A300', crew: 2, critical: true);
        $this->deploy($crit, 0);

        $res = app(LaborAllocationService::class)->forSite('ALL');

        $this->assertSame('A300', $res['items'][0]['activityId'], '임계경로 인력부족이 맨 위에 와야 한다.');
        $this->assertSame('critical_short', $res['items'][0]['status']);
        $this->assertSame('short', $res['items'][1]['status']);
        $this->assertSame(1, $res['kpi']['criticalShort']);
    }

    public function test_kpi_aggregates_demand_and_assigned_across_activities(): void
    {
        $a = $this->seedWork('A400', crew: 4);
        $this->deploy($a, 3);
        $b = $this->seedWork('A410', crew: 2);
        $this->deploy($b, 2);

        $kpi = app(LaborAllocationService::class)->forSite('ALL')['kpi'];

        $this->assertSame(6, $kpi['demand']);   // 4 + 2 계획
        $this->assertSame(5, $kpi['assigned']); // 3 + 2 실투입
        $this->assertSame(48, $kpi['plannedMH']); // 6 * 8
        $this->assertSame(40, $kpi['actualMH']);  // 5 * 8
    }

    public function test_finished_and_future_work_is_excluded(): void
    {
        $done = $this->seedWork('A500', crew: 2);
        $done->update(['status' => '완료', 'progress' => 100]);

        $future = $this->seedWork('A510', crew: 2);
        $future->update([
            'planned_start' => now()->addDays(7)->toDateString(),
            'planned_end' => now()->addDays(9)->toDateString(),
        ]);

        $res = app(LaborAllocationService::class)->forSite('ALL');

        $this->assertCount(0, $res['items'], '완료·미래 작업은 오늘 배치 현황에 없어야 한다.');
    }
}
