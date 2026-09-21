<?php

namespace Tests\Feature;

use App\Models\AuthSetupToken;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkerEnrollment;
use App\Services\Admin\EmployeeAdminService;
use App\Services\Admin\UserAccessService;
use App\Services\Auth\PinAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkerEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'ENROLL', 'name' => 'Enrollment Co', 'status' => 'active']);
        $site = Site::create(['code' => 'ENROLL', 'name' => 'Enrollment Site', 'status' => 'active']);
        $this->team = Team::create(['code' => 'ENROLL', 'name' => 'Pipe Team', 'company_id' => $company->id, 'site_id' => $site->id, 'status' => 'active', 'trade_type' => 'Plumber']);
        $this->admin = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active', 'access_scope' => 'all_sites']);
    }

    private function url(): string
    {
        return route('worker-enrollment.store');
    }

    private function submit(): WorkerEnrollment
    {
        $this->actingAs($this->admin)->post($this->url(), ['team_id' => $this->team->id, 'name' => 'Kim Worker', 'phone' => '202-555-0147'])->assertRedirect();

        return WorkerEnrollment::firstOrFail();
    }

    public function test_hr_registration_is_pending_and_cannot_set_permissions_or_create_employee(): void
    {
        $this->actingAs($this->admin)->post($this->url(), ['team_id' => $this->team->id, 'name' => 'Kim Worker', 'phone' => '202-555-0147', 'status' => 'approved', 'access_role' => 'admin'])->assertRedirect();
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('worker_enrollments', ['phone' => '+12025550147', 'status' => 'pending']);
        $this->post($this->url(), ['team_id' => $this->team->id, 'name' => 'Different Person', 'phone' => '+1 202 555 0147'])->assertRedirect();
        $this->assertDatabaseCount('worker_enrollments', 1);
        $this->assertSame('Kim Worker', WorkerEnrollment::first()->name);
    }

    public function test_hr_can_confirm_registration_and_receive_personal_qr_in_one_step(): void
    {
        $response = $this->actingAs($this->admin)->post($this->url(), [
            'team_id' => $this->team->id,
            'name' => 'Fast Worker',
            'phone' => '202-555-0199',
            'confirmed' => 1,
        ])->assertOk()->assertViewIs('worker-enrollment.qr')->assertSee('출퇴근 시작 QR');

        $this->assertDatabaseHas('worker_enrollments', ['phone' => '+12025550199', 'status' => 'approved']);
        $this->assertDatabaseHas('employees', [
            'name' => 'Fast Worker',
            'team_id' => $this->team->id,
            'employment_status' => 'active',
        ]);
        $this->assertDatabaseCount('auth_setup_tokens', 1);
        $this->assertStringContainsString('/auth/pin/setup/', $response->viewData('url'));
    }

    public function test_public_registration_and_team_qr_are_removed_and_guest_cannot_register(): void
    {
        $this->get('/worker-enroll/'.$this->team->id)->assertNotFound();
        $this->postJson($this->url(), ['team_id' => $this->team->id, 'name' => 'Kim', 'phone' => '2025550147'])->assertUnauthorized();
        $this->actingAs($this->admin)->post('/worker-onboarding/teams/'.$this->team->id.'/invite')->assertNotFound();
        $this->post($this->url(), ['team_id' => $this->team->id, 'name' => 'Kim', 'phone' => 'abc'])->assertSessionHasErrors('phone');
    }

    public function test_back_link_returns_to_attendance_app_only_when_opened_from_it(): void
    {
        $this->actingAs($this->admin)
            ->get(route('worker-enrollment.index', ['return_to' => '/attendance-app']))
            ->assertOk()
            ->assertSee('href="/attendance-app"', false);

        $this->get(route('worker-enrollment.index', ['return_to' => 'https://example.com']))
            ->assertOk()
            ->assertSee('href="/"', false)
            ->assertDontSee('example.com');
    }

    public function test_approval_activation_and_pin_login_without_email_use_existing_employee_relation(): void
    {
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1])->assertRedirect();
        $enrollment->refresh();
        $employee = $enrollment->employee;
        $user = $employee->user;
        $this->assertNull($user->email);
        $this->assertSame('worker', $user->access_role);
        $this->assertSame('self', $user->access_scope);
        $this->assertTrue($employee->isHourly());
        $this->assertEquals($this->team->id, $employee->team_id);
        $this->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('employees', 1);
        $response = $this->post(route('worker-enrollment.activation', $enrollment))->assertOk();
        $url = $response->viewData('url');
        $this->assertTrue(AuthSetupToken::first()->expires_at->lte(now()->addMinutes(15)));
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Kim Worker');
        $result = $this->postJson($url, ['pin' => '5937'])->assertOk();
        $this->assertSame(route('gate.show', ['site' => $employee->site_id, 'onboarded' => 1]), $result->json('redirect'));
        $this->assertNotEmpty($result->json('attendance_device_token'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('login_devices', 1);
        $this->assertDatabaseCount('worker_devices', 1);
        $this->postJson(route('gate.me', ['site' => $employee->site_id]), [
            'device_token' => $result->json('attendance_device_token'),
        ])->assertOk()->assertJsonPath('recognized', true)->assertJsonPath('employee.id', $employee->id);
        $this->get('/attendance-app')->assertOk();
        $this->travelTo(Carbon::parse('2026-09-19 12:00:00', 'UTC'));
        $this->postJson(route('attendance-app.punch'), ['direction' => 'in', 'gate_site' => $employee->site_id])
            ->assertOk()->assertJsonPath('success', true);
        $this->travel(2)->minutes();
        $this->postJson(route('attendance-app.punch'), ['direction' => 'out', 'gate_site' => $employee->site_id])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $employee->id, 'team_id' => $this->team->id, 'event_type' => 'clock_out']);
        $this->postJson($url, ['pin' => '5937'])->assertStatus(422);
        auth()->logout();
        $this->postJson(route('pin.login'), ['device_token' => $result->json('device_token'), 'pin' => '5937'])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_only_hr_roles_can_register_and_legacy_routes_do_not_bypass_policy(): void
    {
        $this->postJson('/join/'.$this->team->site_id, [])->assertUnauthorized();
        foreach (['foreman', 'worker', 'site_manager', 'payroll'] as $role) {
            $actor = User::factory()->create(['access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active']);
            $this->actingAs($actor)->postJson('/join/'.$this->team->site_id, [])->assertForbidden();
            $this->postJson($this->url(), ['team_id' => $this->team->id, 'name' => 'Kim', 'phone' => '2025550147'])->assertForbidden();
        }
        $hr = User::factory()->create(['access_role' => 'hr_manager', 'access_scope' => 'team', 'allowed_team_id' => $this->team->id, 'account_status' => 'active']);
        $this->actingAs($hr)->post($this->url(), ['team_id' => $this->team->id, 'name' => 'Kim Worker', 'phone' => '2025550147'])->assertRedirect();
        $hr->update(['allowed_team_id' => null]);
        $this->post(route('worker-enrollment.approve', WorkerEnrollment::first()), ['confirmed' => 1])->assertForbidden();
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_foreman_can_only_read_own_team_and_cannot_register_approve_reject_or_activate(): void
    {
        $enrollment = $this->submit();
        $person = Employee::create(['name' => 'Foreman', 'company_id' => $this->team->company_id, 'site_id' => $this->team->site_id, 'team_id' => $this->team->id, 'employment_status' => 'active']);
        $foreman = User::factory()->create(['employee_id' => $person->id, 'access_role' => 'foreman', 'access_scope' => 'team', 'allowed_team_id' => $this->team->id, 'account_status' => 'active']);
        $this->team->update(['foreman_employee_id' => $person->id]);
        $this->actingAs($foreman)->get(route('worker-enrollment.index'))->assertOk()->assertSee('Kim Worker')->assertDontSee('<form', false);
        $this->post($this->url(), ['team_id' => $this->team->id, 'name' => 'Other', 'phone' => '2025550100'])->assertForbidden();
        $this->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1])->assertForbidden();
        $this->post(route('worker-enrollment.reject', $enrollment))->assertForbidden();
        $this->post(route('worker-enrollment.activation', $enrollment))->assertForbidden();
        $this->assertSame('pending', $enrollment->fresh()->status);
        $this->assertDatabaseCount('auth_setup_tokens', 0);
        $other = $this->team->replicate();
        $other->code = 'OTHER';
        $other->save();
        WorkerEnrollment::create(['team_id' => $other->id, 'name' => 'Hidden Person', 'phone' => '+12025550100', 'status' => 'pending']);
        $this->get(route('worker-enrollment.index'))->assertDontSee('Hidden Person');
        $foreman->update(['access_role' => 'worker']);
        $this->actingAs($foreman)->get(route('worker-enrollment.index'))->assertForbidden();
        $this->post(route('worker-enrollment.activation', $enrollment))->assertForbidden();
    }

    public function test_existing_phone_in_other_team_is_not_overwritten_or_duplicated(): void
    {
        $old = Employee::create(['name' => 'Kim Worker', 'phone' => '+12025550147', 'company_id' => $this->team->company_id, 'site_id' => $this->team->site_id, 'employment_type' => 'direct', 'employment_status' => 'active']);
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1])->assertSessionHasErrors('approval');
        $this->assertNull($old->fresh()->team_id);
        $this->assertDatabaseCount('employees', 1);
        $this->assertSame('pending', $enrollment->fresh()->status);
    }

    public function test_reissue_expires_old_link_and_expired_accounts_cannot_activate(): void
    {
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1]);
        $old = $this->post(route('worker-enrollment.activation', $enrollment))->viewData('url');
        $new = $this->post(route('worker-enrollment.activation', $enrollment))->viewData('url');
        auth()->logout();
        $this->postJson($old, ['pin' => '5937'])->assertStatus(422);
        $this->travel(16)->minutes();
        $this->postJson($new, ['pin' => '5937'])->assertStatus(422);
        $this->assertDatabaseCount('login_devices', 0);
    }

    public function test_rejection_and_missing_identity_confirmation_do_not_create_access(): void
    {
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment))->assertSessionHasErrors('confirmed');
        $this->post(route('worker-enrollment.reject', $enrollment))->assertRedirect();
        $this->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1])->assertStatus(409);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_existing_same_team_worker_is_linked_without_replacing_history_identity(): void
    {
        $employee = Employee::create(['name' => 'Kim Worker', 'phone' => '(202) 555-0147',
            'company_id' => $this->team->company_id, 'site_id' => $this->team->site_id,
            'team_id' => $this->team->id, 'employment_type' => 'direct', 'employment_status' => 'active']);
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1])->assertRedirect();
        $this->assertSame($employee->id, $enrollment->fresh()->employee_id);
        $this->assertDatabaseCount('employees', 1);
        $this->assertNotNull($employee->fresh()->user);
    }

    public function test_suspended_or_promoted_user_cannot_consume_activation(): void
    {
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1]);
        $url = $this->post(route('worker-enrollment.activation', $enrollment))->viewData('url');
        $user = $enrollment->fresh()->employee->user;
        $user->update(['account_status' => 'suspended']);
        auth()->logout();
        $this->postJson($url, ['pin' => '5937'])->assertStatus(422);
        $user->update(['account_status' => 'active', 'access_role' => 'admin']);
        $this->postJson($url, ['pin' => '5937'])->assertStatus(422);
        $this->assertDatabaseCount('login_devices', 0);
    }

    public function test_no_email_worker_account_can_be_edited_by_existing_account_management(): void
    {
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1]);
        $user = $enrollment->fresh()->employee->user;
        $result = app(UserAccessService::class)->save([
            'id' => $user->id, 'name' => 'Kim Worker', 'email' => '', 'role' => 'worker',
            'scope' => 'self', 'status' => 'active', 'employeeId' => $user->employee_id,
        ]);
        $this->assertTrue($result['success']);
        $this->assertNull($user->fresh()->email);
        $this->assertFalse(app(EmployeeAdminService::class)->delete($user->employee_id)['success']);
    }

    public function test_setup_requires_nontrivial_pin_and_cannot_reactivate_after_completion(): void
    {
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1]);
        $url = $this->post(route('worker-enrollment.activation', $enrollment))->viewData('url');
        auth()->logout();
        foreach (['1111', '1234', '0147'] as $pin) {
            $this->postJson($url, ['pin' => $pin])->assertStatus(422);
        }
        $this->postJson($url, ['pin' => '5937'])->assertOk();
        $this->actingAs($this->admin)->post(route('worker-enrollment.activation', $enrollment))->assertStatus(409);
    }

    public function test_regular_pin_invite_does_not_bind_a_gate_attendance_device(): void
    {
        $employee = Employee::create([
            'name' => 'Invited Worker',
            'phone' => '+12025550188',
            'company_id' => $this->team->company_id,
            'site_id' => $this->team->site_id,
            'team_id' => $this->team->id,
            'position' => 'worker',
            'employment_type' => 'direct',
            'employment_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);
        $url = app(PinAuthService::class)->issueSetupLink($user, AuthSetupToken::PURPOSE_INVITE, $this->admin);

        $this->postJson($url, ['pin' => '5937'])
            ->assertOk()
            ->assertJsonPath('attendance_device_token', null)
            ->assertJsonPath('redirect', '/attendance-app');
        $this->assertDatabaseCount('worker_devices', 0);
    }
}
