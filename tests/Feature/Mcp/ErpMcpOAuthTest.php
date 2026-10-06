<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\User;
use App\OAuth\ErpAuthCodeRepository;
use App\Services\Auth\EmailPasswordAuthService;
use App\Support\WorkerDeviceSession;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ErpMcpOAuthTest extends TestCase
{
    use DatabaseMigrations;

    private const ORIGIN = 'https://erp.example.test';

    private const CLIENT = '00000000-0000-4000-8000-000000000001';

    private const CALLBACK = 'https://chatgpt.example.test/exact-connector-callback';

    private const VERIFIER = 'test-verifier-0123456789012345678901234567890123456789';

    /** Ephemeral synthetic test material, generated in memory and never committed or deployed. */
    private static ?array $testKeyPair = null;

    private static function ephemeralKeyPair(): array
    {
        if (self::$testKeyPair === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false || ! openssl_pkey_export($key, $private)) {
                throw new \RuntimeException('Unable to create isolated OAuth test key.');
            }
            $details = openssl_pkey_get_details($key);
            if (! is_array($details) || ! isset($details['key'])) {
                throw new \RuntimeException('Unable to read isolated OAuth test public key.');
            }
            self::$testKeyPair = ['private' => $private, 'public' => $details['key']];
        }

        return self::$testKeyPair;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            // Fixed, non-secret encryption material for this isolated test process.
            'app.key' => 'base64:'.base64_encode(str_repeat('test', 8)),
            'erp_mcp.enabled' => true, 'erp_mcp.issuer' => self::ORIGIN,
            'erp_mcp.resource' => self::ORIGIN.'/mcp/erp', 'erp_mcp.client_ids' => [self::CLIENT],
            'passport.private_key' => self::ephemeralKeyPair()['private'],
            'passport.public_key' => self::ephemeralKeyPair()['public'],
        ]);
        require base_path('routes/erp_mcp.php');
        // These routes are added after framework boot in the test harness.
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
        Client::query()->create([
            'id' => self::CLIENT, 'name' => 'Test-only ERP connector', 'secret' => null,
            'provider' => 'users', 'redirect_uris' => [self::CALLBACK],
            'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
    }

    public function test_real_code_pkce_exchange_produces_bearer_only_mcp_identity_and_no_session_api_access(): void
    {
        $user = $this->actor();
        Company::query()->create(['code' => 'OAUTH', 'name' => 'Bearer-only company', 'status' => 'active']);
        $token = $this->exchange($this->authorize($user))->assertOk()->json('access_token');
        Auth::guard('web')->logout();
        $this->flushSession();
        $response = $this->mcp($token)->assertOk();
        $this->assertStringContainsString('Bearer-only company', $response->getContent());
        $this->assertNotSame(true, $response->json('result.isError'));
        $this->assertNull(Auth::guard('web')->user());
        $this->withToken($token)->getJson('/')->assertUnauthorized();
    }

    public function test_token_is_rejected_immediately_after_account_or_client_revocation(): void
    {
        $user = $this->actor();
        $token = $this->exchange($this->authorize($user))->assertOk()->json('access_token');
        $user->update(['account_status' => 'suspended']);
        $this->mcp($token)->assertUnauthorized();
        $user->update(['account_status' => 'active']);
        Client::query()->whereKey(self::CLIENT)->update(['revoked' => true]);
        $this->mcp($token)->assertUnauthorized();
    }

    public function test_authentication_cookies_never_satisfy_mcp_and_weak_sessions_cannot_consent(): void
    {
        $user = $this->actor();
        $this->actingAs($user)->postJson(self::ORIGIN.'/mcp/erp', [])->assertUnauthorized();
        $this->withSession([WorkerDeviceSession::FLAG => true])
            ->get(self::ORIGIN.'/oauth/erp/authorize?'.http_build_query($this->authorizationParameters()))->assertForbidden();
    }

    public function test_exact_resource_and_redirect_s256_and_scope_are_required(): void
    {
        $this->actingAs($user = $this->actor())->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->id]);
        foreach ([
            ['resource' => null], ['resource' => 'https://other.example/mcp/erp'],
            ['redirect_uri' => self::CALLBACK.'/extra'], ['code_challenge_method' => 'plain'],
            ['scope' => '*'], ['scope' => 'erp:read erp:write'], ['state' => ''],
            ['client_id' => '00000000-0000-4000-8000-000000000002'],
        ] as $change) {
            $this->get(self::ORIGIN.'/oauth/erp/authorize?'.http_build_query(array_replace($this->authorizationParameters(), $change)))
                ->assertStatus(400);
        }
        $this->post(self::ORIGIN.'/oauth/erp/token', ['resource' => self::ORIGIN.'/mcp/erp',
            'client_id' => self::CLIENT, 'grant_type' => 'client_credentials'])->assertStatus(400);
    }

    public function test_wrong_verifier_does_not_consume_code_but_success_and_replay_revoke_grant(): void
    {
        $code = $this->authorize($this->actor());
        $this->exchange($code, ['code_verifier' => str_repeat('z', 43)])->assertStatus(400);
        $token = $this->exchange($code)->assertOk()->json('access_token');
        $this->exchange($code)->assertStatus(400);
        $this->mcp($token)->assertUnauthorized();
    }

    public function test_refresh_rotates_and_replay_revokes_successor(): void
    {
        $tokens = $this->exchange($this->authorize($this->actor()))->assertOk()->json();
        $next = $this->refresh($tokens['refresh_token'])->assertOk()->json();
        $this->mcp($tokens['access_token'])->assertUnauthorized();
        $this->mcp($next['access_token'])->assertOk();
        $this->refresh($tokens['refresh_token'])->assertStatus(400);
        $this->mcp($next['access_token'])->assertUnauthorized();
        $this->refresh($next['refresh_token'])->assertStatus(400);
    }

    public function test_resource_changes_cannot_retarget_an_existing_code_or_refresh_grant(): void
    {
        $tokens = $this->exchange($this->authorize($this->actor()))->assertOk()->json();
        config(['erp_mcp.issuer' => 'https://new.example.test', 'erp_mcp.resource' => 'https://new.example.test/mcp/erp']);
        $this->post('https://new.example.test/oauth/erp/token', [
            'client_id' => self::CLIENT, 'resource' => 'https://new.example.test/mcp/erp',
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ])->assertStatus(400);
    }

    public function test_code_redemption_repository_requires_transaction_for_row_lock(): void
    {
        $this->authorize($this->actor());
        // Verify the real PostgreSQL locking query, then the no-transaction guard.
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $id = Passport::authCode()->newQuery()->value('id');
        $this->assertFalse(DB::transaction(fn () => app(ErpAuthCodeRepository::class)->isAuthCodeRevoked($id)));
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains(strtolower($sql), 'for update')));
        $this->expectException(\LogicException::class);
        app(ErpAuthCodeRepository::class)->isAuthCodeRevoked($id);
    }

    public function test_pilot_allowlist_and_super_admin_role_are_rechecked_on_every_call(): void
    {
        $user = $this->actor();
        $token = $this->exchange($this->authorize($user))->assertOk()->json('access_token');
        $user->update(['access_role' => 'admin']);
        $this->mcp($token)->assertUnauthorized();
        $user->update(['access_role' => 'super_admin']);
        config(['erp_mcp.user_ids' => []]);
        $this->mcp($token)->assertUnauthorized();
    }

    public function test_bearer_identity_overrides_a_different_web_actor_and_restores_auth_resolver(): void
    {
        $token = $this->exchange($this->authorize($this->actor()))->assertOk()->json('access_token');
        Company::query()->create(['code' => 'BEARER', 'name' => 'Bearer identity wins', 'status' => 'active']);
        $other = User::factory()->create(['access_role' => 'viewer', 'account_status' => 'active']);
        $this->actingAs($other);
        $resolver = app('auth')->userResolver();
        $this->assertStringContainsString('Bearer identity wins', $this->mcp($token)->assertOk()->getContent());
        $this->assertSame($resolver, app('auth')->userResolver());
        $this->assertSame($other->id, Auth::guard('web')->id());
    }

    public function test_signature_issuer_audience_expiry_and_scope_are_validated(): void
    {
        $token = $this->exchange($this->authorize($this->actor()))->assertOk()->json('access_token');
        $parts = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        foreach ([
            ['iss' => 'https://wrong.example'],
            ['aud' => [self::CLIENT]],
            ['aud' => [self::CLIENT, 'https://other.example/mcp/erp']],
            ['exp' => time() - 60],
            ['nbf' => time() + 3600],
        ] as $override) {
            $signed = JWT::encode(array_replace($claims, $override),
                self::ephemeralKeyPair()['private'], 'RS256');
            $this->mcp($signed)->assertUnauthorized();
        }
        $badScope = JWT::encode(array_replace($claims, ['scopes' => ['*']]),
            self::ephemeralKeyPair()['private'], 'RS256');
        $this->mcp($badScope)->assertForbidden();
        $parts[2][0] = $parts[2][0] === 'a' ? 'b' : 'a';
        $this->mcp(implode('.', $parts))->assertUnauthorized();
    }

    public function test_declined_or_lapsed_consent_never_issues_a_code(): void
    {
        $this->actingAs($user = $this->actor())->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->id]);
        $url = self::ORIGIN.'/oauth/erp/authorize?'.http_build_query($this->authorizationParameters());
        $this->get($url)->assertOk();
        $authToken = session('authToken');
        $response = $this->delete(self::ORIGIN.'/oauth/erp/authorize', ['auth_token' => $authToken])->assertRedirect();
        $this->assertStringContainsString('error=access_denied', $response->headers->get('Location'));
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->post(self::ORIGIN.'/oauth/erp/authorize', ['auth_token' => $authToken])->assertForbidden();
        $this->get($url)->assertOk();
        $authToken = session('authToken');
        $this->travel(11)->minutes();
        try {
            $this->post(self::ORIGIN.'/oauth/erp/authorize', ['auth_token' => $authToken])->assertForbidden();
        } finally {
            $this->travelBack();
        }
    }

    public function test_concurrent_code_redemption_has_only_one_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the PostgreSQL concurrency regression.');
        }
        $this->authorize($this->actor());
        $id = Passport::authCode()->newQuery()->value('id');
        $directory = sys_get_temp_dir().'/erp-oauth-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        DB::disconnect();
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    DB::purge();
                    if ($index === 1) {
                        $deadline = microtime(true) + 10;
                        while (! file_exists($directory.'/locked') && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (! file_exists($directory.'/locked')) {
                            throw new \RuntimeException('First redemption did not acquire lock.');
                        }
                        file_put_contents($directory.'/contending', 'yes');
                    }
                    $won = DB::transaction(function () use ($index, $id, $directory): bool {
                        $repository = app(ErpAuthCodeRepository::class);
                        if ($repository->isAuthCodeRevoked($id)) {
                            return false;
                        }
                        if ($index === 0) {
                            file_put_contents($directory.'/locked', 'yes');
                            $deadline = microtime(true) + 10;
                            while (! file_exists($directory.'/contending') && microtime(true) < $deadline) {
                                usleep(10000);
                            }
                            usleep(250000);
                        }
                        $repository->revokeAuthCode($id);

                        return true;
                    });
                    file_put_contents($directory.'/result-'.$index, $won ? 'won' : 'rejected');
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($directory.'/result-'.$index, get_class($exception).': '.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        try {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $this->assertSame('won', file_get_contents($directory.'/result-0'));
            $this->assertSame('rejected', file_get_contents($directory.'/result-1'));
        } finally {
            DB::purge();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function actor(): User
    {
        $user = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        config(['erp_mcp.user_ids' => [(string) $user->id]]);

        return $user;
    }

    private function authorizationParameters(): array
    {
        return ['response_type' => 'code', 'client_id' => self::CLIENT, 'redirect_uri' => self::CALLBACK,
            'resource' => self::ORIGIN.'/mcp/erp', 'scope' => 'erp:read', 'state' => 'test-csrf-state',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=')];
    }

    private function authorize(User $user): string
    {
        $this->actingAs($user)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->id]);
        $this->get(self::ORIGIN.'/oauth/erp/authorize?'.http_build_query($this->authorizationParameters()))
            ->assertOk()->assertSee('ERP 읽기 전용 연결 승인');
        $authToken = session('authToken');
        $response = $this->post(self::ORIGIN.'/oauth/erp/authorize', ['auth_token' => $authToken])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('test-csrf-state', $query['state']);

        return $query['code'];
    }

    private function exchange(string $code, array $overrides = [])
    {
        $this->flushHeaders();

        return $this->post(self::ORIGIN.'/oauth/erp/token', array_replace([
            'grant_type' => 'authorization_code', 'client_id' => self::CLIENT,
            'redirect_uri' => self::CALLBACK, 'resource' => self::ORIGIN.'/mcp/erp',
            'code_verifier' => self::VERIFIER, 'code' => $code,
        ], $overrides));
    }

    private function refresh(string $token)
    {
        $this->flushHeaders();

        return $this->post(self::ORIGIN.'/oauth/erp/token', ['grant_type' => 'refresh_token',
            'client_id' => self::CLIENT, 'resource' => self::ORIGIN.'/mcp/erp', 'refresh_token' => $token]);
    }

    private function mcp(string $token)
    {
        return $this->withToken($token)->withHeader('MCP-Protocol-Version', '2025-11-25')
            ->postJson(self::ORIGIN.'/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1,
                'method' => 'tools/call', 'params' => ['name' => 'list_erp_companies', 'arguments' => (object) []]]);
    }
}
