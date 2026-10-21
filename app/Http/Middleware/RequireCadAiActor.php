<?php

namespace App\Http\Middleware;

use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\Mcp\CadMcpOAuth;
use App\Support\WorkerDeviceSession;
use Closure;
use Illuminate\Http\Request;

final class RequireCadAiActor
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('cad_ai.enabled'), 404);
        $user = $request->user();
        abort_unless(CadMcpOAuth::eligible($user), 403);
        abort_unless(EmailPasswordAuthService::hasStrongAuthentication($request, $user)
            && ! WorkerDeviceSession::isDeviceOnly($request)
            && ! $request->session()->has(PersonalAppAccessService::SESSION), 403,
            'AI 연결은 이메일·비밀번호 또는 Google로 로그인한 뒤 사용하세요.');

        return $next($request);
    }
}
