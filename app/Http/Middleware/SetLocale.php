<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 선택 언어(쿠키 app_locale)를 매 요청에 적용 → ERP·현장앱·블레이드 등 모든
 * 서버 렌더 페이지가 자동으로 선택 언어로 표시된다. 쿠키는 암호화 제외 대상이라
 * 클라이언트(JS)가 평문으로 써도 그대로 읽힌다.
 */
class SetLocale
{
    public const SUPPORTED = ['ko', 'en', 'es'];

    public const COOKIE = 'app_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->cookie(self::COOKIE);

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = (string) config('app.locale', 'en');
            $locale = in_array($locale, self::SUPPORTED, true) ? $locale : 'ko';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
