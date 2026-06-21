<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

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
            ->withSession(['google_oauth_state' => 'known-state'])
            ->get('/auth/google/callback?state=known-state&code=auth-code');

        $response->assertRedirect('/');
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

    public function test_google_callback_sends_admin_users_to_admin_panel(): void
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
            ->withSession(['google_oauth_state' => 'known-state'])
            ->get('/auth/google/callback?state=known-state&code=auth-code');

        $response->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin->fresh());
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }
}
