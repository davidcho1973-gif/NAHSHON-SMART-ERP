<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\Mcp\ErpMcpOAuth;
use App\Support\WorkerDeviceSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireErpMcpConsentActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::query()->find(Auth::guard('web')->id());
        abort_unless(ErpMcpOAuth::eligible($user), 403, 'An active, approved ERP account is required.');
        abort_unless(EmailPasswordAuthService::hasStrongAuthentication($request, $user)
            && ! WorkerDeviceSession::isDeviceOnly($request)
            && ! $request->session()->has(PersonalAppAccessService::SESSION),
            403, 'Sign in with Google or your password before connecting ERP.');

        Auth::guard('web')->setUser($user);

        return $next($request);
    }
}
