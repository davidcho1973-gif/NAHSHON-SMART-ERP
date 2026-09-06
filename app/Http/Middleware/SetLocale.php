<?php

namespace App\Http\Middleware;

use App\Support\AppLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * 선택 언어를 매 요청에 적용 → ERP·현장앱·블레이드 등 모든 서버 렌더 페이지가
 * 그 언어로 표시된다. 쿠키는 암호화 제외 대상이라 클라이언트(JS)가 평문으로 써도 읽힌다.
 *
 * 고르는 규칙 자체는 AppLocale 한 곳에 있다 — 첫 화면(JS)과 서버가 다른 규칙을 쓰면
 * «첫 화면은 영어인데 들어가면 한글» 이 된다. 실제로 그랬다(2026-09-06).
 */
class SetLocale
{
    /** @deprecated AppLocale::SUPPORTED 를 쓴다. 부르는 곳이 남아 있어 남겨 둔다. */
    public const SUPPORTED = AppLocale::SUPPORTED;

    public const COOKIE = AppLocale::COOKIE;

    public function handle(Request $request, Closure $next): Response
    {
        [$locale, $remember] = AppLocale::decide($request);

        app()->setLocale($locale);

        $response = $next($request);

        // 가입 언어로 정해졌으면 쿠키에 심는다 — 다음 요청부터는 사용자를 몰라도 같은 언어다.
        //
        // 다만 이 응답이 이미 언어 쿠키를 달고 있으면 건드리지 않는다. 언어를 바꾸는
        // 요청이 바로 그 경우다 — 화면은 «영어로 바꿔 주세요» 라고 보냈는데 여기서
        // 옛 언어로 덮어쓰면, 바꾼 그 응답만 옛 언어로 돌아간다.
        if ($remember && ! $this->alreadySetsLocale($response)) {
            $response->headers->setCookie(
                Cookie::make(AppLocale::COOKIE, $locale, AppLocale::COOKIE_MINUTES)
            );
        }

        return $response;
    }

    /** 이 응답이 이미 언어 쿠키를 달고 나가는가. */
    private function alreadySetsLocale(Response $response): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === AppLocale::COOKIE) {
                return true;
            }
        }

        return false;
    }
}
