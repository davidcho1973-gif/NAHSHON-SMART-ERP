<?php

namespace App\Http\Middleware;

use App\Support\Mcp\ErpMcpOAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateErpMcpConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->session()->pull('erp_mcp_auth_context');
        abort_unless(is_array($context)
            && ($context['user_id'] ?? null) === (string) $request->user('web')->getAuthIdentifier()
            && ($context['resource'] ?? null) === ErpMcpOAuth::resource()
            && ($context['issuer'] ?? null) === ErpMcpOAuth::issuer()
            && is_int($context['expires_at'] ?? null) && $context['expires_at'] > now()->getTimestamp()
            && is_string($request->input('auth_token'))
            && hash_equals((string) ($context['auth_token'] ?? ''), $request->input('auth_token'))
            && ErpMcpOAuth::client($context['client_id'] ?? null) !== null,
            403, 'This consent request expired. Start the connection again.');

        return $next($request);
    }
}
