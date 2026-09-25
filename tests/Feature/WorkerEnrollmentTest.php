<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkerEnrollment;
use App\Services\Admin\EmployeeAdminService;
use App\Services\Admin\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        // 개인 링크는 만들지 않는다 — 본인에게 건네는 것은 현장 QR 하나뿐이고,
        // 그 앞에서 전화번호 뒷 4자리를 넣으면 들어온다(사장님 결정 2026-09-23).
        $this->assertDatabaseCount('auth_setup_tokens', 0);
        $this->assertSame(route('gate.show', ['site' => $this->team->site_id]), $response->viewData('url'));
    }

    /** 승인만으로 계정이 서고, 본인은 전화번호 뒷 4자리로 들어온다. */
    public function test_approval_alone_lets_the_worker_in_with_four_digits(): void
    {
        $enrollment = $this->submit();
        $this->actingAs($this->admin)->post(route('worker-enrollment.approve', $enrollment), ['confirmed' => 1])->assertRedirect();

        $employee = $enrollment->fresh()->employee;
        $user = $employee->user;
        $this->assertNull($user->email, '현장 인력에게 이메일을 요구하지 않는다');
        $this->assertSame('worker', $user->access_role);

        auth()->logout();
        $found = $this->postJson(route('worker-app.find'), ['last4' => '0147'])->assertOk()->json('workers');
        $this->assertSame($employee->id, $found[0]['id']);

        $this->postJson(route('worker-app.enter'), ['employee_id' => $employee->id])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->get(route('attendance-app.index'))->assertOk();
        // 그 문으로는 ERP 본화면이 열리지 않는다.
        $this->get('/')->assertRedirect(route('attendance-app.index'));
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
        $this->assertSame('pending', $enrollment->fresh()->status);
        $this->assertDatabaseCount('auth_setup_tokens', 0);
        $other = $this->team->replicate();
        $other->code = 'OTHER';
        $other->save();
        WorkerEnrollment::create(['team_id' => $other->id, 'name' => 'Hidden Person', 'phone' => '+12025550100', 'status' => 'pending']);
        $this->get(route('worker-enrollment.index'))->assertDontSee('Hidden Person');
        $foreman->update(['access_role' => 'worker']);
        $this->actingAs($foreman)->get(route('worker-enrollment.index'))->assertForbidden();
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
}
