<?php

namespace App\Http\Middleware;

use App\Support\Mcp\McpOAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpMcpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(McpOAuth::enabled(), 404);
        abort_unless(McpOAuth::configured(), 503, 'ERP MCP configuration is incomplete.');
        abort_unless($request->isSecure() && $request->getSchemeAndHttpHost() === McpOAuth::origin(),
            400, 'Use the configured HTTPS origin.');

        $origin = $request->header('Origin');
        abort_if($origin !== null && ! in_array($origin,
            array_merge([McpOAuth::origin()], McpOAuth::allowedOrigins()), true), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
