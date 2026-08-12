<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 현장 등록이 ERP 화면에서 끝까지 동작하는가.
 *
 * 규칙 자체는 SiteAdminServiceTest 가 지킨다. 여기서는 그 앞단 — 화면이 실제로 부르는
 * API 이름이 서버에 붙어 있는지를 본다. 이름이 어긋나면 서비스가 아무리 맞아도
 * 버튼이 아무 일도 하지 않는다.
 */
class SiteCreationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create([
            'access_role' => $role,
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);
    }

    public function test_admin_can_register_a_site_through_the_erp_api(): void
    {
        $this->actingAs($this->user('admin'));

        $this->postJson('/smart-company-api/api_saveSiteAdmin', [
            'args' => [[
                'code' => 'NEW-SITE',
                'name' => 'New Site Name',
                'country' => 'US',
                'timezone' => 'America/Phoenix',
                'status' => 'active',
            ]],
            'siteId' => 'ALL',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('sites', ['code' => 'NEW-SITE', 'timezone' => 'America/Phoenix']);
    }

    public function test_the_screen_lists_sites_and_projects_together(): void
    {
        $this->actingAs($this->user('admin'));

        $this->postJson('/smart-company-api/api_getSiteAdmin', ['args' => [], 'siteId' => 'ALL'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['sites', 'projects', 'canManage']);
    }

    public function test_worker_cannot_register_a_site_through_the_api(): void
    {
        $this->actingAs($this->user('worker'));

        $this->postJson('/smart-company-api/api_saveSiteAdmin', [
            'args' => [['code' => 'HACK', 'name' => 'x', 'timezone' => 'America/Phoenix']],
            'siteId' => 'ALL',
        ]);

        $this->assertSame(0, Site::where('code', 'HACK')->count());
    }
}
