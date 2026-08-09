<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\EmployeeAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 직원 등록과 로그인 계정 부여.
 *
 * 계정을 따로 만들게 하면 이름과 이메일을 두 번 치게 되고, 두 번 치면 오타가 난다.
 * 오타가 나면 그 사람은 자기 계정으로 못 들어오는데 아무도 이유를 모른다. 그래서
 * 직원 목록에서 바로 계정을 만들고, 이름·소속은 직원 정보를 그대로 쓴다.
 */
class DirectEmployeeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'NH', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'LGES-AZ', 'name' => 'LG AZ',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Super', 'email' => 'super@nahshonmep.com', 'password' => bcrypt('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function svc(): EmployeeAdminService
    {
        return app(EmployeeAdminService::class);
    }

    private function employee(array $extra = []): Employee
    {
        return Employee::create(array_merge([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'PM Kim',
            'email' => 'pmkim@nahshonmep.com',
            'employment_status' => 'active',
        ], $extra));
    }

    public function test_registering_an_employee_issues_a_number_automatically(): void
    {
        $this->actingAs($this->admin());

        $res = $this->svc()->save([
            'name' => 'New Worker',
            'email' => 'newworker@gmail.com',
            'companyId' => $this->company->id,
            'siteId' => $this->site->id,
            'employmentType' => Employee::TYPE_DIRECT,
            'status' => 'active',
        ]);

        $this->assertTrue($res['success']);
        $emp = Employee::where('email', 'newworker@gmail.com')->firstOrFail();
        $this->assertNotNull($emp->employee_number);
    }

    public function test_granting_an_account_reuses_the_employee_record(): void
    {
        $this->actingAs($this->admin());
        $emp = $this->employee();

        $res = $this->svc()->grantAccount($emp->id, ['role' => 'site_manager', 'scope' => 'site']);

        $this->assertTrue($res['success']);
        $user = User::where('employee_id', $emp->id)->firstOrFail();
        // 이름·소속은 직원 정보에서 그대로 온다 — 사람이 다시 치지 않는다.
        $this->assertSame('PM Kim', $user->name);
        $this->assertSame('pmkim@nahshonmep.com', $user->email);
        $this->assertSame('site_manager', $user->access_role);
        $this->assertSame('site', $user->access_scope);
        $this->assertSame($this->site->id, $user->allowed_site_id);
        $this->assertSame('active', $user->account_status);
    }

    public function test_worker_account_defaults_to_seeing_only_itself(): void
    {
        $this->actingAs($this->admin());
        $emp = $this->employee(['name' => 'Field Hand', 'email' => 'hand@gmail.com']);

        $this->assertTrue($this->svc()->grantAccount($emp->id, [])['success']);

        $user = User::where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('worker', $user->access_role);
        $this->assertSame('self', $user->access_scope);
    }

    public function test_employee_without_an_email_must_be_given_one(): void
    {
        // 이메일이 없으면 구글 로그인으로 들어올 방법이 없다.
        $this->actingAs($this->admin());
        $emp = $this->employee(['email' => null]);

        $res = $this->svc()->grantAccount($emp->id, []);

        $this->assertFalse($res['success']);
        $this->assertSame(0, User::where('employee_id', $emp->id)->count());
    }

    public function test_a_second_account_for_the_same_employee_is_refused(): void
    {
        $this->actingAs($this->admin());
        $emp = $this->employee();
        $this->svc()->grantAccount($emp->id, []);

        $res = $this->svc()->grantAccount($emp->id, ['email' => 'other@gmail.com']);

        $this->assertFalse($res['success']);
        $this->assertSame(1, User::where('employee_id', $emp->id)->count());
    }

    public function test_hr_manager_cannot_grant_admin_roles(): void
    {
        // 줄 수 없는 역할을 요청하면 조용히 낮추지 않고 거절한다.
        $this->actingAs(User::factory()->create([
            'access_role' => 'hr_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));
        $emp = $this->employee();

        $res = $this->svc()->grantAccount($emp->id, ['role' => 'super_admin', 'scope' => 'all_sites']);

        $this->assertFalse($res['success']);
        $this->assertSame(0, User::where('employee_id', $emp->id)->count());
    }

    public function test_the_list_shows_who_has_no_login_yet(): void
    {
        $this->actingAs($this->admin());
        $withAccount = $this->employee(['name' => '계정 있음', 'email' => 'has@gmail.com']);
        $without = $this->employee(['name' => '계정 없음', 'email' => 'none@gmail.com']);
        $this->svc()->grantAccount($withAccount->id, []);

        $rows = collect($this->svc()->list()['rows'])->keyBy('id');

        $this->assertTrue($rows[$withAccount->id]['hasAccount']);
        $this->assertFalse($rows[$without->id]['hasAccount']);
        $this->assertSame('worker', $rows[$withAccount->id]['accountRole']);
    }
}
