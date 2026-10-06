<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ManagerInvitation;
use App\Models\Site;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\ManagerInvitationService;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $worker;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->site = Site::create(['code' => 'INVITE', 'name' => 'Invite Site', 'status' => 'active']);
        $employee = Employee::create(['name' => 'Existing Worker', 'phone' => '+12025550147', 'site_id' => $this->site->id,
            'employment_status' => 'active', 'position' => 'worker']);
        $this->worker = User::factory()->create(['name' => 'Existing Worker', 'employee_id' => $employee->id, 'email' => null, 'google_id' => null,
            'password_set_at' => null, 'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
            'purchase_request_enabled' => false, 'purchase_buy_enabled' => false]);
    }

    private function issue(): array
    {
        $this->actingAsPurchaseUser($this->owner);
        $result = app(ManagerInvitationService::class)->issue(['id' => $this->worker->id, 'role' => 'site_manager', 'scope' => 'site', 'siteId' => $this->site->id]);
        $this->assertTrue($result['success'], json_encode($result));

        return $result;
    }

    private function guest(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    public function test_password_registration_promotes_existing_worker_once_and_retains_employee(): void
    {
        $invite = $this->issue();
        $this->assertSame('worker', $this->worker->fresh()->access_role);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $invite['qr']);
        $this->guest();
        $this->get($invite['url'])->assertOk()->assertDontSee('Existing Worker');
        $this->post($invite['url'].'/complete', ['email' => 'new@example.com', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertGone();
        $this->post($invite['url'].'/verify', ['phone' => '202-555-0147'])->assertRedirect($invite['url']);
        $this->get($invite['url'])->assertSee('Existing Worker');
        $this->post($invite['url'].'/complete', ['email' => 'NEW@example.com', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
            'role' => 'super_admin', 'scope' => 'all_sites'])->assertRedirect(route('manager-invitation.welcome'));
        $this->assertAuthenticatedAs($this->worker);
        $user = $this->worker->fresh();
        $this->assertSame('site_manager', $user->access_role);
        $this->assertSame('site', $user->access_scope);
        $this->assertSame($this->site->id, $user->allowed_site_id);
        $this->assertSame($this->worker->employee_id, $user->employee_id);
        $this->assertSame('new@example.com', $user->email);
        $this->assertTrue(Hash::check('NewPassword123', $user->password));
        $this->assertNotNull($user->password_set_at);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('users', 2);
        $this->get($invite['url'])->assertGone();
        $this->get(route('manager-invitation.welcome'))->assertOk();
        $this->get('/')->assertOk();
    }

    public function test_phone_mismatch_and_duplicate_email_do_not_promote(): void
    {
        $invite = $this->issue();
        $this->guest();
        $this->post($invite['url'].'/verify', ['phone' => '0147'])->assertSessionHasErrors('phone');
        $this->assertSame('worker', $this->worker->fresh()->access_role);
        $this->post($invite['url'].'/verify', ['phone' => '2025550147'])->assertRedirect();
        $this->post($invite['url'].'/complete', ['email' => $this->owner->email, 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertSessionHasErrors('email');
        $this->assertNull(ManagerInvitation::first()->accepted_at);
        $this->assertNull($this->worker->fresh()->email);
    }

    public function test_reissue_revoke_expiry_and_account_changes_invalidate_invites(): void
    {
        $first = $this->issue();
        $second = $this->issue();
        $this->guest();
        $this->get($first['url'])->assertGone();
        $this->get($second['url'])->assertOk();
        $this->actingAsPurchaseUser($this->owner);
        $this->assertTrue(app(ManagerInvitationService::class)->revoke($this->worker->id)['success']);
        $this->guest();
        $this->get($second['url'])->assertGone();
        $third = $this->issue();
        ManagerInvitation::find($third['id'])->update(['expires_at' => now()->subMinute()]);
        $this->guest();
        $this->get($third['url'])->assertGone();
        $fourth = $this->issue();
        $this->worker->update(['access_scope' => 'all_sites']);
        $this->guest();
        $this->get($fourth['url'])->assertGone();
    }

    public function test_only_strong_superadmin_can_invite_and_registered_accounts_cannot_be_taken_over(): void
    {
        $this->actingAs($this->owner);
        $input = ['id' => $this->worker->id, 'role' => 'admin', 'scope' => 'all_sites'];
        $this->assertFalse(app(ManagerInvitationService::class)->issue($input)['success']);
        $this->actingAsPurchaseUser($this->owner);
        $this->withSession([WorkerDeviceSession::FLAG => true]);
        $this->app['request']->session()->put(WorkerDeviceSession::FLAG, true);
        $this->assertFalse(app(ManagerInvitationService::class)->issue($input)['success']);
        $this->app['request']->session()->forget(WorkerDeviceSession::FLAG);
        $this->worker->update(['email' => 'registered@example.com']);
        $this->assertFalse(app(ManagerInvitationService::class)->issue($input)['success']);
        $this->worker->update(['email' => null]);
        $this->assertTrue(app(ManagerInvitationService::class)->issue($input)['success']);
        $this->owner->update(['access_role' => 'admin']);
        $this->assertFalse(app(ManagerInvitationService::class)->issue($input)['success']);
    }

    public function test_google_registration_uses_verified_profile_and_preserves_existing_identity(): void
    {
        config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
        Http::fake([
            config('services.google.token_url') => Http::response(['access_token' => 'token']),
            config('services.google.userinfo_url') => Http::response(['sub' => 'new-google', 'email' => 'google@example.com', 'email_verified' => true]),
        ]);
        $invite = $this->issue();
        $this->guest();
        $this->post($invite['url'].'/verify', ['phone' => '2025550147'])->assertRedirect();
        $this->get(route('auth.google.redirect'))->assertRedirect();
        $state = session('google_oauth_state');
        $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'code']))->assertRedirect(route('manager-invitation.welcome'));
        $this->assertAuthenticatedAs($this->worker);
        $this->assertSame('new-google', $this->worker->fresh()->google_id);
        $this->assertSame('site_manager', $this->worker->fresh()->access_role);
        $this->assertNotNull($this->worker->fresh()->email_verified_at);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($this->worker->id, session(EmailPasswordAuthService::STRONG_AUTH_SESSION));
    }

    public function test_alias_invitation_links_and_qr_use_the_canonical_login_host(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://alias.local');
        $invite = $this->issue();
        $this->assertStringStartsWith('http://localhost/manager-invitation/', $invite['url']);
        $token = basename($invite['url']);
        $this->guest();
        $this->get('http://alias.local/manager-invitation/'.$token)
            ->assertRedirect($invite['url'])->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNull(session(ManagerInvitationService::SESSION));
        $this->assertSame('worker', $this->worker->fresh()->access_role);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->post($invite['url'].'/verify', ['phone' => '2025550147'])->assertRedirect($invite['url']);
        $this->get($invite['url'])->assertOk()->assertSee('Existing Worker');
    }

    public function test_revoked_invitation_after_phone_verification_cannot_complete(): void
    {
        $invite = $this->issue();
        $this->guest();
        $this->post($invite['url'].'/verify', ['phone' => '2025550147'])->assertRedirect();
        ManagerInvitation::find($invite['id'])->update(['revoked_at' => now()]);
        $this->post($invite['url'].'/complete', ['email' => 'new@example.com', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertGone();
        $this->assertSame('worker', $this->worker->fresh()->access_role);
        $this->assertGuest();
    }

    public function test_expired_phone_verification_and_issuer_suspension_do_not_grant_access(): void
    {
        $invite = $this->issue();
        $this->guest();
        $this->post($invite['url'].'/verify', ['phone' => '2025550147'])->assertRedirect();
        $this->travel(16)->minutes();
        $this->post($invite['url'].'/complete', ['email' => 'new@example.com', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertGone();
        $this->travelBack();
        $this->owner->update(['account_status' => 'suspended']);
        $this->get($invite['url'])->assertGone();
        $this->assertSame('worker', $this->worker->fresh()->access_role);
    }

    public function test_invalid_scope_and_credentialed_worker_are_rejected(): void
    {
        $this->actingAsPurchaseUser($this->owner);
        $service = app(ManagerInvitationService::class);
        $this->assertFalse($service->issue(['id' => $this->worker->id, 'role' => 'super_admin', 'scope' => 'all_sites'])['success']);
        $this->assertFalse($service->issue(['id' => $this->worker->id, 'role' => 'site_manager', 'scope' => 'site', 'siteId' => 999999])['success']);
        $this->worker->forceFill(['password_set_at' => now()])->save();
        $this->assertFalse($service->issue(['id' => $this->worker->id, 'role' => 'admin', 'scope' => 'all_sites'])['success']);
        $this->assertDatabaseCount('manager_invitations', 0);
    }
}
