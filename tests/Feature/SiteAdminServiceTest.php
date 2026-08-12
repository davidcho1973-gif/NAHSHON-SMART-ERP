<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Admin\SiteAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 현장 · 프로젝트 — 모든 것이 여기서 시작한다.
 *
 * 등록은 쉽게, 삭제는 어렵게. sites 를 참조하는 테이블이 일곱 개이고 전부 연쇄 삭제라
 * 잘못 지우면 협력사 명단·QR·인원 마감이 함께 사라진다. 그래서 이 시험의 절반은
 * "못 지우게 막았는가" 다.
 */
class SiteAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function svc(): SiteAdminService
    {
        return app(SiteAdminService::class);
    }

    private function site(string $code = 'AZ-PHX'): Site
    {
        return Site::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $code.' Plant',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
    }

    public function test_worker_cannot_see_sites(): void
    {
        $this->actingAs($this->user('worker'));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_site_manager_can_view_but_not_create(): void
    {
        $this->actingAs($this->user('site_manager'));

        $res = $this->svc()->list();
        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage']);

        $this->assertFalse($this->svc()->saveSite([
            'code' => 'NEW', 'name' => 'New', 'timezone' => 'America/Phoenix',
        ])['success']);
    }

    public function test_admin_can_register_a_site(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveSite([
            'code' => 'tx-hou', 'name' => 'Houston Plant',
            'address' => '1 Main St', 'country' => 'US',
            'timezone' => 'America/Chicago', 'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $this->assertTrue($res['success']);
        $site = Site::findOrFail($res['id']);
        // 코드는 QR·문서에 찍히므로 대문자로 통일한다.
        $this->assertSame('TX-HOU', $site->code);
        $this->assertSame('America/Chicago', $site->timezone);
    }

    public function test_bad_timezone_is_refused(): void
    {
        // 타임존은 출퇴근 시각·일일 마감의 기준이다. 틀리면 하루가 밀린다.
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveSite(['code' => 'X1', 'name' => 'X', 'timezone' => 'Mars/Olympus']);

        $this->assertFalse($res['success']);
        $this->assertDatabaseMissing('sites', ['code' => 'X1']);
    }

    public function test_duplicate_site_code_is_refused(): void
    {
        $this->actingAs($this->user('admin'));
        $this->site('AZ-PHX');

        $res = $this->svc()->saveSite(['code' => 'az-phx', 'name' => 'Copy', 'timezone' => 'America/Phoenix']);

        $this->assertFalse($res['success']);
        $this->assertSame(1, Site::where('code', 'AZ-PHX')->count());
    }

    public function test_site_with_attendance_history_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $site = $this->site();
        $employee = Employee::create([
            'company_id' => $this->company->id, 'name' => '작업자', 'employment_status' => 'active',
        ]);
        AttendanceLog::create([
            'employee_id' => $employee->id,
            'site_id' => $site->id,
            'attendance_date' => '2026-08-01',
            'event_type' => 'clock_in',
            'event_at' => '2026-08-01 07:00:00',
            'status' => 'present',
        ]);

        $res = $this->svc()->deleteSite($site->id);

        $this->assertFalse($res['success']);
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        // 목록에서도 미리 잠가 둔다 — 눌러 보고 거절당하는 것보다 낫다.
        $row = collect($this->svc()->list()['sites'])->firstWhere('id', $site->id);
        $this->assertFalse($row['deletable']);
        $this->assertNotNull($row['deleteBlocker']);
    }

    public function test_empty_site_can_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $site = $this->site('EMPTY');

        $this->assertTrue($this->svc()->deleteSite($site->id)['success']);
        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_project_code_is_generated_when_left_blank(): void
    {
        $this->actingAs($this->user('admin'));
        $site = $this->site();

        $a = $this->svc()->saveProject([
            'name' => 'Equipment Setting', 'site_id' => $site->id,
            'construction_type' => array_key_first(Project::CONSTRUCTION_TYPE_OPTIONS),
            'state' => 'az',
        ]);
        $b = $this->svc()->saveProject([
            'name' => 'Piping', 'site_id' => $site->id,
            'construction_type' => array_key_first(Project::CONSTRUCTION_TYPE_OPTIONS),
            'state' => 'az',
        ]);

        $this->assertTrue($a['success']);
        $this->assertTrue($b['success']);
        // 사람이 규칙을 외울 필요 없이 현장·주·연도로 만들고, 겹치지 않게 번호를 올린다.
        $this->assertNotSame($a['projectCode'], $b['projectCode']);
        $this->assertStringStartsWith('AZPHX-AZ-', $a['projectCode']);
    }

    public function test_project_needs_a_site(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveProject([
            'name' => 'Orphan', 'site_id' => 0,
            'construction_type' => array_key_first(Project::CONSTRUCTION_TYPE_OPTIONS),
        ]);

        $this->assertFalse($res['success']);
    }

    public function test_project_code_cannot_change_once_wbs_exists(): void
    {
        // 공정·조달·사진이 전부 project_code 로 붙는다. 코드를 바꾸면 그 연결이 끊긴다.
        $this->actingAs($this->user('admin'));
        $site = $this->site();
        $project = Project::create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'project_code' => 'AZ-2026-001', 'name' => 'Live',
            'construction_type' => array_key_first(Project::CONSTRUCTION_TYPE_OPTIONS),
        ]);
        WbsItem::create([
            'project_code' => 'AZ-2026-001', 'level' => WbsItem::LEVEL_SUBTASK,
            'wbs_code' => '1.1.1', 'name' => '배관 설치',
        ]);

        $res = $this->svc()->saveProject([
            'id' => $project->id, 'name' => 'Live', 'site_id' => $site->id,
            'project_code' => 'AZ-2026-999',
            'construction_type' => array_key_first(Project::CONSTRUCTION_TYPE_OPTIONS),
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame('AZ-2026-001', $project->refresh()->project_code);
    }

    public function test_project_with_wbs_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $site = $this->site();
        $project = Project::create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'project_code' => 'AZ-2026-002', 'name' => 'Busy',
            'construction_type' => array_key_first(Project::CONSTRUCTION_TYPE_OPTIONS),
        ]);
        WbsItem::create([
            'project_code' => 'AZ-2026-002', 'level' => WbsItem::LEVEL_SUBTASK,
            'wbs_code' => '1.1.1', 'name' => '배관 설치',
        ]);

        $this->assertFalse($this->svc()->deleteProject($project->id)['success']);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_site_with_projects_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $site = $this->site();
        Project::create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'project_code' => 'AZ-2026-003', 'name' => 'Child',
            'construction_type' => array_key_first(Project::CONSTRUCTION_TYPE_OPTIONS),
        ]);

        $this->assertFalse($this->svc()->deleteSite($site->id)['success']);
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_list_counts_projects_and_crew_per_site(): void
    {
        $this->actingAs($this->user('admin'));
        $site = $this->site();
        Project::create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'project_code' => 'AZ-2026-004', 'name' => 'One',
            'construction_type' => array_key_first(Project::CONSTRUCTION_TYPE_OPTIONS),
        ]);
        Employee::create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'name' => '현장 인원', 'employment_status' => 'active',
        ]);
        Employee::create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'name' => '퇴사자', 'employment_status' => 'terminated',
        ]);

        $row = collect($this->svc()->list()['sites'])->firstWhere('id', $site->id);

        $this->assertSame(1, $row['projectCount']);
        // 퇴사자는 세지 않는다 — "지금 이 현장에 몇 명 있나" 를 보는 숫자다.
        $this->assertSame(1, $row['crewCount']);
    }
}
