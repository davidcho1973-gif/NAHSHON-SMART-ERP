<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 현장 운영 대시보드 — 실데이터 집계(출역·오늘작업·조달지연·서류만료) + 리스크 트리아지.
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_aggregates_real_data_into_ops_and_risk(): void
    {
        $today = Carbon::today()->toDateString();
        $company = Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active']);
        $site = Site::create(['code' => 'ST1', 'name' => '현장1', 'timezone' => 'America/Phoenix', 'status' => 'active']);

        // 출역: 활성 직원 2명 중 1명 clock_in.
        $e1 = Employee::create(['company_id' => $company->id, 'site_id' => $site->id, 'first_name' => 'A', 'last_name' => 'K', 'email' => 'a@x.com', 'employment_status' => 'active']);
        Employee::create(['company_id' => $company->id, 'site_id' => $site->id, 'first_name' => 'B', 'last_name' => 'K', 'email' => 'b@x.com', 'employment_status' => 'active']);
        AttendanceLog::create(['employee_id' => $e1->id, 'company_id' => $company->id, 'site_id' => $site->id, 'attendance_date' => $today, 'event_type' => 'clock_in', 'event_at' => now(), 'source' => 'test']);

        // 오늘 작업(노무): 인원 있는 subtask, 오늘 창.
        WbsItem::create(['project_code' => 'P1', 'level' => 'subtask', 'wbs_code' => 'P1-A010', 'activity_id' => 'A010',
            'name' => '전기 배선', 'trade' => 'ELEC', 'status' => '진행중', 'crew_size' => 3, 'site_id' => $site->id,
            'planned_start' => $today, 'planned_end' => $today]);

        // 조달 임계 지연: 조달성 subtask(임계경로) + ETA가 납기보다 늦음.
        $proc = WbsItem::create(['project_code' => 'P1', 'level' => 'subtask', 'wbs_code' => 'P1-A080', 'activity_id' => 'A080',
            'name' => '기계설비 조달', 'trade' => 'MECH', 'status' => '진행중', 'crew_size' => 0, 'is_critical' => true, 'site_id' => $site->id,
            'planned_end' => Carbon::today()->addDays(5)->toDateString()]);
        ProcurementItem::create(['project_code' => 'P1', 'site_id' => $site->id, 'wbs_code' => 'P1-A080', 'wbs_item_id' => $proc->id,
            'status' => '선적중', 'eta' => Carbon::today()->addDays(12)->toDateString()]);

        // 서류 만료 D-3.
        IntegratedDocument::create(['title' => 'COI 대한설비', 'folder_code' => '08', 'site_id' => $site->id, 'status' => 'confirmed',
            'expires_on' => Carbon::today()->addDays(3)->toDateString()]);

        $d = app(DashboardService::class)->overview('ST1');

        $this->assertTrue($d['success']);
        // 출역
        $this->assertSame(1, $d['ops']['attendance']['present']);
        $this->assertSame(2, $d['ops']['attendance']['planned']);
        $this->assertSame(50, $d['ops']['attendance']['rate']);
        // 오늘 작업(조달은 제외되어 노무 1건)
        $this->assertSame(1, $d['ops']['tasks']['total']);
        // 리스크: 조달 임계지연 + 서류 D-3 → 즉시조치 2건 이상
        $this->assertGreaterThanOrEqual(2, $d['risk']['counts']['critical']);
        $modules = array_column($d['risk']['critical'], 'module');
        $this->assertContains('조달', $modules);
        $this->assertContains('서류', $modules);
        $this->assertNotEmpty($d['aiBrief']);
    }

    public function test_empty_scope_returns_all_normal(): void
    {
        $d = app(DashboardService::class)->overview('ALL');
        $this->assertTrue($d['success']);
        $this->assertSame(0, $d['risk']['counts']['critical']);
        $this->assertStringContainsString('정상', $d['aiBrief']);
    }
}
