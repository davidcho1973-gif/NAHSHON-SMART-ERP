<?php

namespace App\Http\Middleware;

use App\Support\WorkerDeviceSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 남의 글이 보이는 곳은 휴대폰 하나로 열지 않는다.
 *
 * 출퇴근은 휴대폰을 기억한 것만으로 연다 — 매일 여러 번 하는 일이고, 한 단계라도
 * 있으면 안 찍히며, 안 찍힌 출퇴근은 그 사람 임금이 깎이는 일이기 때문이다.
 *
 * 메시지와 문서는 다르다. 거기 있는 글은 <b>남의 것</b>이다. 폰을 빌려주거나 잃어버렸을
 * 때 출퇴근 화면이 열리는 것과 현장 대화가 통째로 읽히는 것은 무게가 다르다.
 * 이쪽만 PIN 네 자리를 한 번 받는다 — 세션이 살아 있는 동안은 다시 묻지 않는다.
 *
 * 막을 때 로그인 화면으로 보내지 않는다. 그 화면은 이메일과 비밀번호를 묻는데,
 * 작업자에게는 둘 다 없다 — 그래서 이 공사 전체가 시작됐다.
 */
class RequireMoreThanTheDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! WorkerDeviceSession::isDeviceOnly($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => 'pin_required',
                'error' => '메시지와 문서는 PIN 네 자리를 한 번 넣어야 열립니다.',
                'pin_url' => route('worker-app.pin'),
            ], 403);
        }

        return redirect()->route('worker-app.pin', ['next' => $request->fullUrl()]);
    }
}
