<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ManagerInvitation;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\UserAccessService;
use App\Services\Auth\ManagerInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewManagerInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->company = Company::create(['code' => 'INVITE', 'name' => 'Invite Company']);
        $this->site = Site::create(['code' => 'INVITE', 'name' => 'Invite Site', 'company_id' => $this->company->id]);
    }

    private function issue(array $override = []): array
    {
        $this->actingAsPurchaseUser($this->owner);
        $result = app(ManagerInvitationService::class)->issue($override + ['kind' => 'new_employee', 'role' => 'site_manager',
            'scope' => 'site', 'siteId' => $this->site->id, 'recipientLabel' => 'New site manager']);
        $this->assertTrue($result['success'], json_encode($result));

        return $result;
    }

    private function guest(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    private function details(array $invite): void
    {
        $this->guest();
        $this->post($invite['url'].'/verify', ['name' => 'New Manager', 'phone' => '2025550147', 'role' => 'super_admin'])
            ->assertRedirect($invite['url']);
    }

    private function finish(array $invite, array $override = [])
    {
        return $this->post($invite['url'].'/complete', $override + ['email' => 'new@example.com',
            'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
            'role' => 'super_admin', 'scope' => 'all_sites', 'name' => 'Forged', 'phone' => '2025550999']);
    }

    public function test_one_private_link_creates_employee_and_account_only_on_completion_with_fixed_grant(): void
    {
        $invite = $this->issue();
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('new_employee', $invite['kind']);
        $this->assertNull(ManagerInvitation::first()->user_id);
        $this->details($invite);
        $this->get($invite['url'])->assertOk()->assertSee('New Manager')->assertSee('로그인 방법을 선택하세요.');
        $this->assertDatabaseCount('employees', 0);
        $this->finish($invite)->assertRedirect(route('manager-invitation.welcome'));
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('site_manager', $user->access_role);
        $this->assertSame('site', $user->access_scope);
        $this->assertSame($this->site->id, $user->allowed_site_id);
        $this->assertSame('New Manager', $user->name);
        $this->assertTrue(Hash::check('NewPassword123', $user->password));
        $this->assertNotNull($user->password_set_at);
        $this->assertFalse($user->purchase_request_enabled);
        $this->assertSame('+12025550147', $user->employee->phone);
        $this->assertSame(Employee::TYPE_STAFF, $user->employee->employment_type);
        $this->assertSame($this->site->id, $user->employee->site_id);
        $this->assertSame($this->company->id, $user->employee->company_id);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('payroll_timesheets', 0);
        $this->assertSame($user->id, ManagerInvitation::first()->user_id);
        $this->get($invite['url'])->assertGone();
        $this->finish($invite)->assertGone();
        $this->get('/')->assertOk();
        $this->get('/attendance-app')->assertOk();
    }

    public function test_missing_details_invalid_phone_and_existing_employee_phone_cannot_register(): void
    {
        $invite = $this->issue();
        $this->guest();
        $this->finish($invite)->assertGone();
        $this->post($invite['url'].'/verify', ['name' => '', 'phone' => '0147'])->assertSessionHasErrors('name');
        $this->post($invite['url'].'/verify', ['name' => 'New', 'phone' => '0147'])->assertSessionHasErrors('phone');
        Employee::create(['name' => 'Existing', 'phone' => '+1 (202) 555-0147']);
        $this->post($invite['url'].'/verify', ['name' => 'New', 'phone' => '2025550147'])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertNull(ManagerInvitation::first()->accepted_at);
    }

    public function test_phone_created_after_details_and_email_collision_roll_back_without_consuming_invitation(): void
    {
        $invite = $this->issue();
        $this->details($invite);
        $this->finish($invite, ['email' => $this->owner->email])->assertSessionHasErrors('email');
        Employee::create(['name' => 'Manual Staff', 'email' => 'new@example.com']);
        $this->finish($invite)->assertSessionHasErrors('email');
        Employee::create(['name' => 'Other Worker', 'phone' => '202-555-0147']);
        $this->finish($invite, ['email' => 'unique@example.com'])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employees', 2);
        $this->assertNull(ManagerInvitation::first()->accepted_at);
        $this->assertNull(ManagerInvitation::first()->user_id);
    }

    public function test_private_pending_list_cancellation_reissue_and_deauthorization(): void
    {
        $first = $this->issue();
        $rows = app(UserAccessService::class)->list()['newInvitations'];
        $this->assertCount(1, $rows);
        $this->assertSame($first['id'], $rows[0]['id']);
        $this->assertArrayNotHasKey('token_hash', $rows[0]);
        $second = $this->issue(['replaceInvitationId' => $first['id']]);
        $this->guest();
        $this->get($first['url'])->assertGone();
        $this->details($second);
        $this->actingAsPurchaseUser($this->owner);
        $this->assertTrue(app(ManagerInvitationService::class)->revokeNew($second['id'])['success']);
        $this->finish($second)->assertGone();
        $third = $this->issue();
        $this->owner->update(['access_role' => 'admin']);
        $this->assertSame([], app(UserAccessService::class)->list()['newInvitations']);
        $this->assertFalse(app(ManagerInvitationService::class)->revokeNew($third['id'])['success']);
        $this->guest();
        $this->get($third['url'])->assertGone();
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_session_expiry_and_invitation_expiry_block_new_accounts(): void
    {
        $invite = $this->issue();
        $this->details($invite);
        $this->travel(16)->minutes();
        $this->finish($invite)->assertGone();
        $this->travelBack();
        ManagerInvitation::find($invite['id'])->update(['expires_at' => now()->subSecond()]);
        $this->get($invite['url'])->assertGone();
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_new_google_enrollment_and_duplicate_google_identity_are_atomic(): void
    {
        config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
        Http::fake([config('services.google.token_url') => Http::response(['access_token' => 'token']),
            config('services.google.userinfo_url') => Http::response(['sub' => 'new-google', 'email' => 'google@example.com', 'email_verified' => true])]);
        $invite = $this->issue(['role' => 'admin', 'scope' => 'all_sites']);
        $this->details($invite);
        $this->get(route('auth.google.redirect'))->assertRedirect();
        $state = session('google_oauth_state');
        $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'code']))->assertRedirect(route('manager-invitation.welcome'));
        $user = User::where('google_id', 'new-google')->firstOrFail();
        $this->assertSame('admin', $user->access_role);
        $this->assertSame('all_sites', $user->access_scope);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password_set_at);
        $this->assertDatabaseCount('employees', 1);
        $second = $this->issue();
        $this->guest();
        $this->post($second['url'].'/verify', ['name' => 'Other', 'phone' => '2025550148'])->assertRedirect();
        $this->get(route('auth.google.redirect'))->assertRedirect();
        $state = session('google_oauth_state');
        $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'code']))->assertRedirect($second['url'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_new_invites_require_strong_superadmin_and_valid_preapproved_grants(): void
    {
        $this->actingAs($this->owner);
        $input = ['kind' => 'new_employee', 'role' => 'admin', 'scope' => 'all_sites'];
        $this->assertFalse(app(ManagerInvitationService::class)->issue($input)['success']);
        $this->actingAsPurchaseUser($this->owner);
        $service = app(ManagerInvitationService::class);
        $this->assertFalse($service->issue(['role' => 'super_admin'] + $input)['success']);
        $this->assertFalse($service->issue(['scope' => 'site', 'siteId' => $this->site->id] + $input)['success']);
        $this->assertFalse($service->issue(['companyId' => 999999] + $input)['success']);
        $this->assertFalse($service->issue(['kind' => 'wrong'] + $input)['success']);
        $this->assertDatabaseCount('manager_invitations', 0);
    }
}
