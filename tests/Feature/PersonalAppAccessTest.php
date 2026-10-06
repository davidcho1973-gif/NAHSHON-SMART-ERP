<?php

namespace Tests\Feature;

use App\Models\PersonalAppDevice;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonalAppAccessTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active', 'access_scope' => 'all_sites',
            'password' => Hash::make('ExamplePass123'), 'password_set_at' => now()]);
    }

    private function device(User $user): PersonalAppDevice
    {
        return PersonalAppDevice::create(['user_id' => $user->id, 'token_hash' => PersonalAppDevice::hash('retired-test-token'), 'expires_at' => now()->addDays(100)]);
    }

    public function test_old_qr_and_issuance_routes_are_gone_even_for_superadmins(): void
    {
        $user = $this->manager();
        $this->actingAsPurchaseUser($user);
        foreach (['/app/connect/'.str_repeat('a', 64), '/attendance-app/manager-access', '/personal-app-access/users'] as $url) {
            $this->get($url)->assertStatus(410);
        }
        $this->post('/app/connect/'.str_repeat('a', 64))->assertStatus(410);
        $this->postJson('/personal-app-access/users/'.$user->id.'/qr')->assertStatus(410);
        $this->assertDatabaseCount('personal_app_devices', 0);
        $this->assertDatabaseCount('auth_setup_tokens', 0);
    }

    public function test_old_cookie_does_not_restore_an_account(): void
    {
        $user = $this->manager();
        $device = $this->device($user);
        $this->withCookie(PersonalAppAccessService::COOKIE, 'retired-test-token')
            ->get('/attendance-app')->assertRedirect(route('login', ['erp' => 1]))
            ->assertCookieExpired(PersonalAppAccessService::COOKIE);
        $this->assertGuest();
        $this->assertNotNull($device->fresh()->revoked_at);
    }

    public function test_old_active_qr_session_is_removed_and_api_rejects_it(): void
    {
        $user = $this->manager();
        $device = $this->device($user);
        $this->actingAs($user)->withSession([PersonalAppAccessService::SESSION => $device->id, WorkerDeviceSession::FLAG => true])
            ->getJson('/purchase-requests')->assertUnauthorized()->assertJsonPath('code', 'personal_app_qr_retired');
        $this->assertGuest();
        $this->assertNotNull($device->fresh()->revoked_at);
    }

    public function test_strong_login_survives_stale_qr_cookie_and_menus_are_removed(): void
    {
        $user = $this->manager();
        $device = $this->device($user);
        $this->actingAsPurchaseUser($user)->withCookie(PersonalAppAccessService::COOKIE, 'retired-test-token')
            ->get('/attendance-app')->assertOk()->assertDontSee('/attendance-app/manager-access', false)
            ->assertSee('구매신청')->assertSee('현장 QR');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->get('/')->assertOk()->assertDontSee('data-view="personal-app-access"', false);
    }

    public function test_password_login_replaces_retired_session(): void
    {
        $user = $this->manager();
        $device = $this->device($user);
        $this->actingAs($user)->withSession([PersonalAppAccessService::SESSION => $device->id, WorkerDeviceSession::FLAG => true])
            ->post('/auth/password/login', ['email' => $user->email, 'password' => 'ExamplePass123', 'erp' => '1'])
            ->assertRedirect('/')->assertSessionHas(EmailPasswordAuthService::STRONG_AUTH_SESSION, $user->id)
            ->assertSessionMissing(PersonalAppAccessService::SESSION);
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk();
    }

    public function test_retired_cookie_cannot_switch_a_different_normally_authenticated_account(): void
    {
        $old = $this->manager();
        $this->device($old);
        $current = $this->manager();
        $this->actingAsPurchaseUser($current)->withCookie(PersonalAppAccessService::COOKIE, 'retired-test-token')
            ->get('/attendance-app')->assertOk();
        $this->assertAuthenticatedAs($current);
    }
}
