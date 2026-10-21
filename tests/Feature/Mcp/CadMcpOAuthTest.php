<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Support\Mcp\CadMcpOAuth;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CadMcpOAuthTest extends TestCase
{
    use DatabaseMigrations;

    private const ORIGIN = 'https://erp.example.test';

    private const CALLBACK = 'https://ai.example.test/cad-callback';

    private const VERIFIER = 'cad-test-verifier-0123456789012345678901234567890123456789';

    private static ?array $keys = null;

    private string $client;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$keys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$keys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)),
            'cad_ai.enabled' => true, 'cad_ai.origin' => self::ORIGIN,
            'passport.private_key' => self::$keys['private'], 'passport.public_key' => self::$keys['public'],
            'erp_mcp.enabled' => true, 'erp_mcp.issuer' => self::ORIGIN, 'erp_mcp.resource' => self::ORIGIN.'/mcp/erp']);
        require base_path('routes/erp_mcp.php');
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
        $this->client = $this->postJson(self::ORIGIN.'/oauth/cad/register', [
            'client_name' => 'Synthetic CAD connector', 'redirect_uris' => [self::CALLBACK],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->assertJsonPath('scope', 'cad:session')->assertJsonMissingPath('client_secret')->json('client_id');
    }

    public function test_discovery_is_canonical_and_registration_never_grants_data_access(): void
    {
        $this->getJson(self::ORIGIN.'/.well-known/oauth-protected-resource/mcp/cad')
            ->assertOk()->assertJsonPath('resource', self::ORIGIN.'/mcp/cad')
            ->assertJsonPath('authorization_servers.0', self::ORIGIN.'/oauth/cad');
        $this->getJson(self::ORIGIN.'/.well-known/oauth-authorization-server/oauth/cad')
            ->assertOk()->assertJsonPath('issuer', self::ORIGIN.'/oauth/cad')
            ->assertJsonPath('registration_endpoint', self::ORIGIN.'/oauth/cad/register');
        $response = $this->postJson(self::ORIGIN.'/mcp/cad', [])->assertUnauthorized();
        $this->assertStringContainsString('/.well-known/oauth-protected-resource/mcp/cad', $response->headers->get('WWW-Authenticate'));
        $this->withHeader('Origin', 'https://untrusted.example')->getJson(self::ORIGIN.'/.well-known/oauth-protected-resource/mcp/cad')->assertForbidden();
        $this->flushHeaders();
        $this->getJson('https://wrong.example/.well-known/oauth-protected-resource/mcp/cad')->assertStatus(400);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_dcr_rejects_confidential_wildcard_http_and_broader_scope_clients(): void
    {
        foreach ([['redirect_uris' => ['http://ai.example/callback']],
            ['redirect_uris' => ['https://ai.example/*']], ['redirect_uris' => ['https://user:pass@ai.example/callback']],
            ['token_endpoint_auth_method' => 'client_secret_basic'], ['scope' => 'erp:read'],
            ['grant_types' => ['refresh_token']]] as $change) {
            $this->postJson(self::ORIGIN.'/oauth/cad/register', array_replace([
                'client_name' => 'Invalid synthetic client', 'redirect_uris' => [self::CALLBACK]], $change))->assertStatus(422);
        }
        $this->assertDatabaseCount('cad_ai_oauth_clients', 1);
    }

    public function test_real_pkce_consent_supports_cad_user_and_cannot_be_used_for_erp_or_cookie_apis(): void
    {
        $user = $this->actor();
        $token = $this->exchange($this->authorize($user))->assertOk()->json('access_token');
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $this->assertSame(self::ORIGIN.'/oauth/cad', $claims['iss']);
        $this->assertSame([$this->client, self::ORIGIN.'/mcp/cad'], $claims['aud']);
        $this->assertSame(['cad:session'], $claims['scopes']);
        Auth::guard('web')->logout();
        $this->flushSession();
        $this->mcp($token)->assertOk()->assertJsonPath('result.isError', false);
        $this->assertNull(Auth::guard('web')->user());
        $this->withToken($token)->postJson(self::ORIGIN.'/mcp/erp', [])->assertUnauthorized();
        $this->withToken($token)->getJson('/admin-api/cad/ai/config')->assertUnauthorized();
    }

    public function test_strong_login_exact_redirect_pkce_and_cad_scope_are_required(): void
    {
        $user = $this->actor();
        $this->actingAs($user)->get($this->authorizeUrl())->assertForbidden();
        $this->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->id]);
        foreach ([['scope' => 'erp:read'], ['scope' => 'cad:session erp:read'],
            ['resource' => self::ORIGIN.'/mcp/erp'], ['redirect_uri' => self::CALLBACK.'/extra'],
            ['code_challenge_method' => 'plain'], ['client_id' => 'not-a-uuid'], ['state' => '']] as $change) {
            $this->get($this->authorizeUrl($change))->assertStatus(400);
        }
        $this->withSession([WorkerDeviceSession::FLAG => true])->get($this->authorizeUrl())->assertForbidden();
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_refresh_rotation_code_replay_and_owner_revocation_invalidate_connection(): void
    {
        $user = $this->actor();
        $code = $this->authorize($user);
        $this->exchange($code, ['code_verifier' => str_repeat('z', 43)])->assertStatus(400);
        $first = $this->exchange($code)->assertOk()->json();
        $next = $this->refresh($first['refresh_token'])->assertOk()->json();
        $this->mcp($first['access_token'])->assertUnauthorized();
        $this->mcp($next['access_token'])->assertOk();
        $this->refresh($first['refresh_token'])->assertStatus(400);
        $this->mcp($next['access_token'])->assertUnauthorized();
        $this->flushHeaders();
        $fresh = $this->exchange($this->authorize($user))->assertOk()->json('access_token');
        $this->flushHeaders();
        $config = $this->getJson('/admin-api/cad/ai/config')->assertOk()->assertJsonPath('available', true);
        $family = $config->json('connections.0.id');
        $this->assertNotEmpty($family);
        $other = $this->actor();
        $this->actingAs($other)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $other->id])
            ->deleteJson('/admin-api/cad/ai/connections/'.$family)->assertNotFound();
        $this->actingAs($user)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->id])
            ->deleteJson('/admin-api/cad/ai/connections/'.$family)->assertOk();
        $this->mcp($fresh)->assertUnauthorized();
    }

    public function test_cad_codes_and_refresh_tokens_cannot_be_retargeted_to_erp_even_when_client_is_pilot_allowlisted(): void
    {
        $user = $this->actor();
        $user->update(['access_role' => 'super_admin']);
        config(['erp_mcp.user_ids' => [(string) $user->id], 'erp_mcp.client_ids' => [$this->client]]);
        $code = $this->authorize($user);
        $this->post(self::ORIGIN.'/oauth/erp/token', ['grant_type' => 'authorization_code',
            'client_id' => $this->client, 'redirect_uri' => self::CALLBACK, 'resource' => self::ORIGIN.'/mcp/erp',
            'code_verifier' => self::VERIFIER, 'code' => $code])->assertStatus(400);
        $tokens = $this->exchange($code)->assertOk()->json();
        $this->post(self::ORIGIN.'/oauth/erp/token', ['grant_type' => 'refresh_token',
            'client_id' => $this->client, 'resource' => self::ORIGIN.'/mcp/erp', 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
        $this->mcp($tokens['access_token'])->assertOk();
    }

    public function test_missing_or_mismatched_signing_keys_are_reported_without_breaking_cad_pages(): void
    {
        config(['passport.private_key' => 'invalid synthetic key']);
        $this->assertFalse(CadMcpOAuth::keysReady());
        $user = $this->actor();
        $this->actingAs($user)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->id]);
        $this->getJson('/admin-api/cad/ai/config')->assertOk()->assertJsonPath('available', false);
        $this->get($this->authorizeUrl())->assertStatus(503);
        $this->postJson(self::ORIGIN.'/oauth/cad/register', ['client_name' => 'No key', 'redirect_uris' => [self::CALLBACK]])->assertStatus(503);
    }

    public function test_job_managed_cad_user_can_manage_own_connection_without_drawing_delete_rights(): void
    {
        $user = $this->actor();
        $user->update(['job_role' => 'engineering', 'job_permissions' => ['progress' => ['view']]]);
        $this->exchange($this->authorize($user))->assertOk();
        $this->flushHeaders();
        $family = $this->getJson('/admin-api/cad/ai/config')->assertOk()->json('connections.0.id');
        $this->deleteJson('/admin-api/cad/ai/connections/'.$family)->assertOk();
    }

    public function test_signed_mcp_command_crosses_browser_queue_and_ack_with_the_same_token_family(): void
    {
        Storage::fake('local');
        config(['cad.disk' => 'local', 'cad.converter_binary' => null]);
        $user = $this->actor();
        $token = $this->exchange($this->authorize($user))->assertOk()->json('access_token');
        $company = Company::create(['code' => 'CAD-OAUTH', 'name' => 'Synthetic', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'CAD-OAUTH', 'name' => 'Synthetic', 'status' => 'active']);
        $drawing = $this->postJson('/admin-api/cad', ['site_id' => $site->id, 'title' => 'Synthetic OAuth drawing',
            'file' => new UploadedFile(base_path('tests/Fixtures/cad-line.dxf'), 'line.dxf', 'application/dxf', null, true)])->assertCreated()->json('id');
        $context = ['version' => 1, 'documentHash' => str_repeat('a', 64), 'unit' => 'm', 'scope' => 'selection', 'selection' => ['L1'],
            'entities' => [['id' => 'L1', 'type' => 'LINE', 'layer' => '0', 'editable' => true,
                'geometry' => ['p' => ['x' => 0, 'y' => 0], 'q' => ['x' => 3, 'y' => 4]],
                'style' => ['color' => null, 'lineType' => 'BYLAYER', 'lineWeight' => -1],
                'quantities' => ['length' => 5, 'area' => null, 'available' => true, 'reason' => null]]],
            'layers' => [['name' => '0', 'color' => '#ffffff', 'visible' => true, 'locked' => false]],
            'totals' => ['length' => 5, 'area' => 0, 'unavailableCount' => 0]];
        $shared = $this->postJson('/admin-api/cad/ai/sessions', ['drawingId' => $drawing, 'revision' => 1,
            'context' => $context, 'allowEdits' => true])->assertCreated()->json();
        $response = $this->mcp($token, 'submit_cad_actions', ['sessionId' => $shared['sessionId'],
            'documentHash' => $context['documentHash'], 'idempotencyKey' => 'actual-oauth-move',
            'actions' => [['type' => 'move', 'entityIds' => ['L1'], 'dx' => 2, 'dy' => 0]]])->assertOk()->assertJsonPath('result.isError', false);
        $command = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('pending', $command['status']);
        $this->assertFalse($command['applied']);
        $this->assertSame(Passport::token()->newQuery()->value('family_id'), DB::table('cad_ai_commands')->value('oauth_family_id'));
        $this->flushHeaders();
        $base = '/admin-api/cad/ai/sessions/'.$shared['sessionId'];
        $headers = ['X-CAD-Editor-Token' => $shared['editorToken']];
        $this->getJson($base.'/commands', $headers)->assertOk()->assertJsonPath('commands.0.id', $command['id']);
        $context['documentHash'] = str_repeat('b', 64);
        $context['entities'][0]['geometry']['p']['x'] = 2;
        $context['entities'][0]['geometry']['q']['x'] = 5;
        $this->postJson($base.'/commands/'.$command['id'].'/result', ['status' => 'applied', 'message' => 'Applied locally.', 'context' => $context], $headers)->assertOk();
        $status = $this->mcp($token, 'cad_command_status', ['sessionId' => $shared['sessionId'], 'commandId' => $command['id']])->assertOk();
        $this->assertSame('applied', json_decode($status->json('result.content.0.text'), true)['status']);
        $this->assertDatabaseCount('cad_drawing_revisions', 1);
        $this->assertDatabaseCount('claim_work_records', 0);
    }

    private function actor(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
    }

    private function authorizeUrl(array $change = []): string
    {
        return self::ORIGIN.'/oauth/cad/authorize?'.http_build_query(array_replace([
            'response_type' => 'code', 'client_id' => $this->client, 'redirect_uri' => self::CALLBACK,
            'resource' => self::ORIGIN.'/mcp/cad', 'scope' => 'cad:session', 'state' => 'synthetic-cad-state',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=')], $change));
    }

    private function authorize(User $user): string
    {
        $this->flushHeaders();
        $this->actingAs($user)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->id]);
        $this->get($this->authorizeUrl())->assertOk()->assertSee('CAD AI 연결 승인')->assertSee(self::CALLBACK);
        $response = $this->post(self::ORIGIN.'/oauth/cad/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('synthetic-cad-state', $query['state']);

        return $query['code'];
    }

    private function exchange(string $code, array $overrides = [])
    {
        $this->flushHeaders();

        return $this->post(self::ORIGIN.'/oauth/cad/token', array_replace([
            'grant_type' => 'authorization_code', 'client_id' => $this->client, 'redirect_uri' => self::CALLBACK,
            'resource' => self::ORIGIN.'/mcp/cad', 'code_verifier' => self::VERIFIER, 'code' => $code], $overrides));
    }

    private function refresh(string $token)
    {
        $this->flushHeaders();

        return $this->post(self::ORIGIN.'/oauth/cad/token', ['grant_type' => 'refresh_token', 'client_id' => $this->client,
            'resource' => self::ORIGIN.'/mcp/cad', 'refresh_token' => $token]);
    }

    private function mcp(string $token, string $name = 'list_cad_sessions', array $arguments = [])
    {
        return $this->withToken($token)->withHeader('MCP-Protocol-Version', '2025-11-25')
            ->postJson(self::ORIGIN.'/mcp/cad', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments ?: (object) []]]);
    }
}
