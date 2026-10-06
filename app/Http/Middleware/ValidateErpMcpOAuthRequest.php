<?php

namespace App\Http\Middleware;

use App\Support\Mcp\ErpMcpOAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A single resource is validated at both ends; it cannot change during a grant. */
class ValidateErpMcpOAuthRequest
{
    public function handle(Request $request, Closure $next, string $stage): Response
    {
        if ($request->input('resource') !== ErpMcpOAuth::resource()) {
            return $this->error('invalid_target', 'The exact ERP MCP resource is required.');
        }
        $client = ErpMcpOAuth::client($request->input('client_id'));
        if (! $client || $request->has('client_secret') || $request->header('Authorization') !== null) {
            return $this->error('invalid_client', 'An approved public client is required.');
        }
        $grant = $request->input('grant_type');
        if ($stage === 'token' && ! in_array($grant, ['authorization_code', 'refresh_token'], true)) {
            return $this->error('unsupported_grant_type', 'Only authorization code and refresh grants are supported.');
        }
        if (($stage === 'authorize' || $grant === 'authorization_code')
            && ! ErpMcpOAuth::validRedirect($client, $request->input('redirect_uri'))) {
            return $this->error('invalid_request', 'The redirect URI must exactly match the registered HTTPS callback.');
        }
        $scope = $request->input('scope');
        if (($stage === 'authorize' && $scope !== ErpMcpOAuth::SCOPE)
            || ($stage === 'token' && $scope !== null && $scope !== ErpMcpOAuth::SCOPE)) {
            return $this->error('invalid_scope', 'Only erp:read is supported.');
        }
        if ($stage === 'authorize') {
            if ($request->input('response_type') !== 'code'
                || $request->input('code_challenge_method') !== 'S256'
                || ! is_string($request->input('code_challenge'))
                || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $request->input('code_challenge')) !== 1
                || ! is_string($request->input('state')) || $request->input('state') === ''
                || strlen($request->input('state')) > 2048) {
                return $this->error('invalid_request', 'Authorization code, S256 PKCE and state are required.');
            }
            $request->session()->forget(['authToken', 'authRequest', 'erp_mcp_auth_context']);
            $response = $next($request);
            if ($request->session()->has('authToken')) {
                $request->session()->put('erp_mcp_auth_context', [
                    'auth_token' => $request->session()->get('authToken'),
                    'user_id' => (string) $request->user('web')->getAuthIdentifier(),
                    'client_id' => $client->getKey(),
                    'resource' => ErpMcpOAuth::resource(),
                    'issuer' => ErpMcpOAuth::issuer(),
                    'expires_at' => now()->addMinutes(10)->getTimestamp(),
                ]);
            }

            return $response;
        }
        if ($grant === 'authorization_code' && (! is_string($request->input('code_verifier'))
            || preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $request->input('code_verifier')) !== 1)) {
            return $this->error('invalid_request', 'A valid PKCE verifier is required.');
        }
        // Never trust this attribute from input; only this middleware sets it.
        $request->attributes->set('erp_mcp_oauth_request', true);

        return $next($request);
    }

    private function error(string $error, string $description): Response
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400)
            ->header('Cache-Control', 'no-store');
    }
}
