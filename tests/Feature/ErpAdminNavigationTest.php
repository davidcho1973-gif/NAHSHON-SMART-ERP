<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpAdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_sees_admin_entry_on_erp_home(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('data-admin-entry', escape: false)
            ->assertSee(url('/admin'));
    }

    public function test_worker_does_not_see_admin_entry_on_erp_home(): void
    {
        $worker = User::factory()->create([
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);

        $this->actingAs($worker)
            ->get('/')
            ->assertOk()
            ->assertDontSee('data-admin-entry', escape: false);
    }

    public function test_erp_home_redirects_expired_api_sessions_to_login(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee("credentials: 'same-origin'", escape: false)
            ->assertSee('status !== 401 && status !== 419', escape: false)
            ->assertSee("window.location.replace('/login?expired=1')", escape: false);
    }

    public function test_admin_panel_has_erp_home_navigation_link(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $this->actingAs($admin)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee('ERP 홈')
            ->assertSee(route('smart-company.index'));
    }
}
