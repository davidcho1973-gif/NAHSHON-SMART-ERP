<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\UnifiedAlert;
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

    /**
     * 전화번호 뒷 4자리로 <b>바로</b> 들어간다 (2026-09-22, 오너 지시 「계속 쓸 수 있게」).
     *
     * 예전에는 4자리가 «비밀번호를 정하는 5분» 만 열었고, 비밀번호를 정하면 닫혔다.
     * 오너가 계속 쓰게 하라고 정했다 — 현장 관리자에게 비밀번호를 하나 더 외우게 하는
     * 것이 실제로는 «못 들어온다» 로 끝나기 때문이다.
     */
    public function test_phone_digits_sign_a_person_straight_in(): void
    {
        $user = $this->employeeUser();

        // 이메일은 앞뒤 공백·대소문자가 달라도 같은 사람으로 읽는다.
        $this->signIn('0072', ' WORKER@YAHOO.COM ')->assertRedirect('/attendance-app');
        $this->assertAuthenticatedAs($user);

        // 들어왔다고 권한이 달라지지 않는다.
        $this->assertSame('worker', $user->fresh()->access_role);
        $this->assertSame('self', $user->fresh()->access_scope);
        $this->assertNull($user->fresh()->email_verified_at);

        // 넣은 값이 기록에 남으면 그 기록을 보는 사람이 곧 그 사람 열쇠를 갖게 된다.
        $this->assertDatabaseMissing('auth_events', ['note' => '0072']);
    }

    public function test_the_digits_keep_working_after_a_password_is_set(): void
    {
        $user = $this->employeeUser();
        $user->forceFill(['password' => Hash::make('MySite2026'), 'password_set_at' => now()])->save();

        // 비밀번호를 정했다고 4자리가 닫히지 않는다 — 그게 「계속 쓸 수 있게」 의 뜻이다.
        $this->signIn('0072')->assertRedirect('/attendance-app');
        $this->assertAuthenticatedAs($user);
        Auth::logout();

        // 정해 둔 비밀번호도 그대로 통한다. 둘 다 열쇠다.
        $this->signIn('MySite2026')->assertRedirect('/attendance-app');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_old_digits_stop_working_when_the_phone_number_changes(): void
    {
        // 번호를 바꾼 이유가 폰을 잃어버린 것일 수 있다. 예전 번호가 계속 열쇠면
        // 그 폰을 주운 사람이 계속 들어온다.
        $user = $this->employeeUser();
        $user->employee->update(['phone' => '4805559911']);

        $this->signIn('0072')->assertSessionHasErrors('email_login');
        $this->assertGuest();

        $this->signIn('9911')->assertRedirect('/attendance-app');
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

        $this->signIn()->assertRedirect($user->fresh()->landingPath());
        $this->assertAuthenticatedAs($user->fresh());

        // 구글 연결은 건드리지 않는다 — 나중에 지메일이 다시 되면 그대로 쓴다.
        $this->assertSame('registered-google', $user->fresh()->google_id);
    }

    public function test_a_google_manager_keeps_the_digits_after_setting_a_password(): void
    {
        $user = $this->employeeUser('site_manager');
        $user->forceFill(['google_id' => 'registered-google'])->save();

        $this->signIn();
        $this->savePassword();
        Auth::logout();

        // 비밀번호를 정해 둔 뒤에도 4자리는 그대로 열쇠다.
        $this->signIn()->assertRedirect($user->fresh()->landingPath());
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_getting_in_with_the_digits_is_reported_once(): void
    {
        // 4자리는 명부·단톡방에 적혀 있는 값이라 아는 사람이 여럿이다. 상시 열쇠로 쓰는
        // 이상 잠금만으로는 «번호를 아는 사람» 을 막을 수 없으니, 들어온 사실을 보이게 한다.
        $user = $this->employeeUser('site_manager');

        $this->signIn();

        $this->assertDatabaseHas('unified_alerts', [
            'fingerprint' => "signed-in-with-phone-digits:{$user->id}",
            'event_type' => 'signed_in_with_phone_digits',
            'severity' => 'warning',
        ]);

        // 로그인할 때마다 울리면 곧 안 읽는 알림이 된다 — 계정당 한 줄이다.
        Auth::logout();
        $this->signIn();
        $this->assertSame(1, UnifiedAlert::query()
            ->where('fingerprint', "signed-in-with-phone-digits:{$user->id}")->count());
    }

    public function test_signing_in_with_a_real_password_is_not_reported(): void
    {
        $user = $this->employeeUser('admin');
        $user->forceFill(['password' => Hash::make('MySite2026'), 'password_set_at' => now()])->save();

        $this->signIn('MySite2026');

        $this->assertDatabaseMissing('unified_alerts', [
            'fingerprint' => "signed-in-with-phone-digits:{$user->id}",
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
        // 4자리는 10,000 가지뿐이다. 이 잠금이 «전부 시도하면 언젠가 열린다» 를 막는다
        // (5회/15분이면 다 해보는 데 500시간이 걸린다).
        $this->signIn()->assertRedirect('/attendance-app');
        $this->assertSame(0, $user->fresh()->password_login_failures);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_nobody_can_set_a_password_without_signing_in_first(): void
    {
        // 비밀번호 설정 화면은 «들어온 사람» 만 쓴다. 로그인하지 않고 여기에 바로 던지면
        // 이메일만 아는 사람이 남의 비밀번호를 정해 버릴 수 있다.
        $user = $this->employeeUser();

        $this->get('/auth/password/setup')->assertRedirect('/login');
        $this->savePassword()->assertRedirect('/login');

        $this->assertNull($user->fresh()->password_set_at);
        $this->assertGuest();
    }

    /**
     * 심어 놓은 «비밀번호 설정 세션» 으로는 아무것도 못 한다.
     *
     * 예전에는 4자리를 맞히면 서버가 5분짜리 설정 세션을 내줬고, 그 세션을 복사해
     * 두었다가 나중에 다시 쓰는 것이 위험이었다. 4자리가 바로 로그인이 된 지금은
     * 그 세션을 서버가 아예 내주지 않는다 — 그래도 <b>손으로 만들어 넣는</b> 길은
     * 여전히 막혀 있어야 한다. 로그인하지 않았으면 비밀번호를 정할 수 없다.
     */
    public function test_a_planted_setup_session_cannot_set_a_password(): void
    {
        $user = $this->employeeUser();
        $this->signIn();
        $this->savePassword();
        Auth::logout();

        $this->withSession([EmailPasswordAuthService::SETUP_SESSION => [
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(5)->timestamp,
            'contact' => 'forged',
        ]]);
        $this->savePassword('Attacker2026')->assertRedirect('/login');

        $this->assertTrue(Hash::check('MySite2026', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_a_suspended_account_cannot_get_in_or_set_a_password(): void
    {
        $user = $this->employeeUser();
        $this->signIn();
        $this->assertAuthenticatedAs($user->fresh());

        // 정지된 뒤에는 이미 열려 있던 화면으로도 비밀번호를 정할 수 없다.
        $user->forceFill(['account_status' => 'suspended'])->save();
        $this->savePassword();
        $this->assertNull($user->fresh()->password_set_at);

        Auth::logout();
        $this->signIn()->assertSessionHasErrors('email_login');
        $this->assertGuest();
    }

    public function test_password_requires_letters_numbers_and_matching_confirmation(): void
    {
        $user = $this->employeeUser();
        $this->signIn();

        foreach (['0072', 'abcdefgh', '12345678'] as $password) {
            $this->savePassword($password)->assertSessionHasErrors('password');
        }
        $this->post('/auth/password/setup', ['password' => 'MySite2026', 'password_confirmation' => 'Other2026'])
            ->assertSessionHasErrors('password');

        // 약한 값은 하나도 저장되지 않았다.
        $this->assertNull($user->fresh()->password_set_at);
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
        $this->signIn('0072', 'manager@outlook.com')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->savePassword()->assertRedirect('/');
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
