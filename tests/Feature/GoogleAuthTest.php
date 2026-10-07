<?php

namespace Tests\Feature;

use App\Models\PersonalAppDevice;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_alias_google_entry_redirects_before_creating_oauth_state(): void
    {
        $this->configureGoogle();
        config(['services.google.redirect' => 'https://erp.example.com/auth/google/callback']);
        Http::fake();

        $this->get('https://alias.example.com/auth/google?state=untrusted&redirect_uri=https://outside.example/callback')
            ->assertRedirect('https://erp.example.com/auth/google')
            ->assertSessionMissing('google_oauth_state');

        Http::assertNothingSent();
    }

    public function test_canonical_google_entry_creates_state_without_an_extra_redirect(): void
    {
        $this->configureGoogle();
        config(['services.google.redirect' => 'https://erp.example.com:443/auth/google/callback']);

        $response = $this->get('https://erp.example.com/auth/google')
            ->assertRedirect()->assertSessionHas('google_oauth_state');

        $location = $response->headers->get('Location');
        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);
        $this->assertSame('https://erp.example.com:443/auth/google/callback', $parameters['redirect_uri']);
        $this->assertSame(session('google_oauth_state'), $parameters['state']);
        $this->assertSame('openid email profile', $parameters['scope']);
    }

    public function test_google_entry_matches_the_callback_scheme_and_non_default_port(): void
    {
        $this->configureGoogle();
        config(['services.google.redirect' => 'https://erp.example.com:8443/auth/google/callback']);

        $this->get('http://erp.example.com/auth/google')
            ->assertRedirect('https://erp.example.com:8443/auth/google')
            ->assertSessionMissing('google_oauth_state');
    }

    public function test_google_entry_without_an_explicit_callback_uses_the_current_origin(): void
    {
        $this->configureGoogle();
        config(['services.google.redirect' => null]);

        $response = $this->get('https://erp.example.com/auth/google')
            ->assertRedirect()->assertSessionHas('google_oauth_state');

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('https://erp.example.com/auth/google/callback', $parameters['redirect_uri']);
    }

    public function test_alias_login_restores_only_the_safe_local_destination_in_a_new_canonical_session(): void
    {
        $this->configureGoogle();
        config(['app.url' => 'https://erp.example.com',
            'services.google.redirect' => 'https://erp.example.com/auth/google/callback']);
        $user = User::factory()->create(['email' => 'worker@example.com', 'access_role' => 'worker',
            'access_scope' => 'self', 'account_status' => 'active']);
        $destination = '/attendance-app?tab=history#today';

        $response = $this->withSession(['url.intended' => 'https://alias.example.com'.$destination])
            ->get('https://alias.example.com/auth/google')
            ->assertRedirect('https://erp.example.com/auth/google?'.http_build_query(['return_to' => $destination]))
            ->assertSessionMissing('google_oauth_state');

        // Host-only cookies are separate: none of the alias session reaches the callback origin.
        $this->app['session.store']->flush();
        $this->get($response->headers->get('Location'))
            ->assertSessionHas('google_oauth_state')
            ->assertSessionHas('url.intended', $destination);
        $state = session('google_oauth_state');
        $this->fakeGoogleProfile($user);

        $this->get('https://erp.example.com/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'auth-code']))
            ->assertRedirect('https://erp.example.com'.$destination)
            ->assertSessionMissing('google_oauth_state')
            ->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_alias_login_preserves_explicit_erp_entry_across_host_sessions(): void
    {
        $this->configureGoogle();
        config(['app.url' => 'https://erp.example.com',
            'services.google.redirect' => 'https://erp.example.com/auth/google/callback']);
        $user = User::factory()->create(['email' => 'admin@example.com', 'access_role' => 'admin',
            'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->get('https://alias.example.com/login?erp=1')->assertOk();

        $response = $this->withSession(['url.intended' => '/attendance-app'])
            ->get('https://alias.example.com/auth/google')
            ->assertRedirect('https://erp.example.com/auth/google?'.http_build_query(['erp' => '1', 'return_to' => '/attendance-app']))
            ->assertSessionMissing('google_oauth_state');

        $this->app['session.store']->flush();
        $this->get($response->headers->get('Location'))
            ->assertSessionHas(EmailPasswordAuthService::ERP_LOGIN_SESSION, true);
        $state = session('google_oauth_state');
        $this->fakeGoogleProfile($user);

        $this->get('https://erp.example.com/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'auth-code']))
            ->assertRedirect('https://erp.example.com')
            ->assertSessionMissing(EmailPasswordAuthService::ERP_LOGIN_SESSION)
            ->assertSessionMissing('url.intended')
            ->assertSessionHas(EmailPasswordAuthService::STRONG_AUTH_SESSION, $user->id);
    }

    #[DataProvider('unsafeDestinations')]
    public function test_google_entry_does_not_forward_or_accept_an_unsafe_destination(mixed $destination): void
    {
        $this->configureGoogle();
        config(['app.url' => 'https://erp.example.com',
            'services.google.redirect' => 'https://erp.example.com/auth/google/callback']);

        $this->withSession(['url.intended' => $destination])
            ->get('https://alias.example.com/auth/google')
            ->assertRedirect('https://erp.example.com/auth/google')
            ->assertSessionMissing('google_oauth_state');

        $this->app['session.store']->flush();
        $this->get('https://erp.example.com/auth/google?'.http_build_query(['return_to' => $destination]))
            ->assertSessionHas('google_oauth_state')
            ->assertSessionMissing('url.intended');
    }

    public static function unsafeDestinations(): array
    {
        return [
            'outside host' => ['https://outside.example/attendance-app'],
            'protocol relative' => ['//outside.example/attendance-app'],
            'canonical protocol relative' => ['//erp.example.com/attendance-app'],
            'credentials' => ['https://user:password@erp.example.com/attendance-app'],
            'other port' => ['https://erp.example.com:444/attendance-app'],
            'other scheme' => ['http://erp.example.com/attendance-app'],
            'javascript scheme' => ['javascript:/attendance-app'],
            'backslash authority' => ['/\\outside.example/attendance-app'],
            'encoded backslash' => ['/%5coutside.example/attendance-app'],
            'encoded authority' => ['/%2foutside.example/attendance-app'],
            'newline' => ["/\n/outside.example/attendance-app"],
            'encoded newline' => ['/%0a/outside.example/attendance-app'],
            'retired admin' => ['/admin/member-documents'],
            'login loop' => ['/login?erp=1'],
            'oauth loop' => ['/auth/google'],
            'encoded login loop' => ['/%6cogin'],
            'relative without slash' => ['attendance-app'],
            'array' => [['/attendance-app']],
        ];
    }

    public function test_google_callback_links_registered_active_user_and_logs_in(): void
    {
        $this->configureGoogle();

        $user = User::factory()->create([
            'email' => 'worker@example.com',
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
            'google_id' => null,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-123',
                'email' => 'worker@example.com',
                'email_verified' => true,
                'name' => 'ERP Worker',
            ], 200),
        ]);

        $response = $this
            ->withSession(['google_oauth_state' => 'known-state', WorkerDeviceSession::FLAG => true])
            ->get('/auth/google/callback?state=known-state&code=auth-code');

        // 작업자는 ERP 가 아니라 자기 앱으로 간다. 자기 근무시간을 보러 로그인했는데
        // 회사 전체 화면이 뜨면 잘못 눌렀다고 생각하고 앱을 지운다.
        $response->assertRedirect('/attendance-app')
            ->assertSessionHas(EmailPasswordAuthService::STRONG_AUTH_SESSION, $user->id)
            ->assertSessionMissing(WorkerDeviceSession::FLAG);
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_google_callback_rejects_unregistered_email(): void
    {
        $this->configureGoogle();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-456',
                'email' => 'unknown@example.com',
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this
            ->withSession(['google_oauth_state' => 'known-state'])
            ->get('/auth/google/callback?state=known-state&code=auth-code');

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_google_callback_rejects_inactive_account(): void
    {
        $this->configureGoogle();

        User::factory()->create([
            'email' => 'worker@example.com',
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'suspended',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-123',
                'email' => 'worker@example.com',
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this
            ->withSession(['google_oauth_state' => 'known-state'])
            ->get('/auth/google/callback?state=known-state&code=auth-code');

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_google_callback_always_sends_admin_users_to_erp_home(): void
    {
        $this->configureGoogle();

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-admin',
                'email' => 'admin@example.com',
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this
            ->withSession([
                'google_oauth_state' => 'known-state',
                'url.intended' => '/admin/member-documents',
            ])
            ->get('/auth/google/callback?state=known-state&code=auth-code');

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_authenticated_login_page_always_redirects_to_erp_home(): void
    {
        $user = User::factory()->create([
            'access_role' => 'safety_manager',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['url.intended' => '/admin/member-documents'])
            ->get('/login');

        $response->assertRedirect('/');
    }

    public function test_google_erp_reauthentication_retires_old_qr_and_ignores_stale_landing(): void
    {
        $this->configureGoogle();
        $user = User::factory()->create(['email' => 'super@example.com', 'access_role' => 'super_admin',
            'access_scope' => 'all_sites', 'account_status' => 'active']);
        $device = PersonalAppDevice::create(['user_id' => $user->id, 'token_hash' => hash('sha256', 'trusted-device'),
            'expires_at' => now()->addDays(100)]);
        $this->actingAs($user)->withSession([PersonalAppAccessService::SESSION => $device->id,
            WorkerDeviceSession::FLAG => true, 'url.intended' => '/attendance-app'])
            ->get('/login?erp=1')->assertOk()->assertViewHas('erpLogin', true);
        $this->get('/auth/google')->assertRedirect()->assertSessionHas('google_oauth_state');
        $this->get('/auth/google/callback?error=access_denied')->assertRedirect('/login')
            ->assertSessionHasErrors('google')
            ->assertSessionMissing(PersonalAppAccessService::SESSION)
            ->assertSessionHas(EmailPasswordAuthService::ERP_LOGIN_SESSION, true);
        $this->assertGuest();
        $this->assertNotNull($device->fresh()->revoked_at);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-super', 'email' => $user->email, 'email_verified' => true,
            ], 200),
        ]);
        $this->withSession(['google_oauth_state' => 'fresh-state'])
            ->get('/auth/google/callback?state=fresh-state&code=auth-code')->assertRedirect('/')
            ->assertSessionHas(EmailPasswordAuthService::STRONG_AUTH_SESSION, $user->id)
            ->assertSessionMissing(EmailPasswordAuthService::ERP_LOGIN_SESSION)
            ->assertSessionMissing(PersonalAppAccessService::SESSION)
            ->assertSessionMissing(WorkerDeviceSession::FLAG)->assertSessionMissing('url.intended');
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->get('/')->assertOk();
    }

    public function test_login_page_explains_when_the_session_has_expired(): void
    {
        $this->configureGoogle();

        $this->get('/login?expired=1')
            ->assertOk()
            ->assertSee('로그인 세션이 만료되었습니다.');
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    private function fakeGoogleProfile(User $user): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-'.$user->id, 'email' => $user->email, 'email_verified' => true,
            ], 200),
        ]);
    }
}
