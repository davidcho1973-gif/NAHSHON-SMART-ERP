<?php

namespace Tests\Feature;

use App\Models\AttendanceQrCode;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Admin\CrewSetupService;
use App\Services\Admin\EmployeeAdminService;
use App\Services\AttendanceQrService;
use App\Services\Hr\GlobalHrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrewSetupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'CREW', 'name' => 'Crew Company', 'status' => 'active']);
        $this->site = Site::create(['code' => 'CREW-SITE', 'name' => 'Crew Site', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
    }

    private function service(): CrewSetupService
    {
        return app(CrewSetupService::class);
    }

    private function input(array $extra = []): array
    {
        return array_merge(['name' => 'Pipe Team', 'code' => 'CREW-PIPE', 'companyId' => $this->company->id, 'siteId' => $this->site->id, 'trade' => 'Plumbing', 'planned' => 4], $extra);
    }

    private function employee(array $extra = []): Employee
    {
        return Employee::create(array_merge(['name' => 'Foreman', 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'employment_status' => 'active', 'employment_type' => 'direct'], $extra));
    }

    public function test_apply_access_promotes_worker_and_limits_actual_crew_app_to_own_team(): void
    {
        $person = $this->employee();
        $user = User::factory()->create(['employee_id' => $person->id, 'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']);
        $password = $user->password;
        $result = $this->service()->saveTeam($this->input(['foremanId' => $person->id, 'applyAppAccess' => true]));
        $this->assertTrue($result['success'], json_encode($result));
        $user->refresh();
        $this->assertSame('foreman', $user->access_role);
        $this->assertSame('team', $user->access_scope);
        $this->assertEquals($result['id'], $user->allowed_team_id);
        $this->assertSame($password, $user->password);
        $this->assertSame('foreman', $person->fresh()->attendance_app_role);
        $this->assertSame('team', $person->fresh()->attendance_app_scope);
        $qr = AttendanceQrCode::forTeam(Team::find($result['id']));
        $other = Team::create(['code' => 'OTHER-T', 'name' => 'Other Team', 'site_id' => $this->site->id, 'company_id' => $this->company->id, 'status' => 'active']);
        $otherQr = AttendanceQrCode::forTeam($other);
        $service = app(AttendanceQrService::class);
        $this->assertTrue($service->canProcessCrew($user, $qr));
        $this->assertFalse($service->canProcessCrew($user, $otherQr));
        $this->actingAs($user)->get(route('attendance-app.crew', ['token' => $qr->token]))->assertOk();
        $this->get(route('attendance-app.crew', ['token' => $otherQr->token]))->assertRedirect();
        $this->assertDatabaseHas('auth_events', ['event' => 'foreman_access_applied', 'user_id' => $user->id]);
    }

    public function test_apply_creates_missing_account_with_entered_email(): void
    {
        $person = $this->employee();
        $result = $this->service()->saveTeam($this->input(['foremanId' => $person->id, 'applyAppAccess' => true, 'foremanEmail' => 'foreman@example.test']));
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertDatabaseHas('users', ['employee_id' => $person->id, 'email' => 'foreman@example.test', 'access_role' => 'foreman', 'access_scope' => 'team']);
    }

    public function test_missing_or_duplicate_email_rolls_back_team_and_membership(): void
    {
        $person = $this->employee();
        $existing = User::factory()->create();
        foreach (['', $existing->email] as $email) {
            $result = $this->service()->saveTeam($this->input(['foremanId' => $person->id, 'applyAppAccess' => true, 'foremanEmail' => $email]));
            $this->assertFalse($result['success']);
            $this->assertDatabaseCount('teams', 0);
            $this->assertNull($person->fresh()->team_id);
            $this->assertNull($person->fresh()->user);
        }
    }

    public function test_admin_and_inactive_accounts_are_not_overwritten(): void
    {
        foreach ([['admin', 'active'], ['foreman', 'inactive'], ['super_admin', 'active']] as [$role, $status]) {
            $person = $this->employee();
            $user = User::factory()->create(['employee_id' => $person->id, 'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => $status]);
            $result = $this->service()->saveTeam($this->input(['foremanId' => $person->id, 'applyAppAccess' => true]));
            $this->assertFalse($result['success']);
            $this->assertSame($role, $user->fresh()->access_role);
            $this->assertSame($status, $user->fresh()->account_status);
            $this->assertDatabaseCount('teams', 0);
        }
    }

    public function test_replacement_revokes_old_foreman_and_new_account_failure_is_atomic(): void
    {
        $old = $this->employee(['email' => 'old@example.test']);
        $first = $this->service()->saveTeam($this->input(['foremanId' => $old->id, 'applyAppAccess' => true]));
        $next = $this->employee();
        $result = $this->service()->saveTeam($this->input(['id' => $first['id'], 'foremanId' => $next->id, 'applyAppAccess' => true]));
        $this->assertFalse($result['success']);
        $this->assertSame('foreman', $old->fresh()->user->access_role);
        $this->assertEquals($old->id, Team::find($first['id'])->foreman_employee_id);
        $result = $this->service()->saveTeam($this->input(['id' => $first['id'], 'foremanId' => $next->id, 'applyAppAccess' => true, 'foremanEmail' => 'next@example.test']));
        $this->assertTrue($result['success']);
        $this->assertSame('worker', $old->fresh()->user->access_role);
        $this->assertSame('self', $old->fresh()->user->access_scope);
        $this->assertSame('worker', $old->fresh()->attendance_app_role);
        $this->assertSame('foreman', $next->fresh()->user->access_role);
        $this->assertEquals($first['id'], $old->fresh()->team_id);
    }

    public function test_removal_requires_access_cleanup_and_clears_crew_access(): void
    {
        $person = $this->employee(['email' => 'clear@example.test']);
        $first = $this->service()->saveTeam($this->input(['foremanId' => $person->id, 'applyAppAccess' => true]));
        $result = $this->service()->saveTeam($this->input(['id' => $first['id'], 'foremanId' => '']));
        $this->assertFalse($result['success']);
        $result = $this->service()->saveTeam($this->input(['id' => $first['id'], 'foremanId' => '', 'applyAppAccess' => true]));
        $this->assertTrue($result['success']);
        $this->assertSame('worker', $person->fresh()->user->access_role);
        $this->assertSame('self', $person->fresh()->attendance_app_scope);
        $this->assertNull(Team::find($first['id'])->foreman_employee_id);
    }

    public function test_inactive_team_revokes_foreman_without_disabling_account(): void
    {
        $person = $this->employee(['email' => 'inactive-team@example.test']);
        $first = $this->service()->saveTeam($this->input(['foremanId' => $person->id, 'applyAppAccess' => true]));
        $result = $this->service()->saveTeam($this->input(['id' => $first['id'], 'foremanId' => $person->id, 'status' => 'inactive', 'applyAppAccess' => true]));
        $this->assertTrue($result['success']);
        $this->assertSame('worker', $person->fresh()->user->access_role);
        $this->assertSame('active', $person->fresh()->user->account_status);
    }

    public function test_team_links_foreman_and_company_site_without_granting_app_access(): void
    {
        $person = $this->employee();
        $result = $this->service()->saveTeam($this->input(['foremanId' => $person->id]));
        $this->assertTrue($result['success'], json_encode($result));
        $team = Team::findOrFail($result['id']);
        $this->assertEquals($team->id, $person->fresh()->team_id);
        $this->assertEquals($person->id, $team->foreman_employee_id);
        $this->assertSame('Foreman', $team->foreman_name);
        $this->assertNull($person->fresh()->user);
        $this->assertTrue($this->company->sites()->where('sites.id', $this->site->id)->exists());
        $person->update(['name' => 'Renamed']);
        $this->assertSame('Renamed', $team->fresh()->foreman_name);
    }

    public function test_foreman_from_another_company_is_rejected_atomically(): void
    {
        $other = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $person = $this->employee(['company_id' => $other->id]);
        $result = $this->service()->saveTeam($this->input(['foremanId' => $person->id]));
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('foremanId', $result['errors']);
        $this->assertDatabaseCount('teams', 0);
        $this->assertNull($person->fresh()->team_id);
    }

    public function test_company_codes_and_case_insensitive_names_cannot_duplicate(): void
    {
        $result = $this->service()->saveCompany(['code' => 'NEW', 'name' => ' crew company ', 'company_type' => 'partner']);
        $this->assertFalse($result['success']);
        $result = $this->service()->saveCompany(['id' => $this->company->id, 'code' => 'CHANGED', 'name' => 'Crew Company', 'company_type' => 'partner']);
        $this->assertFalse($result['success']);
        $result = $this->service()->saveCompany(['code' => 'new', 'name' => 'New Company', 'company_type' => 'partner']);
        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('companies', ['id' => $result['id'], 'code' => 'NEW']);
    }

    public function test_company_scoped_hr_cannot_read_or_write_other_company(): void
    {
        $this->employee();
        $other = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $hidden = $this->employee(['company_id' => $other->id]);
        $this->actingAs(User::factory()->create(['access_role' => 'hr_manager', 'access_scope' => 'company', 'allowed_company_id' => $this->company->id, 'account_status' => 'active']));
        $view = $this->service()->overview();
        $this->assertCount(1, $view['employees']);
        $this->assertFalse($this->service()->saveTeam($this->input(['companyId' => $other->id]))['success']);
        $this->assertFalse($this->service()->assign(['employeeId' => $hidden->id])['success']);
        $this->assertFalse($this->service()->saveCompany(['name' => 'Forbidden'])['success']);
    }

    public function test_worker_and_inactive_administrator_are_denied(): void
    {
        foreach ([['worker', 'active'], ['admin', 'inactive']] as [$role,$status]) {
            $this->actingAs(User::factory()->create(['access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => $status]));
            $this->assertFalse($this->service()->overview()['success']);
            $this->assertFalse($this->service()->saveTeam($this->input())['success']);
            $this->assertFalse($this->service()->saveCompany([])['success']);
        }
    }

    public function test_assignments_reject_cross_site_and_protect_designated_foreman(): void
    {
        $person = $this->employee();
        $result = $this->service()->saveTeam($this->input(['foremanId' => $person->id]));
        $this->assertFalse($this->service()->assign(['employeeId' => $person->id, 'teamId' => ''])['success']);
        $other = Site::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $wrong = $this->employee(['site_id' => $other->id]);
        $this->assertFalse($this->service()->assign(['employeeId' => $wrong->id, 'teamId' => $result['id']])['success']);
        $worker = $this->employee(['name' => 'Worker']);
        $this->assertTrue($this->service()->assign(['employeeId' => $worker->id, 'teamId' => $result['id']])['success']);
        $this->assertEquals($result['id'], $worker->fresh()->team_id);
    }

    public function test_team_scoped_account_is_not_silently_moved_by_foreman_selection(): void
    {
        $old = Team::create(['code' => 'OLD', 'name' => 'Old', 'company_id' => $this->company->id, 'site_id' => $this->site->id]);
        $person = $this->employee();
        $account = User::factory()->create(['employee_id' => $person->id, 'access_role' => 'foreman', 'access_scope' => 'team', 'allowed_team_id' => $old->id, 'account_status' => 'active']);
        $result = $this->service()->saveTeam($this->input(['foremanId' => $person->id]));
        $this->assertFalse($result['success']);
        $this->assertEquals($old->id, $account->fresh()->allowed_team_id);
        $this->assertNull($person->fresh()->team_id);
    }

    public function test_legacy_foreman_text_is_preserved_until_a_person_is_linked(): void
    {
        $team = Team::create(['code' => 'CREW-PIPE', 'name' => 'Pipe Team', 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'foreman_name' => 'Legacy Name']);
        $result = $this->service()->saveTeam($this->input(['id' => $team->id]));
        $this->assertTrue($result['success']);
        $this->assertSame('Legacy Name', $team->fresh()->foreman_name);
    }

    public function test_employee_form_defaults_to_current_company_and_rejects_wrong_team(): void
    {
        session(['current_company_id' => $this->company->id]);
        $this->assertEquals($this->company->id, app(EmployeeAdminService::class)->options()['defaultCompanyId']);
        $other = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $team = Team::create(['code' => 'OTHER-T', 'name' => 'Other Team', 'company_id' => $other->id, 'site_id' => $this->site->id, 'status' => 'active']);
        $result = app(EmployeeAdminService::class)->save(['name' => 'Wrong', 'companyId' => $this->company->id, 'siteId' => $this->site->id, 'teamId' => $team->id, 'employmentType' => 'direct', 'status' => 'active']);
        $this->assertFalse($result['success']);
        $this->assertDatabaseMissing('employees', ['name' => 'Wrong']);
    }

    public function test_api_dispatch_and_role_boundary(): void
    {
        $this->postJson('/smart-company-api/api_saveCrewTeam', ['args' => [$this->input()], 'siteId' => 'ALL'])
            ->assertOk()->assertJson(['success' => true]);
        $this->postJson('/smart-company-api/api_getCrewSetup', ['args' => [], 'siteId' => 'ALL'])
            ->assertOk()->assertJsonPath('teams.0.code', 'CREW-PIPE');
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']));
        $this->postJson('/smart-company-api/api_getCrewSetup', ['args' => []])->assertOk()->assertJson(['success' => false]);
        $this->postJson('/smart-company-api/api_saveCrewTeam', ['args' => [$this->input()]])->assertForbidden();
    }

    public function test_editing_a_name_preserves_an_unchanged_inactive_team(): void
    {
        $team = Team::create(['code' => 'OLD', 'name' => 'Old Team', 'company_id' => $this->company->id, 'site_id' => $this->site->id, 'status' => 'inactive']);
        $person = $this->employee(['team_id' => $team->id]);
        $result = app(EmployeeAdminService::class)->save(['id' => $person->id, 'name' => 'Updated Name', 'companyId' => $this->company->id, 'siteId' => $this->site->id, 'teamId' => $team->id, 'employmentType' => 'direct', 'status' => 'active']);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertEquals($team->id, $person->fresh()->team_id);
    }

    public function test_legacy_team_board_cannot_move_designated_foreman(): void
    {
        $person = $this->employee();
        $this->service()->saveTeam($this->input(['foremanId' => $person->id]));
        $result = app(GlobalHrService::class)->assignTeam($person->id, null);
        $this->assertFalse($result['success']);
        $this->assertNotNull($person->fresh()->team_id);
    }
}
