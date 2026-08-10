<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\PayProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 슈퍼관리자가 "이 사람 화면" 을 그대로 들여다보는 기능.
 *
 * 왜 필요한가 — 관리자 계정에는 직원 기록이 안 붙어 있어서, 만들어 놓은 화면을 정작
 * 만든 사람이 못 본다. 확인하려고 자기 계정을 아무 직원에게 붙이면 그 직원의 진짜
 * 기록이 섞인다.
 *
 * 무엇을 지켜야 하는가 — 보기 전용이어야 한다. 화면을 둘러보다 누른 버튼 하나가 남의
 * 근무시간이 되면, 나중에 아무도 그게 본인이 찍은 것인지 구별할 수 없다. 임금 기록이다.
 */
class WorkerScreenViewAsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $site = Site::create([
            'company_id' => $company->id, 'code' => 'LG_ESS_PH', 'name' => 'LG ESS Phoenix',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->worker = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'name' => 'Cristian rosas', 'employment_status' => 'active', 'role' => 'General',
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    public function test_a_super_admin_sees_the_workers_real_screen(): void
    {
        $res = $this->actingAs($this->superAdmin())
            ->getJson(route('attendance-app.home', ['as' => $this->worker->id]));

        $res->assertOk();
        $res->assertJsonPath('employee.name', 'Cristian rosas');
        $res->assertJsonPath('viewingAs.name', 'Cristian rosas');
    }

    public function test_the_screen_says_out_loud_whose_screen_it_is(): void
    {
        // 남의 화면을 보고 있다는 사실이 한순간도 숨겨지면 안 된다.
        $this->actingAs($this->superAdmin())
            ->get(route('attendance-app.index', ['as' => $this->worker->id]))
            ->assertOk()
            ->assertSee('Cristian rosas 화면')
            ->assertSee('기록은 남지 않습니다');
    }

    public function test_punching_while_looking_at_someone_else_is_refused(): void
    {
        // 이게 이 기능에서 가장 중요한 한 줄이다.
        $this->actingAs($this->superAdmin())
            ->postJson(route('attendance-app.punch', ['as' => $this->worker->id]), ['direction' => 'in'])
            ->assertStatus(403);

        $this->assertSame(0, AttendanceLog::query()->where('employee_id', $this->worker->id)->count());
    }

    public function test_the_screen_does_not_send_gps_while_looking(): void
    {
        // 관리자가 사무실에서 열어 본 것이 그 작업자의 "현장 재실" 로 기록되면
        // 자동 퇴근 시각이 통째로 틀어진다.
        $html = $this->actingAs($this->superAdmin())
            ->get(route('attendance-app.index', ['as' => $this->worker->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('if (AS) return;', $html);
    }

    public function test_everyone_who_already_sees_pay_can_use_it(): void
    {
        // 급여를 이미 볼 수 있는 역할에는 새로 드러나는 것이 없다. 여기만 좁혀 두면
        // 정작 확인해야 할 사람이 못 쓴다.
        foreach (PayProfileService::VIEW_ROLES as $role) {
            $user = User::factory()->create([
                'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
            ]);

            $this->actingAs($user)
                ->getJson(route('attendance-app.home', ['as' => $this->worker->id]))
                ->assertOk()
                ->assertJsonPath('employee.name', 'Cristian rosas');
        }
    }

    public function test_a_role_that_cannot_see_pay_cannot_use_it(): void
    {
        // 현장소장은 출퇴근은 봐도 시급은 못 본다. 이 화면에는 시급이 나온다.
        $this->assertNotContains('site_manager', PayProfileService::VIEW_ROLES);

        $siteManager = User::factory()->create([
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $this->actingAs($siteManager)
            ->getJson(route('attendance-app.home', ['as' => $this->worker->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_employee');
    }

    public function test_the_button_appears_for_the_same_roles(): void
    {
        // 화면의 버튼과 서버의 판단이 어긋나면 "버튼이 없다" 또는 "눌러도 안 된다" 가 된다.
        $admin = User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $this->actingAs($admin);

        $this->assertTrue(app(\App\Services\Admin\EmployeeAdminService::class)->options()['canViewAsWorker']);
    }

    public function test_a_worker_cannot_look_at_another_worker(): void
    {
        $other = Employee::create([
            'company_id' => $this->worker->company_id, 'site_id' => $this->worker->site_id,
            'name' => 'Dhairo Carmona', 'employment_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_id' => $other->id, 'access_role' => 'worker',
            'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAs($user)
            ->getJson(route('attendance-app.home', ['as' => $this->worker->id]))
            ->assertOk()
            // 자기 화면이 나온다 — ?as= 가 조용히 무시된다.
            ->assertJsonPath('employee.name', 'Dhairo Carmona')
            ->assertJsonPath('viewingAs', null);
    }

    public function test_a_super_admin_still_punches_normally_on_his_own_screen(): void
    {
        // ?as= 없이 열면 평소대로다. 이 기능이 자기 출퇴근을 막으면 안 된다.
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson(route('attendance-app.punch'), ['direction' => 'in'])
            ->assertStatus(422);   // 본인에게 직원이 연결 안 된 것 — 403(보는 중)이 아니다
    }
}
