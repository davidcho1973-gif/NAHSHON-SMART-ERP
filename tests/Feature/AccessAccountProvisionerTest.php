<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Hr\AccessAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessAccountProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'NH', 'name' => 'NAHSHON', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'LGES-AZ', 'name' => 'LG AZ', 'status' => 'active']);
    }

    public function test_grants_worker_login_account_linked_to_employee(): void
    {
        $emp = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '홍길동', 'email' => 'gildong@nahshonmep.com', 'employment_status' => 'active',
        ]);

        $user = app(AccessAccountProvisioner::class)->grant($emp, 'worker');

        $this->assertSame($emp->id, $user->employee_id);
        $this->assertSame('worker', $user->access_role);
        $this->assertSame('active', $user->account_status);
        $this->assertSame('gildong@nahshonmep.com', $user->email);
        $this->assertSame($this->site->id, $user->allowed_site_id);
        $this->assertNotNull($user->password); // 구글 전용이라도 자리값은 채워짐
        $this->assertTrue($emp->fresh()->user->is($user));
    }

    public function test_grants_admin_role_and_is_idempotent_on_re_grant(): void
    {
        $emp = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => 'PM Kim', 'email' => 'pm@nahshonmep.com', 'employment_status' => 'active',
        ]);

        $svc = app(AccessAccountProvisioner::class);
        $first = $svc->grant($emp, 'worker');
        $second = $svc->grant($emp, 'site_manager', 'site');

        // 재부여 시 새 계정이 생기지 않고 같은 계정의 역할만 갱신된다.
        $this->assertSame($first->id, $second->id);
        $this->assertSame('site_manager', $second->access_role);
        $this->assertSame('site', $second->access_scope);
        $this->assertSame(1, User::where('employee_id', $emp->id)->count());
    }

    public function test_links_existing_user_by_email_instead_of_duplicating(): void
    {
        $existing = User::factory()->create(['email' => 'dup@nahshonmep.com', 'access_role' => 'viewer']);
        $emp = Employee::create([
            'company_id' => $this->company->id,
            'name' => 'Dup', 'email' => 'dup@nahshonmep.com', 'employment_status' => 'active',
        ]);

        $user = app(AccessAccountProvisioner::class)->grant($emp, 'worker');

        $this->assertSame($existing->id, $user->id);
        $this->assertSame($emp->id, $user->employee_id);
        $this->assertSame(1, User::where('email', 'dup@nahshonmep.com')->count());
    }

    public function test_requires_email(): void
    {
        $emp = Employee::create([
            'company_id' => $this->company->id, 'name' => 'NoEmail', 'employment_status' => 'active',
        ]);

        $this->expectException(\RuntimeException::class);
        app(AccessAccountProvisioner::class)->grant($emp, 'worker');
    }
}
