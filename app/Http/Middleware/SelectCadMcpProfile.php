<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SelectCadMcpProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('cad_mcp_profile', true);
        try {
            return $next($request);
        } finally {
            $request->attributes->remove('cad_mcp_profile');
        }
    }
}
