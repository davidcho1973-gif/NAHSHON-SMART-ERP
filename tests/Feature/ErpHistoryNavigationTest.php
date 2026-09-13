<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpHistoryNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_shell_renders_shared_history_and_restores_document_context(): void
    {
        $user = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $response = $this->actingAs($user)->get('/?view=wbs');
        $response->assertOk()->assertSee('js/erp-history.js', false)
            ->assertSee('erpNavigation.start()', false)->assertSee('ERPDocumentNavigate', false)
            ->assertSee("newUrl.searchParams.delete('team_code')", false);
        // Rendered fixture enables JavaScript syntax and browser navigation checks without production data.
        file_put_contents(storage_path('app/navigation-shell-fixture.html'), $response->getContent());
    }

    public function test_document_hub_uses_shared_history_without_delayed_deep_link_open(): void
    {
        $user = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $response = $this->actingAs($user)->get('/document-hub?embed=1');
        $response->assertOk()->assertSee('restoreDocumentNavigation', false)
            ->assertSee('rememberDocumentNavigation', false)
            ->assertDontSee('setTimeout(()=>openDocument(Number(requested)),400)', false);
        file_put_contents(storage_path('app/navigation-documents-fixture.html'), $response->getContent());
    }

    public function test_worker_tabs_and_ops_details_render_history_support(): void
    {
        $user = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        foreach (['/attendance-app' => 'worker', '/attendance-app/ops-room' => 'ops'] as $url => $name) {
            $response = $this->actingAs($user)->get($url);
            $response->assertOk()->assertSee('js/erp-history.js', false)->assertSee('ERPHistory.create', false);
            file_put_contents(storage_path('app/navigation-'.$name.'-fixture.html'), $response->getContent());
        }
    }
}
