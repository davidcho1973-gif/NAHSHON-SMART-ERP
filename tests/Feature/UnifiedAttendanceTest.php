<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Services\Admin\EmployeeAdminService;
use App\Support\QrPosters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UnifiedAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function worker(): array
    {
        $company = Company::create(['code' => 'OWN', 'name' => 'Own', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $site = Site::create(['code' => 'TEST', 'name' => 'Test', 'company_id' => $company->id, 'status' => 'active', 'timezone' => 'America/New_York']);
        $employee = Employee::create(['name' => 'Test Worker', 'phone' => '4805550123', 'company_id' => $company->id, 'site_id' => $site->id, 'employment_status' => 'active', 'employment_type' => Employee::TYPE_DIRECT]);
        $user = User::factory()->create(['employee_id' => $employee->id, 'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active', 'pin_hash' => Hash::make('2580')]);

        return [$site, $employee, $user];
    }

    public function test_one_qr_and_legacy_registration_open_the_same_gate(): void
    {
        [$site] = $this->worker();
        $this->get(route('worker-join.form', $site))->assertRedirect(route('gate.show', ['site' => $site, 'lang' => 'ko']));
        $this->get(route('gate.show', $site))->assertOk()->assertSee('name="full_name"', false)->assertSee('id="pin"', false);
        $this->assertSame(QrPosters::make($site, QrPosters::GATE)['url'], QrPosters::make($site, QrPosters::JOIN)['url']);
        $this->assertCount(1, QrPosters::many($site));
    }

    public function test_only_pin_verified_device_can_punch_and_client_cannot_choose_employee(): void
    {
        [$site,$employee] = $this->worker();
        $this->postJson(route('gate.remember', $site), ['employee_id' => $employee->id])->assertStatus(410);
        $this->postJson(route('gate.identify', $site), ['last4' => '0123'])->assertStatus(410);
        $legacy = WorkerDevice::issueFor($employee);
        $this->postJson(route('gate.punch', $site), ['employee_id' => $employee->id, 'device_token' => $legacy])->assertUnauthorized();
        $this->postJson(route('gate.login', $site), ['phone' => '+1 (480) 555-0123', 'pin' => '9998'])->assertUnprocessable();
        $token = $this->postJson(route('gate.login', $site), ['phone' => '+1 (480) 555-0123', 'pin' => '2580'])->assertOk()->json('device_token');
        $this->postJson(route('gate.me', $site), ['device_token' => $token])->assertJsonPath('recognized', true);
        $this->postJson(route('gate.punch', $site), ['employee_id' => 999999, 'device_token' => $token])->assertJsonPath('success', true);
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $employee->id, 'event_type' => 'clock_in']);
        $this->postJson(route('gate.punch', $site), ['device_token' => $token])->assertJsonPath('ignored', true);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_existing_number_cannot_create_another_worker_or_take_over(): void
    {
        [$site,$employee] = $this->worker();
        $this->postJson(route('worker-join.store', $site), ['full_name' => 'Different Name', 'phone' => '+1 4805550123'])->assertUnprocessable();
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('worker_devices', 0);
        $this->assertSame('Test Worker', $employee->fresh()->name);
    }

    public function test_wrong_site_disabled_and_admin_accounts_cannot_use_public_gate(): void
    {
        [$site,$employee,$user] = $this->worker();
        $user->update(['access_role' => 'admin']);
        $this->postJson(route('gate.login', $site), ['phone' => $employee->phone, 'pin' => '2580'])->assertUnprocessable();
        $token = WorkerDevice::issueFor($employee, verified: true);
        $this->postJson(route('gate.me', $site), ['device_token' => $token])->assertJsonPath('recognized', false);
        $user->update(['access_role' => 'worker', 'account_status' => 'disabled']);
        $this->postJson(route('gate.punch', $site), ['device_token' => $token])->assertUnauthorized();
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_hr_unconfirmed_employee_blocks_payroll_approval(): void
    {
        [$site,$employee] = $this->worker();
        $employee->update(['payload' => ['self_registered_pending_hr' => true]]);
        $run = PayrollRun::create(['code' => 'TEST-RUN', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'status' => 'calculated']);
        $run->payslips()->create(['employee_id' => $employee->id, 'status' => 'draft', 'snap_pay_type' => 'hourly', 'snap_base_rate' => 20]);
        try {
            $run->update(['status' => 'approved']);
            $this->fail('Unconfirmed worker was approved for payroll');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('인사 확인', $e->getMessage());
        }
        $this->assertSame('calculated', $run->fresh()->status);
        $employee->update(['payload' => ['self_registered_pending_hr' => false]]);
        $run->refresh()->update(['status' => 'approved']);
        $this->assertSame('approved', $run->fresh()->status);
    }

    public function test_new_worker_sets_pin_then_clocks_in_without_hr_blocking_attendance(): void
    {
        [$site] = $this->worker();
        $response = $this->post(route('worker-join.store', $site), ['full_name' => 'New Worker', 'phone' => '4805550198']);
        $response->assertOk();
        $setup = $response->viewData('pinSetupUrl');
        $this->assertNotEmpty($setup);
        $result = $this->postJson($setup, ['pin' => '2580'])->assertOk();
        $token = $result->json('attendance_device_token');
        $this->postJson(route('gate.me', $site), ['device_token' => $token])->assertJsonPath('recognized', true);
        $this->postJson(route('gate.punch', $site), ['device_token' => $token])->assertJsonPath('success', true);
        $employee = Employee::where('phone', '4805550198')->firstOrFail();
        $this->assertTrue((bool) data_get($employee->payload, 'self_registered_pending_hr'));
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $employee->id]);
        $this->postJson($setup, ['pin' => '4826'])->assertUnprocessable();
    }

    public function test_repeated_wrong_pin_locks_phone_recovery(): void
    {
        [$site, $employee, $user] = $this->worker();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('gate.login', $site), ['phone' => $employee->phone, 'pin' => '9870'])->assertUnprocessable();
        }
        $this->assertTrue($user->fresh()->pin_locked_until->isFuture());
        $this->postJson(route('gate.login', $site), ['phone' => $employee->phone, 'pin' => '2580'])->assertUnprocessable();
        $this->assertDatabaseCount('worker_devices', 0);
    }

    public function test_hr_connects_existing_worker_without_separate_account_creation(): void
    {
        [$site, $employee, $user] = $this->worker();
        $user->delete();
        $hr = User::factory()->create(['access_role' => 'hr_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($hr);
        $result = app(EmployeeAdminService::class)->issuePinLink($employee->id);
        $this->assertTrue($result['success']);
        $this->assertSame('worker', $employee->fresh()->user->access_role);
        $this->assertSame('self', $employee->fresh()->user->access_scope);
        $foreman = User::factory()->create(['access_role' => 'foreman', 'access_scope' => 'team', 'account_status' => 'active']);
        $this->actingAs($foreman);
        $this->assertFalse(app(EmployeeAdminService::class)->issuePinLink($employee->id)['success']);
    }
}
