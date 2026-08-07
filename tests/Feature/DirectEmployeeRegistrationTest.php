<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DirectEmployeeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Super', 'email' => 'super@nahshonmep.com', 'password' => bcrypt('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    public function test_employees_page_renders_with_grant_account_action(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageEmployees::class)->assertOk();
    }

    public function test_one_shot_register_creates_employee_and_worker_login(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageEmployees::class)
            ->mountAction('registerWithAccount')
            ->set('mountedActions.0.data', [
                'full_name' => 'New Worker',
                'email' => 'newworker@gmail.com',
                'email_confirm' => 'newworker@gmail.com',
                'account_type' => 'worker',
                'access_scope' => 'self',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $emp = Employee::where('email', 'newworker@gmail.com')->first();
        $this->assertNotNull($emp);
        $this->assertNotNull($emp->employee_number); // 사번 자동 생성
        $user = User::where('employee_id', $emp->id)->first();
        $this->assertNotNull($user);
        $this->assertSame('worker', $user->access_role);
        $this->assertSame('active', $user->account_status);
    }

    public function test_one_shot_register_rejects_mismatched_email_confirmation(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageEmployees::class)
            ->mountAction('registerWithAccount')
            ->set('mountedActions.0.data', [
                'full_name' => 'Typo Person',
                'email' => 'right@gmail.com',
                'email_confirm' => 'wrong@gmail.com',
                'account_type' => 'worker',
                'access_scope' => 'self',
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['email_confirm']);

        // 오타로 막혔으니 아무 것도 생성되지 않는다.
        $this->assertSame(0, Employee::where('email', 'right@gmail.com')->count());
    }

    public function test_grant_account_action_creates_admin_login_for_employee(): void
    {
        $this->actingAs($this->admin());

        $company = Company::create(['code' => 'NH', 'name' => 'NAHSHON', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'LGES-AZ', 'name' => 'LG AZ', 'status' => 'active']);
        $emp = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'name' => 'PM Kim', 'email' => 'pmkim@nahshonmep.com', 'employment_status' => 'active',
        ]);

        Livewire::test(ManageEmployees::class)
            ->mountTableAction('grantAccount', $emp)
            ->setTableActionData(['account_type' => 'admin', 'admin_role' => 'site_manager', 'access_scope' => 'site'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $user = User::where('employee_id', $emp->id)->first();
        $this->assertNotNull($user);
        $this->assertSame('site_manager', $user->access_role);
        $this->assertSame('site', $user->access_scope);
        $this->assertSame('active', $user->account_status);
    }
}
