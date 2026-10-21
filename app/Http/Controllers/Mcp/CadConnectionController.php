<?php

namespace App\Http\Controllers\Mcp;

use App\OAuth\ErpTokenFamily;
use App\Support\Mcp\CadMcpOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

final class CadConnectionController
{
    public function config(Request $request)
    {
        $available = CadMcpOAuth::configured() && CadMcpOAuth::keysReady();
        $tokens = Passport::token()->newQuery()->where('user_id', $request->user()->id)
            ->where('resource', CadMcpOAuth::resource())->where('issuer', CadMcpOAuth::issuer())
            ->where('revoked', false)->where(function ($query): void {
                $query->where('expires_at', '>', now()->toIso8601String())
                    ->orWhereIn('id', Passport::refreshToken()->newQuery()->where('revoked', false)
                        ->where('expires_at', '>', now()->toIso8601String())->select('access_token_id'));
            })->orderByDesc('created_at')->limit(200)->get();
        $clients = Passport::client()->newQuery()->whereIn('id', $tokens->pluck('client_id'))->get()->keyBy('id');

        return response()->json([
            'available' => $available, 'serverUrl' => CadMcpOAuth::configured() ? CadMcpOAuth::resource() : null,
            'reason' => $available ? null : '관리자가 CAD AI 연결의 HTTPS 주소와 OAuth 서명 키를 설정해야 합니다.',
            'connections' => $tokens->unique('family_id')->map(fn ($token) => [
                'id' => $token->family_id, 'name' => $clients->get($token->client_id)?->name ?? 'AI 연결',
                'createdAt' => $token->created_at?->toIso8601String(),
            ])->values(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, string $connection)
    {
        DB::connection(config('passport.connection'))->transaction(function () use ($request, $connection): void {
            abort_unless(Passport::token()->newQuery()->where('user_id', $request->user()->id)
                ->where('resource', CadMcpOAuth::resource())->where('issuer', CadMcpOAuth::issuer())
                ->where('family_id', $connection)->exists(), 404);
            if (ErpTokenFamily::lock($connection)) {
                ErpTokenFamily::revoke($connection);
            }
            // Pending commands from this connection must not outlive revocation.
            DB::table('cad_ai_commands')->whereIn('session_id', DB::table('cad_ai_sessions')->where('user_id', $request->user()->id)->select('id'))
                ->where('oauth_family_id', $connection)->where('status', 'pending')
                ->update(['status' => 'rejected', 'updated_at' => now()]);
        });

        return response()->json(['revoked' => true])->header('Cache-Control', 'private, no-store');
    }

    /** RFC 7591 public PKCE clients: registration grants no user or drawing access. */
    public function register(Request $request, ClientRepository $clients)
    {
        abort_unless(CadMcpOAuth::keysReady(), 503, 'CAD OAuth is not configured.');
        abort_if(strlen($request->getContent()) > 8192, 413);
        $data = $request->validate([
            'client_name' => 'required|string|max:120', 'redirect_uris' => 'required|array|min:1|max:5',
            'redirect_uris.*' => 'required|string|max:2048',
            'token_endpoint_auth_method' => 'sometimes|in:none',
            'grant_types' => 'sometimes|array|min:1|max:2',
            'grant_types.*' => 'in:authorization_code,refresh_token',
            'response_types' => 'sometimes|array|size:1', 'response_types.*' => 'in:code',
            'scope' => 'sometimes|in:cad:session',
        ]);
        abort_if(isset($data['grant_types']) && ! in_array('authorization_code', $data['grant_types'], true), 422, 'authorization_code is required.');
        foreach ($data['redirect_uris'] as $uri) {
            $p = parse_url($uri);
            abort_unless(is_array($p) && ($p['scheme'] ?? null) === 'https' && ! empty($p['host'])
                && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['fragment'])
                && ! str_contains($uri, '*') && ! preg_match('/[\x00-\x20\x7f]/', $uri), 422, 'An exact HTTPS redirect URI is required.');
        }
        $client = DB::transaction(function () use ($clients, $data) {
            $client = $clients->createAuthorizationCodeGrantClient($data['client_name'], array_values(array_unique($data['redirect_uris'])), false, null);
            // Restrict a public registration to this CAD OAuth profile only.
            $client->forceFill(['provider' => 'users'])->save();
            DB::table('cad_ai_oauth_clients')->insert(['client_id' => $client->id, 'created_at' => now()]);

            return $client;
        });

        return response()->json([
            'client_id' => $client->id, 'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris, 'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
            'scope' => CadMcpOAuth::SCOPE, 'client_id_issued_at' => now()->getTimestamp(),
        ], 201)->header('Cache-Control', 'no-store');
    }
}
