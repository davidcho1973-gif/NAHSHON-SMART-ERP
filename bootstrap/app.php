<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use App\Http\Middleware\AllowSameOriginFraming;
use App\Http\Middleware\LocalAutoLogin;
use App\Http\Middleware\SetLocale;
use App\Support\UploadLimits;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
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
            // 로컬 개발 자동 로그인(SNAP_AUTOLOGIN=1 + local 전용) — 운영에선 항상 무동작.
            LocalAutoLogin::class,
            SetLocale::class,
            // 같은 사이트 안에서는 iframe 허용(SAMEORIGIN) — 안 붙이면 플랫폼이
            // deny 를 붙여 SPA 가 품은 문서함·문서 뷰어가 회색으로 깨진다.
            AllowSameOriginFraming::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 요청 본문이 post_max_size 를 넘으면 PHP 가 본문을 통째로 버리고 Laravel 이
        // 여기서 413 을 던진다. 기본 응답은 영어 한 줄("Payload Too Large")뿐이라,
        // 파일을 여러 개 고른 사람에게는 무엇이 잘못됐는지 전달되지 않는다 — 화면에는
        // 멈춘 진행 막대만 남는다. 2026-09-05 나손에서 도면 8장을 한 번에 올리다 그랬다.
        // 한도는 올리는 화면마다 다시 적지 않고 실제 ini 값에서 읽는다.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $limit = (int) round(UploadLimits::postMaxBytes() / 1048576);
            $message = '한 번에 보낼 수 있는 크기를 넘었습니다'
                .($limit > 0 ? ' (최대 '.$limit.'MB)' : '')
                .'. 파일을 하나씩 나눠 올려 주세요.';

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 'body_too_large',
                    'error' => $message,
                ], 413);
            }

            return response($message, 413);
        });
    })->create();
