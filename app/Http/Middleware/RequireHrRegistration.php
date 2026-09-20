<?php

namespace App\Http\Middleware;

use App\Support\AccessPolicy;
use Closure;
use Illuminate\Http\Request;

class RequireHrRegistration
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && $user->account_status === 'active'
            && in_array($user->access_role, AccessPolicy::PEOPLE_ROLES, true), 403,
            '직원 등록은 인사담당자만 처리할 수 있습니다.');

        return $next($request);
    }
}
