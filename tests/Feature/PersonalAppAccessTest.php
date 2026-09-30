<?php

namespace Tests\Feature;

use App\Models\AuthSetupToken;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PersonalAppDevice;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\UserAccessService;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\PurchaseAccess;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalAppAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $manager;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $company = Company::create(['code' => 'PAD', 'name' => 'Personal App', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'PAD1', 'name' => 'Site One', 'status' => 'active']);
        $this->super = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active', 'access_scope' => 'all_sites']);
        $this->manager = User::factory()->create(['access_role' => 'site_manager', 'account_status' => 'active',
            'access_scope' => 'site', 'allowed_site_id' => $this->site->id, 'allowed_company_id' => $company->id,
            'purchase_request_enabled' => true, 'purchase_buy_enabled' => false]);
    }

    private function issue(?User $target = null): string
    {
        $this->withSession([WorkerDeviceSession::FLAG => false]);
        $data = $this->actingAsPurchaseUser($this->super)->postJson('/personal-app-access/users/'.($target ?? $this->manager)->id.'/qr')
            ->assertOk()->assertJsonPath('success', true)->json();
        $this->assertStringContainsString('<svg', $data['qr_svg']);

        return parse_url($data['url'], PHP_URL_PATH);
    }

    private function expireSession(): void
    {
        Auth::logout();
        $this->app['session.store']->flush();
        Auth::forgetGuards();
    }

    private function connectManager(?User $user = null)
    {
        $path = $this->issue($user);
        $this->expireSession();

        return $this->post($path)->assertStatus(303)->assertRedirect('/attendance-app');
    }

    public function test_only_verified_superadmins_can_issue_qrs_for_approved_internal_managers(): void
    {
        $this->actingAs($this->manager)->getJson('/personal-app-access/users')->assertForbidden();
        $this->actingAs($this->super)->getJson('/personal-app-access/users')->assertForbidden()
            ->assertJsonPath('code', 'personal_app_reauthentication_required');

        $ungranted = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $worker = User::factory()->create(['access_role' => 'worker', 'account_status' => 'active', 'purchase_request_enabled' => true]);
        $this->actingAsPurchaseUser($this->super)->getJson('/personal-app-access/users')->assertOk()->assertJsonCount(2, 'users');
        foreach ([$ungranted, $worker] as $target) {
            $this->postJson('/personal-app-access/users/'.$target->id.'/qr')->assertUnprocessable();
        }
        $this->issue();
        $this->assertFalse($this->manager->fresh()->purchase_buy_enabled);
    }

    public function test_preview_never_consumes_link_and_exchange_is_single_use_and_private(): void
    {
        $path = $this->issue();
        $this->expireSession();
        $this->get($path)->assertOk()->assertViewHas('status', 'ready')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get($path)->assertOk()->assertViewHas('status', 'ready');
        $this->assertNull(AuthSetupToken::first()->used_at);

        $response = $this->post($path)->assertStatus(303)
            ->assertSessionHas(WorkerDeviceSession::FLAG, true)
            ->assertSessionMissing(EmailPasswordAuthService::STRONG_AUTH_SESSION);
        $this->assertAuthenticatedAs($this->manager);
        $cookie = $response->getCookie(PersonalAppAccessService::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNotNull(AuthSetupToken::first()->used_at);
        $this->assertDatabaseCount('personal_app_devices', 1);
        $this->assertNotSame($cookie->getValue(), PersonalAppDevice::first()->token_hash);
        $this->post($path)->assertStatus(410);
        $this->get($path)->assertViewHas('status', 'used');
    }

    public function test_expiration_reissue_and_another_token_purpose_cannot_activate_a_device(): void
    {
        $old = $this->issue();
        $latest = $this->issue();
        $this->expireSession();
        $this->post($old)->assertStatus(410);
        $this->travel(16)->minutes();
        $this->post($latest)->assertStatus(410);
        $otherToken = Str::random(64);
        AuthSetupToken::create(['user_id' => $this->manager->id, 'issued_by_id' => $this->super->id,
            'purpose' => 'activation', 'token_hash' => AuthSetupToken::hash($otherToken), 'expires_at' => now()->addMinutes(15)]);
        $this->post('/app/connect/'.$otherToken)->assertStatus(410);
        $this->assertDatabaseCount('personal_app_devices', 0);
    }

    public function test_other_signed_in_account_requires_confirmation_and_qr_cannot_preserve_erp_proof(): void
    {
        $path = $this->issue(); // The issuing superadmin is still logged in on this browser.
        $this->get($path)->assertViewHas('accountSwitch', true);
        $this->post($path)->assertStatus(409);
        $this->assertNull(AuthSetupToken::first()->used_at);
        $this->post($path, ['confirm_switch' => '1'])->assertStatus(303)
            ->assertSessionMissing(EmailPasswordAuthService::STRONG_AUTH_SESSION);
        $this->assertAuthenticatedAs($this->manager);
        $this->assertFalse(PurchaseAccess::canBuy($this->manager, request()));
    }

    public function test_cookie_restores_app_and_scoped_api_after_session_expires_without_new_login(): void
    {
        $response = $this->connectManager();
        $token = $response->getCookie(PersonalAppAccessService::COOKIE)->getValue();
        $this->expireSession();
        $this->withCookie(PersonalAppAccessService::COOKIE, $token)->get('/app')
            ->assertRedirect('/attendance-app')->assertSessionHas(WorkerDeviceSession::FLAG, true);
        $this->assertAuthenticatedAs($this->manager);
        $this->expireSession();
        $this->withCredentials()->getJson('/purchase-requests')->assertOk()->assertJsonPath('can_request', true);
        $this->assertAuthenticatedAs($this->manager);
        $this->expireSession();
        $this->get('/attendance-app/purchase-requests')->assertOk();
        $this->assertAuthenticatedAs($this->manager);
    }

    public function test_trusted_app_cannot_access_erp_permissions_buyer_actions_or_set_password(): void
    {
        $this->manager->forceFill(['purchase_buy_enabled' => true])->save();
        $this->connectManager();
        $this->get('/?view=employee-admin')->assertRedirect('/attendance-app');
        $this->postJson('/smart-company-api/api_saveUserAccount', ['args' => [[]]])->assertForbidden()->assertJsonPath('code', 'personal_app_only');
        $this->getJson('/purchase-requests?desk=1')->assertForbidden()->assertJsonPath('code', 'purchase_reauthentication_required');
        $this->postJson('/auth/password/setup', ['password' => 'Changed1234', 'password_confirmation' => 'Changed1234'])
            ->assertForbidden()->assertJsonPath('code', 'personal_app_only');
        $this->getJson('/email-ai')->assertForbidden();
        $this->getJson('/personal-app-access/users')->assertForbidden();
        $this->assertNull($this->manager->fresh()->password_set_at);
    }

    public function test_matching_remember_cookie_restores_trusted_device_and_still_honors_revocation(): void
    {
        $this->super->forceFill(['password' => Hash::make('MyAdmin2026'), 'password_set_at' => now()])->save();
        $connected = $this->connectManager($this->super);
        $deviceToken = $connected->getCookie(PersonalAppAccessService::COOKIE)->getValue();
        $device = PersonalAppDevice::first();
        $recallerName = Auth::guard()->getRecallerName();
        $login = $this->post('/auth/password/login', ['email' => $this->super->email, 'password' => 'MyAdmin2026'])
            ->assertRedirect('/')->assertSessionMissing(PersonalAppAccessService::SESSION);
        $recallerToken = $login->getCookie($recallerName)->getValue();

        // Session expiration does not revoke Laravel's remember token as logout would.
        $this->app['session.store']->flush();
        Auth::forgetGuards();
        $this->withCookies([PersonalAppAccessService::COOKIE => $deviceToken, $recallerName => $recallerToken])
            ->get('/attendance-app/manager-access')->assertOk()
            ->assertSessionHas(PersonalAppAccessService::SESSION, $device->id)
            ->assertSessionMissing(PersonalAppAccessService::LEGACY_SESSION)
            ->assertSessionMissing(EmailPasswordAuthService::STRONG_AUTH_SESSION);
        $this->assertAuthenticatedAs($this->super);
        $this->getJson('/personal-app-access/users')->assertOk();
        $this->assertFalse(app(UserAccessService::class)->canManagePurchasingGrants());

        $device->update(['revoked_at' => now()]);
        $this->getJson('/personal-app-access/users')->assertUnauthorized()
            ->assertJsonPath('code', 'personal_app_device_revoked');
        $this->assertGuest();
    }

    public function test_mismatched_remember_and_device_cookies_never_switch_or_trust_another_account(): void
    {
        $this->super->forceFill(['password' => Hash::make('MyAdmin2026'), 'password_set_at' => now()])->save();
        $connected = $this->connectManager();
        $deviceToken = $connected->getCookie(PersonalAppAccessService::COOKIE)->getValue();
        $recallerName = Auth::guard()->getRecallerName();
        $login = $this->post('/auth/password/login', ['email' => $this->super->email, 'password' => 'MyAdmin2026'])
            ->assertRedirect('/');
        $recallerToken = $login->getCookie($recallerName)->getValue();
        $this->app['session.store']->flush();
        Auth::forgetGuards();

        $this->withCookies([PersonalAppAccessService::COOKIE => $deviceToken, $recallerName => $recallerToken])
            ->get('/attendance-app/manager-access')->assertOk()
            ->assertSessionMissing(PersonalAppAccessService::SESSION)
            ->assertSessionHas(PersonalAppAccessService::LEGACY_SESSION, true)
            ->assertSessionMissing(EmailPasswordAuthService::STRONG_AUTH_SESSION);
        $this->assertAuthenticatedAs($this->super);
        $this->getJson('/personal-app-access/users')->assertForbidden()
            ->assertJsonPath('code', 'personal_app_reauthentication_required');
        $this->postJson('/smart-company-api/api_getEmployeeList')->assertForbidden()
            ->assertJsonPath('code', 'personal_app_only');
        $this->assertNull(PersonalAppDevice::first()->revoked_at);
    }

    public function test_trusted_superadmin_can_issue_existing_managers_but_cannot_delegate_purchase_grants(): void
    {
        $this->connectManager($this->super);
        $this->get('/attendance-app/manager-access')->assertOk();
        $this->getJson('/personal-app-access/users')->assertOk();
        $this->postJson('/personal-app-access/users/'.$this->manager->id.'/qr')->assertOk();
        $this->assertFalse(app(UserAccessService::class)->canManagePurchasingGrants());
        $this->getJson('/purchase-requests?desk=1')->assertForbidden();
    }

    public function test_revoking_a_connected_device_ends_existing_session_and_cookie_access(): void
    {
        $response = $this->connectManager();
        $token = $response->getCookie(PersonalAppAccessService::COOKIE)->getValue();
        PersonalAppDevice::first()->update(['revoked_at' => now()]);
        $this->withCookie(PersonalAppAccessService::COOKIE, $token)->getJson('/purchase-requests')->assertUnauthorized()
            ->assertJsonPath('code', 'personal_app_device_revoked');
        $this->assertGuest();
        $this->get('/app')->assertOk();
        $this->assertGuest();
    }

    public function test_current_scope_and_grant_changes_are_used_instead_of_qr_time_permissions(): void
    {
        $this->connectManager();
        $this->getJson('/purchase-requests')->assertOk()->assertJsonCount(1, 'sites');
        $this->manager->forceFill(['allowed_site_id' => null, 'allowed_company_id' => null])->save();
        $this->getJson('/purchase-requests')->assertOk()->assertJsonCount(0, 'sites');
        $this->manager->forceFill(['purchase_request_enabled' => false])->save();
        $this->getJson('/purchase-requests')->assertUnauthorized();
        $this->assertNotNull(PersonalAppDevice::first()->revoked_at);
    }

    public function test_account_inactivation_and_employee_departure_disable_devices_and_unconsumed_qrs(): void
    {
        $path = $this->issue();
        $this->manager->update(['account_status' => 'suspended']);
        $this->expireSession();
        $this->get($path)->assertViewHas('status', 'denied');
        $this->post($path)->assertForbidden();
        $this->manager->update(['account_status' => 'active']);
        $employee = Employee::create(['name' => 'Manager', 'company_id' => $this->site->company_id,
            'site_id' => $this->site->id, 'employment_type' => 'direct', 'employment_status' => 'active']);
        $this->manager->update(['employee_id' => $employee->id]);
        $this->connectManager();
        $employee->update(['employment_status' => 'inactive']);
        $this->getJson('/purchase-requests')->assertUnauthorized();
    }

    public function test_logout_revokes_browser_device_and_does_not_silently_restore_it(): void
    {
        $response = $this->connectManager();
        $token = $response->getCookie(PersonalAppAccessService::COOKIE)->getValue();
        $this->withCookie(PersonalAppAccessService::COOKIE, $token)->post('/logout')->assertRedirect('/login');
        $this->assertNotNull(PersonalAppDevice::first()->revoked_at);
        $this->assertGuest();
        $this->get('/app')->assertOk();
        $this->assertGuest();
    }

    public function test_explicit_password_login_upgrades_app_session_without_revoking_device(): void
    {
        $this->manager->forceFill(['password' => Hash::make('MySite2026'), 'password_set_at' => now()])->save();
        $this->connectManager();
        $this->get('/login')->assertOk()->assertSee('/auth/password/login');
        $this->post('/auth/password/login', ['email' => $this->manager->email, 'password' => 'MySite2026'])
            ->assertRedirect('/')->assertSessionMissing(PersonalAppAccessService::SESSION)
            ->assertSessionMissing(WorkerDeviceSession::FLAG)
            ->assertSessionHas(EmailPasswordAuthService::STRONG_AUTH_SESSION, $this->manager->id);
        $this->assertNull(PersonalAppDevice::first()->revoked_at);
        $this->get('/')->assertOk();
    }

    public function test_phone_digits_cannot_remove_the_app_only_boundary_via_password_login(): void
    {
        $employee = Employee::create(['name' => 'Manager', 'company_id' => $this->site->company_id,
            'email' => $this->manager->email, 'phone' => '4805550072', 'employment_type' => 'direct', 'employment_status' => 'active']);
        $this->manager->forceFill(['employee_id' => $employee->id])->save();
        $this->connectManager();
        $deviceId = PersonalAppDevice::first()->id;
        $this->post('/auth/password/login', ['email' => $this->manager->email, 'password' => '0072'])
            ->assertSessionHasErrors('email_login')->assertSessionHas(PersonalAppAccessService::SESSION, $deviceId);
        $this->postJson('/vehicle-api/save', [])->assertForbidden()->assertJsonPath('code', 'personal_app_only');
    }

    public function test_forged_or_mismatched_device_session_cannot_become_a_normal_session(): void
    {
        $this->actingAs($this->manager)->withSession([PersonalAppAccessService::SESSION => 999999]);
        $this->postJson('/smart-company-api/api_getEmployeeList')->assertUnauthorized();
        $this->assertGuest();
    }
}
