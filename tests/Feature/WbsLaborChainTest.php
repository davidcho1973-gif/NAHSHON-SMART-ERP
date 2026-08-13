<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use App\Models\Project;
use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Models\WbsItem;
use App\Services\Wbs\WbsLaborService;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 공정 → 안전 → 인원 → 급여 사슬.
 *
 * 조인 키는 (employee_id, 날짜): 안전카드가 "그날 그 작업에 누가 투입됐나"를,
 * 타임시트가 "그 사람이 그날 몇 시간 일했나"를 안다.
 */
class WbsLaborChainTest extends TestCase
{
    use RefreshDatabase;

    private function seedWork(float $crew = 2, float $manhours = 48): WbsItem
    {
        Project::create(['project_code' => 'LAB-01', 'name' => '노무 사슬', 'construction_type' => 'equipment_setting']);

        $stage = WbsItem::create(['project_code' => 'LAB-01', 'level' => 'stage', 'wbs_code' => 'LAB-01-S-1', 'node_no' => '1', 'name' => '설치']);
        $task = WbsItem::create(['project_code' => 'LAB-01', 'parent_id' => $stage->id, 'level' => 'task', 'wbs_code' => 'LAB-01-T-1.1', 'node_no' => '1.1', 'name' => 'ELEC']);

        return WbsItem::create([
            'project_code' => 'LAB-01', 'parent_id' => $task->id, 'level' => 'subtask',
            'wbs_code' => 'LAB-01-W-A100', 'activity_id' => 'A100', 'node_no' => '1.1.1',
            'name' => '천장 전기 배관', 'company' => 'ELEC', 'crew_size' => $crew, 'manhours' => $manhours,
            'days' => 3, 'status' => '검수완료', 'progress' => 0,
            'crew_roles' => [['count' => $crew, 'role' => 'electrician', 'external' => false, 'note' => null]],
            'planned_start' => now()->toDateString(), 'planned_end' => now()->addDays(2)->toDateString(),
        ]);
    }

    private function makeEmployee(string $name): Employee
    {
        $company = Company::firstOrCreate(['code' => 'ABC ENG'], ['name' => 'ABC ENG']);

        return Employee::create([
            'company_id' => $company->id, 'name' => $name,
            'employee_number' => 'E-' . fake()->unique()->numberBetween(1000, 9999),
            'employment_status' => '재직',
        ]);
    }

    public function test_assigning_an_employee_records_the_name_for_the_signature_record(): void
    {
        $item = $this->seedWork();
        app(WbsService::class)->createSafetyCard($item->wbs_code);
        $sig = SafetyWorkSignature::first();
        $kim = $this->makeEmployee('김철수');

        $res = app(WbsLaborService::class)->assignEmployee($sig->id, $kim->id);

        $this->assertTrue($res['success']);
        $sig->refresh();
        $this->assertSame($kim->id, $sig->employee_id);
        // 이름은 서명 당시 기록으로 복사된다 — 직원이 개명/삭제돼도 서명 기록은 변하면 안 된다.
        $this->assertSame('김철수', $sig->name);
    }

    public function test_signature_record_survives_employee_deletion(): void
    {
        $item = $this->seedWork();
        app(WbsService::class)->createSafetyCard($item->wbs_code);
        $sig = SafetyWorkSignature::first();
        $kim = $this->makeEmployee('김철수');
        app(WbsLaborService::class)->assignEmployee($sig->id, $kim->id);

        $kim->delete();

        $sig->refresh();
        $this->assertNull($sig->employee_id);
        $this->assertSame('김철수', $sig->name, '직원이 지워져도 서명한 이름은 법적 기록으로 남아야 한다.');
    }

    public function test_signed_slot_cannot_be_reassigned(): void
    {
        $item = $this->seedWork();
        app(WbsService::class)->createSafetyCard($item->wbs_code);
        $sig = SafetyWorkSignature::first();
        $kim = $this->makeEmployee('김철수');
        $lee = $this->makeEmployee('이민준');

        app(WbsLaborService::class)->assignEmployee($sig->id, $kim->id);
        $sig->update(['signed' => true, 'signed_at' => now()]);

        $res = app(WbsLaborService::class)->assignEmployee($sig->id, $lee->id);

        $this->assertFalse($res['success']);
        $this->assertSame($kim->id, $sig->fresh()->employee_id);
    }

    public function test_actual_labour_hours_flow_from_timesheets_into_the_wbs_item(): void
    {
        $item = $this->seedWork(crew: 2, manhours: 48);
        $date = now()->toDateString();
        app(WbsService::class)->createSafetyCard($item->wbs_code, $date);

        $kim = $this->makeEmployee('김철수');
        $lee = $this->makeEmployee('이민준');
        $sigs = SafetyWorkSignature::orderBy('id')->get();

        // 두 사람 배정 + 서명
        foreach ([[$sigs[0], $kim], [$sigs[1], $lee]] as [$sig, $emp]) {
            app(WbsLaborService::class)->assignEmployee($sig->id, $emp->id);
            $sig->update(['signed' => true, 'signed_at' => now()]);
        }

        // 출퇴근 → 타임시트 (분 단위). 김 8시간, 이 9.5시간.
        PayrollTimesheet::create(['employee_id' => $kim->id, 'work_date' => $date, 'regular_minutes' => 480, 'overtime_minutes' => 0, 'payable_minutes' => 480, 'status' => 'approved', 'source' => 'attendance_logs']);
        PayrollTimesheet::create(['employee_id' => $lee->id, 'work_date' => $date, 'regular_minutes' => 480, 'overtime_minutes' => 90, 'payable_minutes' => 570, 'status' => 'approved', 'source' => 'attendance_logs']);

        $labor = app(WbsLaborService::class)->laborFor($item->wbs_code);

        $this->assertTrue($labor['success']);
        $this->assertSame(17.5, $labor['actualHours']); // 8 + 9.5
        $this->assertSame(48.0, $labor['plannedHours']);
        $this->assertSame(36, $labor['consumedPct']); // 17.5 / 48
        $this->assertCount(1, $labor['days']);
        $this->assertSame(2, $labor['days'][0]['signedCount']);
        $this->assertSame(8.0, $labor['days'][0]['people'][0]['hours']);
    }

    public function test_missing_attendance_reads_as_unknown_not_zero(): void
    {
        // 출퇴근 기록이 아직 없는 것과 "0시간 일했다"는 완전히 다르다.
        $item = $this->seedWork();
        app(WbsService::class)->createSafetyCard($item->wbs_code);
        $sig = SafetyWorkSignature::first();
        $kim = $this->makeEmployee('김철수');
        app(WbsLaborService::class)->assignEmployee($sig->id, $kim->id);
        $sig->update(['signed' => true, 'signed_at' => now()]);

        $labor = app(WbsLaborService::class)->laborFor($item->wbs_code);

        $this->assertNull($labor['days'][0]['people'][0]['hours']);
        $this->assertSame(0.0, $labor['actualHours']);
    }

    public function test_unsigned_slots_do_not_count_as_deployed_labour(): void
    {
        // 배정만 하고 서명 안 한 사람은 실제 투입이 아니다.
        $item = $this->seedWork();
        $date = now()->toDateString();
        app(WbsService::class)->createSafetyCard($item->wbs_code, $date);
        $sig = SafetyWorkSignature::first();
        $kim = $this->makeEmployee('김철수');
        app(WbsLaborService::class)->assignEmployee($sig->id, $kim->id);

        PayrollTimesheet::create(['employee_id' => $kim->id, 'work_date' => $date, 'regular_minutes' => 480, 'overtime_minutes' => 0, 'payable_minutes' => 480, 'status' => 'approved', 'source' => 'attendance_logs']);

        $labor = app(WbsLaborService::class)->laborFor($item->wbs_code);

        $this->assertSame(0, $labor['days'][0]['signedCount']);
        $this->assertSame(0.0, $labor['actualHours']);
    }

    public function test_today_work_lists_only_work_scheduled_for_today(): void
    {
        $item = $this->seedWork();
        // 다음 주 작업은 오늘 목록에 없어야 한다.
        WbsItem::create([
            'project_code' => 'LAB-01', 'parent_id' => $item->parent_id, 'level' => 'subtask',
            'wbs_code' => 'LAB-01-W-A200', 'activity_id' => 'A200', 'node_no' => '1.1.2',
            'name' => '다음주 작업', 'crew_size' => 2, 'status' => '검수완료',
            'planned_start' => now()->addDays(7)->toDateString(), 'planned_end' => now()->addDays(9)->toDateString(),
        ]);

        $today = app(WbsService::class)->todayWork('LAB-01');

        $this->assertSame(1, $today['total']);
        $this->assertSame('A100', $today['items'][0]['activity_id']);
        $this->assertSame(now()->toDateString(), $today['date']);
    }

    public function test_today_work_tells_the_field_what_to_do_next(): void
    {
        $item = $this->seedWork();

        // 1) 카드 없음 → 안전계획부터
        $this->assertSame('안전계획', app(WbsService::class)->todayWork('LAB-01')['items'][0]['nextAction']);
        $this->assertSame(1, app(WbsService::class)->todayWork('LAB-01')['needsPlanCount']);

        // 2) 카드 생성 → TBM 차례
        app(WbsService::class)->createSafetyCard($item->wbs_code);
        $this->assertSame('TBM', app(WbsService::class)->todayWork('LAB-01')['items'][0]['nextAction']);

        // 3) TBM 완료 → 시작 가능
        SafetyWorkItem::where('wbs_code', $item->wbs_code)->update(['tbm_status' => '완료']);
        $today = app(WbsService::class)->todayWork('LAB-01');
        $this->assertSame('시작', $today['items'][0]['nextAction']);
        $this->assertFalse($today['items'][0]['blocked']);
    }

    public function test_today_work_puts_critical_path_first(): void
    {
        $item = $this->seedWork();
        $item->update(['is_critical' => false, 'float_days' => 10]);
        WbsItem::create([
            'project_code' => 'LAB-01', 'parent_id' => $item->parent_id, 'level' => 'subtask',
            'wbs_code' => 'LAB-01-W-A300', 'activity_id' => 'A300', 'node_no' => '1.1.3',
            'name' => '임계 작업', 'crew_size' => 2, 'status' => '검수완료', 'is_critical' => true, 'float_days' => 0,
            'planned_start' => now()->toDateString(), 'planned_end' => now()->addDay()->toDateString(),
        ]);

        $today = app(WbsService::class)->todayWork('LAB-01');

        $this->assertSame('A300', $today['items'][0]['activity_id'], '임계경로 작업이 맨 위에 와야 한다.');
        $this->assertSame(1, $today['criticalCount']);
    }

    public function test_today_work_excludes_finished_work(): void
    {
        $item = $this->seedWork();
        $item->update(['status' => '완료', 'progress' => 100]);

        $this->assertSame(0, app(WbsService::class)->todayWork('LAB-01')['total']);
    }
}
