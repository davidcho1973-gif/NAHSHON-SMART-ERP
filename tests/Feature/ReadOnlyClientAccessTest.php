<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 원청(client)·뷰어 계정은 열람 전용 — 조회는 되고 쓰기는 403.
 */
class ReadOnlyClientAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create([
            'access_role' => $role,
            'account_status' => 'active',
        ]);
    }

    public function test_client_can_read(): void
    {
        $this->actingAs($this->user('client'))
            ->postJson('/smart-company-api/api_getDailyHeadcount', ['args' => [], 'siteId' => 'ALL'])
            ->assertStatus(200);
    }

    public function test_client_cannot_write(): void
    {
        $this->actingAs($this->user('client'))
            ->postJson('/smart-company-api/api_markWbsStatus', ['args' => ['X', '완료'], 'siteId' => 'ALL'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_viewer_cannot_write(): void
    {
        $this->actingAs($this->user('viewer'))
            ->postJson('/smart-company-api/api_clockIn', ['args' => [], 'siteId' => 'ALL'])
            ->assertStatus(403);
    }

    public function test_admin_can_still_write(): void
    {
        $this->actingAs($this->user('admin'))
            ->postJson('/smart-company-api/api_markWbsStatus', ['args' => ['X', '완료'], 'siteId' => 'ALL'])
            ->assertStatus(200);
    }

    public function test_client_is_not_admin_panel_role(): void
    {
        $this->assertNotContains('client', User::ADMIN_PANEL_ROLES);
        $this->assertTrue($this->user('client')->isReadOnly());
        $this->assertFalse($this->user('site_manager')->isReadOnly());
    }
}
