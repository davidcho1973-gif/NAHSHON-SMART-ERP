<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeJoinTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'OWN', 'name' => 'Own company', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $this->site = Site::create(['code' => 'SITE', 'name' => 'Site', 'company_id' => $company->id, 'status' => 'active', 'timezone' => 'America/New_York']);
        $this->data = ['full_name' => 'New Employee', 'company_id' => $company->id, 'role' => 'Piping', 'position' => 'worker', 'phone' => '+14805550111'];
    }

    public function test_common_registration_requires_an_explicit_valid_position(): void
    {
        foreach (['', 'super_admin'] as $position) {
            $this->post(route('employee-join.store', $this->site), array_replace($this->data, ['position' => $position]))
                ->assertSessionHasErrors('position');
        }
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_worker_and_manager_can_use_one_link_without_issuing_accounts(): void
    {
        $this->get(route('employee-join.form', $this->site))->assertOk()->assertSee('직원 등록');
        $this->post(route('employee-join.store', $this->site), $this->data)->assertOk();
        $this->post(route('employee-join.store', $this->site), array_replace($this->data, [
            'full_name' => 'Site Foreman', 'position' => 'foreman', 'email' => 'foreman@example.com', 'phone' => '+14805550112',
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'approved_by_id' => 1,
        ]))->assertOk();
        $this->assertDatabaseCount('employees', 2);
        $this->assertSame(Employee::TYPE_DIRECT, Employee::where('name', 'New Employee')->sole()->employment_type);
        $this->assertSame(Employee::TYPE_STAFF, Employee::where('name', 'Site Foreman')->sole()->employment_type);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(1, UnifiedAlert::where('event_type', 'manager_account_pending')->count());
    }

    public function test_every_supervisory_position_requires_email_even_on_old_worker_link(): void
    {
        foreach (['employee-join.store', 'worker-join.store', 'manager-join.store'] as $route) {
            foreach (Employee::SUPERVISORY_POSITIONS as $position) {
                $this->post(route($route, $this->site), array_replace($this->data, ['position' => $position]))
                    ->assertSessionHasErrors('email');
            }
        }
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_unclassified_company_does_not_require_a_worker_pay_type_for_management(): void
    {
        $this->post(route('employee-join.store', $this->site), array_replace($this->data, [
            'company_id' => null, 'company_name' => 'New contractor', 'position' => 'office', 'email' => 'office@example.com',
        ]))->assertOk();
        $this->assertSame(Employee::TYPE_STAFF, Employee::sole()->employment_type);
    }

    public function test_registration_cannot_take_over_an_existing_administrator_email(): void
    {
        $boss = User::factory()->create(['email' => 'boss@example.com', 'access_role' => 'super_admin', 'employee_id' => null]);
        $before = $boss->fresh()->getAttributes();
        $this->post(route('employee-join.store', $this->site), array_replace($this->data, [
            'position' => 'superintendent', 'email' => $boss->email,
        ]))->assertOk();
        $this->assertSame($before, $boss->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_returning_employee_position_and_pending_access_alert_stay_consistent(): void
    {
        $this->post(route('employee-join.store', $this->site), $this->data)->assertOk();
        $this->post(route('employee-join.store', $this->site), array_replace($this->data, [
            'position' => 'engineer', 'email' => 'engineer@example.com',
        ]))->assertOk();
        $this->assertDatabaseCount('employees', 1);
        $this->assertSame('engineer', Employee::sole()->position);
        $this->assertSame(Employee::TYPE_STAFF, Employee::sole()->employment_type);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(1, UnifiedAlert::where('event_type', 'manager_account_pending')->count());
    }

    public function test_site_selector_uses_existing_names_and_does_not_create_regional_sites(): void
    {
        $other = Site::create(['code' => 'EXISTING-AZ', 'name' => 'Existing battery project', 'status' => 'active']);
        $closed = Site::create(['code' => 'CLOSED', 'name' => 'Closed project', 'status' => 'inactive']);
        $this->get(route('employee-join.entry'))->assertOk()->assertSee($this->site->name)->assertSee($other->name)
            ->assertSee('Global')->assertDontSee($closed->name);
        $other->update(['name' => 'Updated project name']);
        $this->get(route('employee-join.form', $this->site))->assertOk()->assertSee('Updated project name');
        $this->assertDatabaseCount('sites', 3);

        $this->post(route('employee-join.entry-store'), $this->data + ['registration_site' => (string) $other->id])->assertOk();
        $this->assertSame($other->id, Employee::sole()->site_id);
    }

    public function test_site_selection_rejects_missing_nonexistent_and_inactive_sites(): void
    {
        $closed = Site::create(['code' => 'CLOSED', 'name' => 'Closed', 'status' => 'inactive']);
        foreach (['', '99999', (string) $closed->id] as $selection) {
            $this->post(route('employee-join.entry-store'), $this->data + ['registration_site' => $selection])
                ->assertSessionHasErrors('registration_site');
        }
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_global_registration_records_scope_without_issuing_access_or_a_fake_site(): void
    {
        $this->post(route('employee-join.entry-store'), array_replace($this->data, [
            'registration_site' => 'global', 'position' => 'general_manager', 'email' => 'central@example.com',
            'access_role' => 'super_admin', 'access_scope' => 'all_sites',
        ]))->assertOk()->assertSee('Global')->assertDontSee('/gate//')->assertDontSee('/w9/');
        $employee = Employee::sole();
        $this->assertNull($employee->site_id);
        $this->assertSame('global', data_get($employee->payload, 'registration_scope'));
        $this->assertSame(Employee::TYPE_STAFF, $employee->employment_type);
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('worker_devices', 0);
        $this->assertNull(UnifiedAlert::where('event_type', 'manager_account_pending')->sole()->site_id);
    }

    public function test_global_rejects_workers_and_does_not_move_existing_employees(): void
    {
        $this->post(route('employee-join.entry-store'), $this->data + ['registration_site' => 'global'])
            ->assertSessionHasErrors('position');
        $this->post(route('employee-join.store', $this->site), $this->data)->assertOk();
        $employee = Employee::sole();
        $before = $employee->getAttributes();
        $this->post(route('employee-join.entry-store'), array_replace($this->data, [
            'registration_site' => 'global', 'position' => 'general_manager', 'email' => 'central@example.com',
        ]))->assertSessionHasErrors('email');
        $this->assertSame($before, $employee->fresh()->getAttributes());
        $this->assertDatabaseCount('employees', 1);
    }
}
