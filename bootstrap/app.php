<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectTo(
            guests: '/login',
            users: '/',
        );

        // 언어 쿠키는 클라이언트(JS)가 평문으로 설정하므로 암호화에서 제외.
        $middleware->encryptCookies(except: ['app_locale']);

        // 모든 웹 요청에 선택 언어를 적용.
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            // 같은 사이트 안에서는 iframe 허용(SAMEORIGIN) — 안 붙이면 플랫폼이
            // deny 를 붙여 SPA 가 품은 문서함·문서 뷰어가 회색으로 깨진다.
            \App\Http\Middleware\AllowSameOriginFraming::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
