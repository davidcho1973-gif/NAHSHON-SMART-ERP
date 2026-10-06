<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Mcp\ErpMcpOAuth;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Explicit bearer-only authentication; never fall back to ERP/Passport cookies. */
class AuthenticateErpMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->bearerToken();
        if (! is_string($raw) || $raw === '') {
            return $this->challenge();
        }
        try {
            // Parsing is untrusted structural validation only. No claim grants access.
            $jwt = (new Parser(new JoseEncoder))->parse($raw);
        } catch (Throwable) {
            return $this->challenge('invalid_token');
        }
        if (! $jwt instanceof UnencryptedToken) {
            return $this->challenge('invalid_token');
        }
        $claims = $jwt->claims();
        if (! is_string($claims->get('jti')) || ! is_string($claims->get('sub'))
            || ! is_array($claims->get('aud')) || count($claims->get('aud')) !== 2
            || ! is_string($claims->get('aud')[0] ?? null)
            || ! is_string($claims->get('aud')[1] ?? null)
            || ! $claims->get('exp') instanceof \DateTimeImmutable
            || ! $claims->get('iat') instanceof \DateTimeImmutable
            || ! $claims->get('nbf') instanceof \DateTimeImmutable) {
            return $this->challenge('invalid_token');
        }
        try {
            // Signature, time bounds and persisted revocation precede claim trust.
            $psr = app(ResourceServer::class)->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return $this->challenge('invalid_token');
        }
        $clientId = $psr->getAttribute('oauth_client_id');
        if ($claims->get('iss') !== ErpMcpOAuth::issuer()
            || $claims->get('aud') !== [$clientId, ErpMcpOAuth::resource()]
            || ! $claims->has('exp') || ! $claims->has('iat') || ! $claims->has('nbf')
            || ErpMcpOAuth::client($clientId) === null) {
            return $this->challenge('invalid_token');
        }
        if ($claims->get('scopes') !== [ErpMcpOAuth::SCOPE]) {
            return $this->challenge('insufficient_scope', 403);
        }
        // Cross-check persisted subject/client/scope/expiry, not just the JWT jti.
        $stored = Passport::token()->newQuery()->whereKey($psr->getAttribute('oauth_access_token_id'))->first();
        $userId = $psr->getAttribute('oauth_user_id');
        if (! $stored || $stored->revoked || ! $stored->expires_at || $stored->expires_at->isPast()
            || $stored->resource !== ErpMcpOAuth::resource() || $stored->issuer !== ErpMcpOAuth::issuer()
            || (string) $stored->user_id !== (string) $userId
            || (string) $stored->client_id !== $clientId || $stored->scopes !== [ErpMcpOAuth::SCOPE]) {
            return $this->challenge('invalid_token');
        }
        $user = is_string($userId) ? User::query()->find($userId) : null;
        if (! ErpMcpOAuth::eligible($user)) {
            return $this->challenge('invalid_token');
        }
        $user->withAccessToken(AccessToken::fromPsrRequest($psr));
        // Set only this request's identity. Never log in or change the web guard.
        $resolver = fn ($guard = null) => $guard === null || $guard === 'erp-mcp' ? $user : null;
        $previousRequestResolver = $request->getUserResolver();
        $request->setUserResolver($resolver);
        $request->attributes->set('erp_mcp_client_id', $clientId);

        // Laravel MCP Request::user() uses this resolver directly. Restore it for
        // subsequent requests in long-lived workers; never mutate the web guard.
        $auth = app('auth');
        $previousResolver = $auth->userResolver();
        $auth->resolveUsersUsing($resolver);
        try {
            return $next($request);
        } finally {
            $auth->resolveUsersUsing($previousResolver);
            $request->setUserResolver($previousRequestResolver);
        }
    }

    private function challenge(?string $error = null, int $status = 401): Response
    {
        $header = 'Bearer resource_metadata="'.ErpMcpOAuth::issuer().'/.well-known/oauth-protected-resource/mcp/erp", scope="'.ErpMcpOAuth::SCOPE.'"';
        if ($error !== null) {
            $header .= ', error="'.$error.'"';
        }

        return response()->json(['error' => $error ?? 'unauthorized'], $status)
            ->header('WWW-Authenticate', $header)->header('Cache-Control', 'no-store');
    }
}
