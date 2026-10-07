<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureErpMcpEnabled;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErpMcpDiscoveryTest extends TestCase
{
    public function test_feature_and_all_passport_routes_are_absent_by_default(): void
    {
        $this->get('/mcp/erp')->assertNotFound();
        $this->post('/oauth/token')->assertNotFound();
        $this->post('/oauth/erp/token')->assertNotFound();
        $this->post('/oauth/register')->assertNotFound();
        $this->assertFalse(Route::has('passport.token'));
    }

    public function test_discovery_is_canonical_https_read_only_and_has_no_registration_endpoint(): void
    {
        $this->enable();
        $this->get('https://erp.example.test/.well-known/oauth-protected-resource/mcp/erp')
            ->assertOk()->assertJsonPath('resource', 'https://erp.example.test/mcp/erp')
            ->assertJsonPath('authorization_servers.0', 'https://erp.example.test')
            ->assertJsonPath('scopes_supported.0', 'erp:read');
        $this->get('https://erp.example.test/.well-known/oauth-authorization-server')
            ->assertOk()->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('token_endpoint_auth_methods_supported', ['none'])
            ->assertJsonMissingPath('registration_endpoint')
            ->assertJsonPath('authorization_response_iss_parameter_supported', false);
    }

    public function test_bearer_challenge_has_resource_metadata_and_does_not_use_cookies(): void
    {
        $this->enable();
        $response = $this->postJson('https://erp.example.test/mcp/erp', []);
        $response->assertUnauthorized();
        $this->assertStringContainsString('resource_metadata="https://erp.example.test/.well-known/oauth-protected-resource/mcp/erp"',
            $response->headers->get('WWW-Authenticate'));
        $this->get('https://erp.example.test/mcp/erp')->assertUnauthorized();
        $this->delete('https://erp.example.test/mcp/erp')->assertUnauthorized();
    }

    public function test_http_wrong_host_origin_and_disabled_cached_route_fail_closed(): void
    {
        $this->enable();
        $this->get('http://erp.example.test/.well-known/oauth-authorization-server')->assertStatus(400);
        $this->get('https://attacker.example/.well-known/oauth-authorization-server')->assertStatus(400);
        $this->withHeader('Origin', 'https://attacker.example')
            ->get('https://erp.example.test/.well-known/oauth-authorization-server')->assertForbidden();
        config(['erp_mcp.enabled' => false]);
        $this->get('https://erp.example.test/mcp/erp')->assertNotFound();
        $this->assertContains(EnsureErpMcpEnabled::class, Route::getRoutes()->getByName('erp-mcp.oauth.token')->gatherMiddleware());
    }

    private function enable(): void
    {
        config(['erp_mcp.enabled' => true, 'erp_mcp.issuer' => 'https://erp.example.test',
            'erp_mcp.resource' => 'https://erp.example.test/mcp/erp']);
        require base_path('routes/erp_mcp.php');
        // These routes are added after framework boot in the test harness.
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }
}
