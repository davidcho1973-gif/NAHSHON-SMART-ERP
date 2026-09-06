<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * 이 요청을 어느 언어로 보여 줄 것인가 — 한 곳에서만 정한다.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 앱 첫 화면의 KO·EN·ES 버튼은 고른 언어를 <b>브라우저 안(localStorage)에만</b> 두었다.
 * 그 값은 서버로 가지 않는다. 그래서 첫 화면만 영어로 바뀌고, 거기서 들어가는 화면
 * (현장 기록·물어보기·문서 올리기)은 서버가 그리므로 계속 한국어였다.
 * 2026-09-06 에 사장이 그대로 겪었다 — 첫 화면은 EN, 들어간 화면은 한글.
 *
 * 게다가 가입할 때 고른 언어(employees.preferred_language)는 첫 화면의 자동 전환에만
 * 쓰였고, 서버 렌더 화면은 그것도 몰랐다.
 *
 * ── 정하는 순서 ────────────────────────────────────────────────────────
 *  1. app_locale 쿠키 — 이 사람이 <b>직접 고른</b> 것. 가장 세다.
 *  2. 가입할 때 고른 언어 — 아직 고른 적이 없는 사람의 기본값.
 *  3. 배포 기본 언어(config app.locale).
 *
 * 2번으로 정해졌으면 쿠키에 심어 둔다. 그래야 로그인 전 화면·정적 페이지처럼
 * 사용자를 모르는 요청에서도 같은 언어가 나온다.
 */
final class AppLocale
{
    public const SUPPORTED = ['ko', 'en', 'es'];

    public const COOKIE = 'app_locale';

    /** 쿠키 수명 — 분. 폰을 바꾸지 않는 한 다시 고를 일이 없어야 한다. */
    public const COOKIE_MINUTES = 60 * 24 * 365;

    /** 지원하지 않는 값이면 null. 화면에서 온 값은 믿지 않는다. */
    public static function normalize(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }

    /** 배포 기본 언어. */
    public static function fallback(): string
    {
        return self::normalize(config('app.locale')) ?? 'ko';
    }

    /** 이 사람이 가입할 때 고른 언어. 없으면 null. */
    public static function ofUser(?User $user): ?string
    {
        return self::normalize($user?->employee?->preferred_language);
    }

    /**
     * 이 요청의 언어와, 그것을 쿠키에 심어야 하는지.
     *
     * @return array{0: string, 1: bool} [언어, 쿠키에 심을 것인가]
     */
    public static function decide(Request $request): array
    {
        if ($chosen = self::normalize($request->cookie(self::COOKIE))) {
            return [$chosen, false];
        }

        // 로그인한 사람이면 가입할 때 고른 언어를 따른다. 쿠키가 없다고 한국어로
        // 떨어뜨리면, 스페인어로 가입한 작업자가 매번 버튼부터 찾아야 한다.
        if ($preferred = self::ofUser($request->user())) {
            return [$preferred, true];
        }

        return [self::fallback(), false];
    }

    /**
     * 이 언어의 낱말 사전 — 화면 안의 JS 도 같은 파일을 읽게 하려고 통째로 내준다.
     *
     * 블레이드는 __() 로 읽고, 화면 JS 는 이 배열을 받아 같은 열쇠로 읽는다. 사전이
     * 두 벌이면 한쪽만 번역되는 사고가 난다 — 한국어는 파일이 없어도 열쇠가 곧 원문이다.
     *
     * @return array<string, string>
     */
    public static function dictionary(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $path = lang_path($locale.'.json');

        if (! is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
