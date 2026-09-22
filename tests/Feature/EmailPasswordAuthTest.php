<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmailPasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    private function employeeUser(string $role = 'worker', string $email = 'worker@yahoo.com'): User
    {
        $company = Company::firstOrCreate(['code' => 'AUTH'], ['name' => 'Auth Test', 'status' => 'active']);
        $employee = Employee::create([
            'company_id' => $company->id, 'name' => 'Test Employee', 'email' => $email,
            'phone' => '+1 (480) 555-0072', 'employment_status' => 'active', 'employment_type' => 'direct',
        ]);

        return User::factory()->create([
            'employee_id' => $employee->id, 'email' => $email, 'access_role' => $role,
            'account_status' => 'active', 'access_scope' => 'self', 'google_id' => null, 'email_verified_at' => null,
        ]);
    }

    private function signIn(string $password = '0072', string $email = 'worker@yahoo.com')
    {
        return $this->post('/auth/password/login', compact('email', 'password'));
    }

    private function savePassword(string $password = 'MySite2026')
    {
        return $this->post('/auth/password/setup', ['password' => $password, 'password_confirmation' => $password]);
    }

    public function test_login_has_email_form_google_option_and_no_dead_password_link(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="email"', false)
            ->assertSee('/auth/password/login')->assertSee('/auth/google')->assertDontSee('/admin/login');
    }

    public function test_phone_digits_create_only_a_setup_session_then_password_logs_worker_into_app(): void
    {
        $user = $this->employeeUser();
        $this->signIn('0072', ' WORKER@YAHOO.COM ')->assertRedirect('/auth/password/setup');
        $this->assertGuest();
        $this->get('/auth/password/setup')->assertOk();
        $this->get('/')->assertRedirect('/login');
        $this->savePassword()->assertRedirect('/attendance-app');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('MySite2026', $user->fresh()->password));
        $this->assertNotNull($user->fresh()->password_set_at);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame('worker', $user->fresh()->access_role);
        $this->assertSame('self', $user->fresh()->access_scope);
        $this->assertDatabaseMissing('auth_events', ['note' => '0072']);
    }

    public function test_last_four_digits_cannot_be_reused_after_password_setup_or_phone_change(): void
    {
        $user = $this->employeeUser();
        $user->forceFill(['password' => 'MySite2026', 'password_set_at' => now()])->save();
        $user->employee->update(['phone' => '4805559911']);
        $this->signIn('0072')->assertSessionHasErrors('email_login');
        $this->signIn('9911')->assertSessionHasErrors('email_login');
        $this->assertGuest();
        $this->signIn('MySite2026')->assertRedirect('/attendance-app');
        $this->assertAuthenticatedAs($user);
    }

    /**
     * 구글이 안 되는 관리자도 들어올 수 있어야 한다 (2026-09-22, 오너 지시).
     *
     * 예전에는 google_id 가 있으면 이 길을 막았다. 4자리가 약한 값이라서였다. 그런데
     * 지메일이 없거나 회사 구글 로그인이 막힌 관리자에게는 그게 «문이 하나도 없다» 가
     * 된다 — 계정은 있는데 들어갈 수가 없었다.
     */
    public function test_a_manager_whose_google_does_not_work_can_still_get_in_with_phone_digits(): void
    {
        $user = $this->employeeUser('site_manager');
        $user->forceFill(['google_id' => 'registered-google'])->save();

        // 열리는 것은 로그인이 아니라 «비밀번호를 정하는 5분» 이다.
        $this->signIn()->assertRedirect('/auth/password/setup');
        $this->assertGuest();

        $this->savePassword()->assertRedirect($user->fresh()->landingPath());
        $this->assertAuthenticatedAs($user->fresh());

        // 구글 연결은 건드리지 않는다 — 나중에 지메일이 다시 되면 그대로 쓴다.
        $this->assertSame('registered-google', $user->fresh()->google_id);
    }

    public function test_the_phone_digit_door_shuts_the_moment_a_password_exists(): void
    {
        $user = $this->employeeUser('site_manager');
        $user->forceFill(['google_id' => 'registered-google'])->save();
        $this->signIn();
        $this->savePassword();
        Auth::logout();

        // 4자리는 이제 열쇠가 아니다. 한 번 쓰고 닫히는 문이라 오래 열려 있지 않는다.
        $this->signIn()->assertSessionHasErrors('email_login');
        $this->assertGuest();
    }

    public function test_getting_in_that_way_is_reported_so_a_theft_is_seen(): void
    {
        // 4자리는 현장에서 반쯤 공개된 값이다. 문을 열어 두는 대신, 열렸다는 사실이
        // 반드시 보이게 한다 — 본인이 «안 들어가진다» 고 말할 때까지 기다리면 늦는다.
        $user = $this->employeeUser('site_manager');
        $user->forceFill(['google_id' => 'registered-google'])->save();

        $this->signIn();
        $this->savePassword();

        $this->assertDatabaseHas('unified_alerts', [
            'fingerprint' => "password-set-by-phone-digits:{$user->id}",
            'event_type' => 'password_set_by_phone_digits',
            'severity' => 'warning',
        ]);
    }

    public function test_adding_a_password_while_already_signed_in_is_not_reported_as_a_theft(): void
    {
        // 이미 들어와 있는 사람이 비밀번호를 더하는 것은 4자리로 들어온 것이 아니다.
        // 여기까지 알리면 알림이 시끄러워지고, 시끄러운 알림은 곧 안 읽는 알림이 된다.
        $user = $this->employeeUser('admin');
        $user->forceFill(['google_id' => 'existing-google'])->save();

        $this->actingAs($user)->savePassword();

        $this->assertDatabaseMissing('unified_alerts', [
            'fingerprint' => "password-set-by-phone-digits:{$user->id}",
        ]);
    }

    public function test_pin_accounts_still_do_not_gain_the_phone_digit_fallback(): void
    {
        // 작업자·반장에게는 이미 쓰는 길(PIN)이 따로 있다. 4자리에 4자리를 더하는 것은
        // 보탬이 되지 않고, 약한 문만 하나 더 생긴다.
        $user = $this->employeeUser();
        $user->forceFill(['google_id' => null, 'pin_hash' => Hash::make('7392')])->save();

        $this->signIn()->assertSessionHasErrors('email_login');
        $this->assertGuest();
    }

    public function test_signed_in_google_user_can_set_email_password_without_phone_fallback(): void
    {
        $user = $this->employeeUser('admin');
        $user->forceFill(['google_id' => 'existing-google'])->save();
        $this->actingAs($user)->get('/auth/password/setup')->assertOk();
        $this->savePassword()->assertRedirect('/');
        $this->assertSame('existing-google', $user->fresh()->google_id);
        Auth::logout();
        $this->signIn('MySite2026')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_users_and_unlinked_or_mismatched_contact_details_are_rejected(): void
    {
        $user = $this->employeeUser();
        foreach (['pending', 'suspended', 'disabled'] as $status) {
            $user->forceFill(['account_status' => $status])->save();
            $this->signIn()->assertSessionHasErrors('email_login');
            $this->assertGuest();
        }
        $user->forceFill(['account_status' => 'active'])->save();
        $user->employee->update(['email' => 'different@example.com']);
        $this->signIn()->assertSessionHasErrors('email_login');
        $user->employee->update(['email' => $user->email, 'phone' => null]);
        $this->signIn('')->assertSessionHasErrors('password');
        $this->signIn()->assertSessionHasErrors('email_login');
        $user->forceFill(['employee_id' => null])->save();
        $this->signIn()->assertSessionHasErrors('email_login');
        $this->assertGuest();
    }

    public function test_employee_registration_alone_does_not_create_login_or_grant_role(): void
    {
        $user = $this->employeeUser();
        $user->delete();
        $this->signIn()->assertSessionHasErrors('email_login');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_five_failed_attempts_lock_account_across_ip_addresses_for_fifteen_minutes(): void
    {
        $user = $this->employeeUser();
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i]);
            $this->signIn('9999')->assertSessionHasErrors('email_login');
        }
        $this->assertTrue($user->fresh()->password_login_locked_until->isFuture());
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100']);
        $this->signIn()->assertSessionHasErrors('email_login');
        $this->travel(16)->minutes();
        $this->signIn()->assertRedirect('/auth/password/setup');
        $this->assertSame(0, $user->fresh()->password_login_failures);
        $this->assertGuest();
    }

    public function test_expired_setup_or_post_without_setup_cannot_set_password(): void
    {
        $user = $this->employeeUser();
        $this->savePassword()->assertRedirect('/login');
        $this->signIn();
        $this->travel(6)->minutes();
        $this->get('/auth/password/setup')->assertRedirect('/login');
        $this->savePassword()->assertRedirect('/login');
        $this->assertNull($user->fresh()->password_set_at);
        $this->assertGuest();
    }

    public function test_pending_setup_cannot_be_replayed_after_another_setup_completes(): void
    {
        $user = $this->employeeUser();
        $this->signIn();
        $pending = session(EmailPasswordAuthService::SETUP_SESSION);
        $this->savePassword();
        Auth::logout();
        $this->withSession([EmailPasswordAuthService::SETUP_SESSION => $pending]);
        $this->savePassword('Attacker2026')->assertRedirect('/login');
        $this->assertTrue(Hash::check('MySite2026', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_changed_phone_or_disabled_account_invalidates_pending_setup(): void
    {
        $user = $this->employeeUser();
        $this->signIn();
        $user->employee->update(['phone' => '4805557788']);
        $this->savePassword()->assertRedirect('/login');
        $this->signIn('7788');
        $user->forceFill(['account_status' => 'suspended'])->save();
        $this->savePassword()->assertRedirect('/login');
        $this->assertNull($user->fresh()->password_set_at);
    }

    public function test_password_requires_letters_numbers_and_matching_confirmation(): void
    {
        $this->employeeUser();
        $this->signIn();
        foreach (['0072', 'abcdefgh', '12345678'] as $password) {
            $this->savePassword($password)->assertSessionHasErrors('password');
        }
        $this->post('/auth/password/setup', ['password' => 'MySite2026', 'password_confirmation' => 'Other2026'])
            ->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = $this->employeeUser();
        $user->forceFill(['password' => 'MySite2026', 'password_set_at' => now()])->save();
        $this->actingAs($user)->get('/auth/password/setup')->assertSee('current_password');
        $this->savePassword('NewSite2026')->assertSessionHasErrors('current_password');
        $this->post('/auth/password/setup', [
            'current_password' => 'wrong', 'password' => 'NewSite2026', 'password_confirmation' => 'NewSite2026',
        ])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('MySite2026', $user->fresh()->password));
        $this->post('/auth/password/setup', [
            'current_password' => 'MySite2026', 'password' => 'NewSite2026', 'password_confirmation' => 'NewSite2026',
        ])->assertRedirect('/attendance-app');
        $this->assertTrue(Hash::check('NewSite2026', $user->fresh()->password));
    }

    public function test_placeholder_password_is_not_a_valid_login_and_bad_password_is_not_flashed(): void
    {
        $this->employeeUser();
        $this->signIn('password')->assertSessionHasErrors('email_login')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
    }

    public function test_active_manager_without_google_can_bootstrap_without_changing_scope(): void
    {
        $user = $this->employeeUser('site_manager', 'manager@outlook.com');
        $this->signIn('0072', 'manager@outlook.com')->assertRedirect('/auth/password/setup');
        $this->savePassword()->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('site_manager', $user->fresh()->access_role);
        $this->assertSame('self', $user->fresh()->access_scope);
    }

    public function test_existing_password_cannot_bypass_suspension_or_employee_offboarding(): void
    {
        $user = $this->employeeUser();
        $user->forceFill(['password' => 'MySite2026', 'password_set_at' => now(), 'account_status' => 'suspended'])->save();
        $this->signIn('MySite2026')->assertSessionHasErrors('email_login');
        $user->forceFill(['account_status' => 'active'])->save();
        $user->employee->update(['employment_status' => 'inactive']);
        $this->signIn('MySite2026')->assertSessionHasErrors('email_login');
        $this->assertGuest();
    }

    public function test_ip_throttle_limits_attempts_against_unknown_accounts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signIn('9999', 'unknown'.$i.'@example.com')->assertRedirect('/login');
        }
        $this->signIn('9999', 'unknown@example.com')->assertStatus(429);
    }
}
