<?php

namespace Tests\Feature;

use App\Models\AuthEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ManagerInvitation;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\UserAccessService;
use App\Services\Auth\ManagerInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReusableManagerInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->company = Company::create(['code' => 'SHARED', 'name' => 'Shared Company']);
        $this->site = Site::create(['code' => 'SHARED', 'name' => 'Shared Site', 'company_id' => $this->company->id]);
    }

    private function issue(array $overrides = []): array
    {
        $this->actingAsPurchaseUser($this->owner);
        $result = app(ManagerInvitationService::class)->issue($overrides + ['kind' => 'new_employee', 'useMode' => 'reusable',
            'jobRole' => 'site_manager', 'scope' => 'site', 'siteIds' => [$this->site->id], 'companyId' => $this->company->id]);
        $this->assertTrue($result['success'], json_encode($result));

        return $result;
    }

    private function guest(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    private function verify(array $link, string $phone = '2025550101'): void
    {
        $this->post($link['url'].'/verify', ['name' => 'Shared Manager', 'phone' => $phone])->assertRedirect($link['url']);
    }

    private function finish(array $link, string $email = 'first@example.com')
    {
        return $this->post($link['url'].'/complete', ['email' => $email, 'password' => 'Password12345', 'password_confirmation' => 'Password12345',
            'role' => 'super_admin', 'scope' => 'all_sites', 'jobRole' => 'president', 'companyId' => 999]);
    }

    public function test_two_people_use_same_nonexpiring_link_and_receive_only_its_fixed_grant(): void
    {
        $link = $this->issue();
        $this->assertTrue($link['reusable']);
        $this->assertNull($link['expiresAt']);
        $this->travel(40)->days();
        foreach ([['2025550101', 'first@example.com'], ['2025550102', 'second@example.com']] as [$phone, $email]) {
            $this->guest();
            $this->get($link['url'])->assertOk()->assertSee('유효기간 없음');
            $this->verify($link, $phone);
            $this->finish($link, $email)->assertRedirect(route('manager-invitation.welcome'));
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame('site_manager', $user->job_role);
            $this->assertSame('admin', $user->access_role);
            $this->assertSame('site', $user->access_scope);
            $this->assertSame($this->company->id, $user->allowed_company_id);
            $this->assertSame([$this->site->id], $user->job_site_ids);
            $this->get('/')->assertOk();
        }
        $invite = ManagerInvitation::firstOrFail();
        $this->assertNull($invite->user_id);
        $this->assertNull($invite->accepted_at);
        $this->assertSame(2, $invite->use_count);
        $this->assertNotNull($invite->last_used_at);
        $this->assertDatabaseCount('employees', 2);
        $this->assertSame(2, AuthEvent::where('event', 'manager_invitation_accepted')->count());
        $this->actingAsPurchaseUser($this->owner);
        $this->assertSame($link['url'], app(ManagerInvitationService::class)->share($invite->id)['url']);
        $rows = app(UserAccessService::class)->list()['newInvitations'];
        $this->assertSame(2, $rows[0]['useCount']);
    }

    public function test_revocation_blocks_verified_session_and_replacement_stops_old_link_only(): void
    {
        $link = $this->issue();
        $this->guest();
        $this->verify($link);
        $session = session()->all();
        $replacement = $this->issue(['replaceInvitationId' => $link['id']]);
        $this->guest();
        $this->withSession($session);
        $this->finish($link)->assertGone();
        $this->assertDatabaseCount('employees', 0);
        $this->get($replacement['url'])->assertOk();
        $this->verify($replacement);
        $this->finish($replacement)->assertRedirect();
        $registered = auth()->user();
        $this->actingAsPurchaseUser($this->owner);
        $this->assertTrue(app(ManagerInvitationService::class)->revokeNew($replacement['id'])['success']);
        $this->assertSame('active', $registered->fresh()->account_status);
        $this->assertSame('site_manager', $registered->fresh()->job_role);
        $this->assertFalse(app(ManagerInvitationService::class)->share($replacement['id'])['success']);
    }

    public function test_issuer_removal_and_changed_scope_invalidate_reusable_links(): void
    {
        $link = $this->issue();
        $this->owner->update(['account_status' => 'suspended']);
        $this->guest();
        $this->get($link['url'])->assertRedirect(route('login', ['app' => 1]));
        $this->owner->update(['account_status' => 'active']);
        $this->site->update(['company_id' => Company::create(['code' => 'OTHER', 'name' => 'Other'])->id]);
        $this->get($link['url'])->assertRedirect(route('login', ['app' => 1]));
    }

    public function test_shared_link_never_uses_a_phone_alone_to_take_over_existing_employee(): void
    {
        $link = $this->issue();
        $employee = Employee::create(['name' => 'Existing', 'phone' => '+12025550101', 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'employment_status' => 'active']);
        $worker = User::factory()->create(['employee_id' => $employee->id, 'email' => null, 'google_id' => null, 'password_set_at' => null,
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']);
        $this->guest();
        $this->post($link['url'].'/verify', ['name' => 'Attacker', 'phone' => '2025550101'])->assertSessionHasErrors('phone');
        $this->assertSame('worker', $worker->fresh()->access_role);
        $this->assertDatabaseCount('employees', 1);
        $this->assertNull(ManagerInvitation::first()->last_used_at);
    }

    public function test_connected_existing_worker_keeps_same_employee_and_records(): void
    {
        $this->withoutExceptionHandling();
        $link = $this->issue();
        $employee = Employee::create(['name' => 'Existing', 'phone' => '+12025550101', 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'employment_status' => 'active', 'employee_number' => 'KEEP-001']);
        $worker = User::factory()->create(['employee_id' => $employee->id, 'email' => null, 'google_id' => null, 'password_set_at' => null,
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']);
        $this->actingAs($worker);
        $this->verify($link);
        $this->assertSame(app(ManagerInvitationService::class)->fingerprint($worker->fresh()), session(ManagerInvitationService::SESSION.'.existing_fingerprint'));
        $this->assertTrue(app(ManagerInvitationService::class)->eligible($worker->fresh()));
        $this->finish($link)->assertRedirect(route('manager-invitation.welcome'));
        $this->assertSame($employee->id, $worker->fresh()->employee_id);
        $this->assertSame('KEEP-001', $employee->fresh()->employee_number);
        $this->assertSame('superintendent', $employee->fresh()->position);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(1, ManagerInvitation::first()->use_count);
    }

    public function test_repeated_email_and_phone_do_not_consume_or_duplicate_shared_registration(): void
    {
        $link = $this->issue();
        $this->guest();
        $this->verify($link);
        $this->finish($link)->assertRedirect();
        $this->guest();
        $this->verify($link, '2025550102');
        $this->finish($link)->assertSessionHasErrors('email');
        $this->post($link['url'].'/verify', ['name' => 'Duplicate', 'phone' => '2025550101'])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('employees', 1);
        $this->assertSame(1, ManagerInvitation::first()->use_count);
    }

    public function test_sharing_secret_is_encrypted_hidden_and_requires_strong_superadmin_login(): void
    {
        $link = $this->issue();
        $invite = ManagerInvitation::firstOrFail();
        $this->assertNotSame(basename($link['url']), $invite->getRawOriginal('share_token'));
        $this->assertArrayNotHasKey('share_token', $invite->toArray());
        $this->assertArrayNotHasKey('token_hash', $invite->toArray());
        $this->guest();
        $this->assertFalse(app(ManagerInvitationService::class)->share($link['id'])['success']);
        $this->actingAs(User::factory()->create(['access_role' => 'admin']));
        $this->assertFalse(app(ManagerInvitationService::class)->share($link['id'])['success']);
        $this->guest();
        $this->actingAs($this->owner);
        $this->assertFalse(app(ManagerInvitationService::class)->share($link['id'])['success']);
    }

    public function test_one_time_mode_still_expires_and_shared_mode_rejects_legacy_broad_grants(): void
    {
        $one = $this->issue(['useMode' => 'single']);
        $this->assertFalse($one['reusable']);
        $this->assertNotNull($one['expiresAt']);
        $this->travel(8)->days();
        $this->guest();
        $this->get($one['url'])->assertRedirect(route('login', ['app' => 1]));
        $this->actingAsPurchaseUser($this->owner);
        $this->assertFalse(app(ManagerInvitationService::class)->issue(['kind' => 'new_employee', 'useMode' => 'reusable', 'role' => 'admin', 'scope' => 'all_sites'])['success']);
        $this->assertFalse(app(ManagerInvitationService::class)->issue(['kind' => 'existing_worker', 'useMode' => 'reusable'])['success']);
    }

    public function test_google_signup_keeps_shared_link_available_for_the_next_person(): void
    {
        config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
        Http::fake([
            config('services.google.token_url') => Http::response(['access_token' => 'token']),
            config('services.google.userinfo_url') => Http::response(['sub' => 'shared-google', 'email' => 'google@example.com', 'email_verified' => true]),
        ]);
        $link = $this->issue();
        $this->guest();
        $this->verify($link);
        $this->get(route('auth.google.redirect'))->assertRedirect();
        $this->get(route('auth.google.callback', ['state' => session('google_oauth_state'), 'code' => 'code']))->assertRedirect(route('manager-invitation.welcome'));
        $this->assertSame('shared-google', auth()->user()->google_id);
        $this->assertSame('site_manager', auth()->user()->job_role);
        $this->guest();
        $this->get($link['url'])->assertOk();
        $this->assertSame(1, ManagerInvitation::first()->use_count);
    }

    public function test_existing_worker_changed_after_verification_cannot_receive_stale_grant(): void
    {
        $link = $this->issue();
        $employee = Employee::create(['name' => 'Existing', 'phone' => '+12025550101', 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'employment_status' => 'active']);
        $worker = User::factory()->create(['employee_id' => $employee->id, 'email' => null, 'google_id' => null, 'password_set_at' => null,
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']);
        $this->actingAs($worker);
        $this->verify($link);
        $worker->update(['email' => 'already-linked@example.com']);
        $this->finish($link)->assertGone();
        $this->assertSame('already-linked@example.com', $worker->fresh()->email);
        $this->assertSame(0, ManagerInvitation::first()->use_count);
    }

    public function test_shared_registration_is_rate_limited_even_with_invalid_form_data(): void
    {
        $link = $this->issue();
        $this->guest();
        for ($i = 0; $i < 30; $i++) {
            $this->post($link['url'].'/verify', [])->assertSessionHasErrors('phone');
        }
        $this->post($link['url'].'/verify', [])->assertStatus(429);
        $this->assertDatabaseCount('employees', 0);
    }
}
