<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\UserAccesses\UserAccessResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingPayrollLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'NAHSHON MEP', 'code' => 'NMEP']);
        $this->site = Site::create(['name' => 'LG AZ Plant', 'code' => 'LGES-AZ']);
    }

    public function test_creating_an_employee_auto_provisions_a_payroll_profile(): void
    {
        $employee = Employee::create([
            'name' => 'Carlos Rivera',
            'employee_number' => 'EMP-7001',
            'role' => 'Pipefitter',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'active',
        ]);

        $profile = EmployeePayrollProfile::where('employee_id', $employee->id)->first();

        $this->assertNotNull($profile, 'A wage profile should be created with the employee.');
        $this->assertSame('hourly', $profile->pay_type);
        $this->assertSame('0.0000', (string) $profile->base_rate);
        $this->assertSame('Pipefitter', $profile->trade);
        $this->assertSame($this->site->id, $profile->site_id);
        $this->assertFalse((bool) $profile->is_dispatched);
    }

    public function test_korean_nationality_is_inferred_as_dispatched(): void
    {
        $employee = Employee::create([
            'name' => 'Min Lee',
            'employee_number' => 'EMP-7002',
            'role' => 'Welder',
            'nationality' => 'Korea',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'active',
        ]);

        $profile = EmployeePayrollProfile::where('employee_id', $employee->id)->first();
        $this->assertTrue((bool) $profile->is_dispatched);
    }

    public function test_profile_is_not_duplicated_for_one_employee(): void
    {
        $employee = Employee::create([
            'name' => 'Solo',
            'employee_number' => 'EMP-7003',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'active',
        ]);

        $this->assertSame(1, EmployeePayrollProfile::where('employee_id', $employee->id)->count());
    }

    public function test_role_gates_restrict_who_can_manage_employees(): void
    {
        $siteManager = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'all_sites']);
        $this->actingAs($siteManager);
        $this->assertTrue(EmployeeResource::canViewAny());
        $this->assertFalse(EmployeeResource::canCreate());   // site_manager cannot create employees
        $this->assertFalse(EmployeeResource::canDelete(null));

        $hr = User::factory()->create(['access_role' => 'hr_manager', 'access_scope' => 'all_sites']);
        $this->actingAs($hr);
        $this->assertTrue(EmployeeResource::canCreate());
    }

    public function test_employee_query_is_scoped_to_allowed_site(): void
    {
        $otherSite = Site::create(['name' => 'SK TN Plant', 'code' => 'SK-TN']);

        $mine = Employee::create(['name' => 'A', 'employee_number' => 'EMP-A', 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'employment_status' => 'active']);
        Employee::create(['name' => 'B', 'employee_number' => 'EMP-B', 'company_id' => $this->company->id, 'site_id' => $otherSite->id, 'employment_status' => 'active']);

        $scoped = User::factory()->create([
            'access_role' => 'site_manager',
            'access_scope' => 'site',
            'allowed_site_id' => $this->site->id,
        ]);
        $this->actingAs($scoped);

        $ids = EmployeeResource::getEloquentQuery()->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }

    public function test_admin_sees_all_employees(): void
    {
        Employee::create(['name' => 'A', 'employee_number' => 'EMP-A', 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'employment_status' => 'active']);
        // Admin is scoped to "site" yet has no allowed site — must still bypass and see all.
        $admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'site', 'allowed_site_id' => null]);
        $this->actingAs($admin);

        $this->assertSame(1, EmployeeResource::getEloquentQuery()->count());
    }

    public function test_non_super_admin_cannot_grant_admin_roles(): void
    {
        $hr = User::factory()->create(['access_role' => 'hr_manager']);
        $this->actingAs($hr);
        $roles = UserAccessResource::assignableRoles();
        $this->assertArrayNotHasKey('super_admin', $roles);
        $this->assertArrayNotHasKey('admin', $roles);
        $this->assertArrayHasKey('worker', $roles);

        $super = User::factory()->create(['access_role' => 'super_admin']);
        $this->actingAs($super);
        $this->assertArrayHasKey('super_admin', UserAccessResource::assignableRoles());
    }
}
