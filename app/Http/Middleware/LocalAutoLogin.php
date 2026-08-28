<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 로컬 개발 자동 로그인 — local 환경 + SNAP_AUTOLOGIN=1 일 때만.
 *
 * 헤드리스 스크린샷·로컬 리허설에서 구글 OAuth 를 건너뛰기 위한
 * 표준 개발 편의 장치다. 운영/스테이징에서는 환경 검사로 완전히 비활성.
 */
class LocalAutoLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local') && env('SNAP_AUTOLOGIN') && Auth::guest()) {
            $user = User::query()->where('access_role', 'super_admin')->orderBy('id')->first();
            if ($user !== null) {
                Auth::login($user);
            }
        }

        return $next($request);
    }
}
