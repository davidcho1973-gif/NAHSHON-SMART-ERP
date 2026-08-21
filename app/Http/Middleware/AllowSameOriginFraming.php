<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "같은 사이트 안에서는 액자(iframe)에 넣어도 된다" 를 명시한다.
 *
 * ERP 는 자기 화면을 자기 안에 품는다 — SPA 가 AI 문서함(/document-hub)을 iframe 으로
 * 얹고, 문서 뷰어가 미리보기를 iframe 으로 띄운다. 그런데 앱이 아무 선언을 안 하면
 * 플랫폼(엣지)이 기본값으로 `X-Frame-Options: deny` 를 붙이고, 그 순간 브라우저는
 * <b>자기 자신의 화면조차</b> 액자에 넣기를 거부한다 — 문서함 메뉴가 회색 깨진
 * 아이콘만 보여주던 원인이다.
 *
 * SAMEORIGIN 은 "우리 도메인 안에서만 허용" 이다. 남의 사이트가 우리 화면을 품어
 * 로그인 클릭을 가로채는 것(클릭재킹)은 여전히 막힌다 — 보호를 버리는 게 아니라
 * 범위를 정확히 말하는 것뿐이다.
 */
class AllowSameOriginFraming
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
