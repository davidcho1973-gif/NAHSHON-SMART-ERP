<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** A suspended account must lose access on its next request, not its next login. */
class RequireActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $request->path() !== 'logout') {
            abort_if(($user->account_status ?? $user->fresh()?->account_status) !== 'active',
                403, '계정이 비활성 상태입니다. 관리자에게 문의하세요.');
        }

        return $next($request);
    }
}
