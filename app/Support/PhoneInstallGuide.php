<?php

namespace App\Support;

/**
 * 「홈 화면에 추가」 안내서 — 폰과 브라우저마다 누르는 곳이 다르다.
 *
 * ── 왜 안내서가 따로 필요한가 ─────────────────────────────────────────
 * 설치 팝업(partials/install-app)은 세 줄짜리다. 그걸로 되는 사람은 그걸로 끝나지만,
 * 현장에서 막히는 사람은 <b>거기 적힌 버튼이 자기 폰에 없는</b> 사람들이다:
 *
 *   · 카카오톡으로 링크를 받아 연 사람 — 인앱 브라우저에는 설치 기능이 아예 없다.
 *     현장에서 가장 흔한 실패다. 본인은 «안 된다» 고만 말할 수 있다.
 *   · 아이폰인데 크롬으로 연 사람 — 공유 목록에 「홈 화면에 추가」 가 안 나온다.
 *   · 삼성 인터넷을 쓰는 사람 — 크롬과 메뉴 이름이 다르다(「현재 페이지 추가」).
 *
 * 이 셋은 «버튼을 못 찾는» 문제가 아니라 <b>브라우저를 바꿔야 푸는</b> 문제다.
 * 그래서 안내서가 먼저 하는 일은 단계를 보여 주는 것이 아니라, <b>지금 이 폰이
 * 어느 경우인지 알려 주는 것</b>이다.
 *
 * 판정은 여기 한 곳에서 한다. 화면마다 UserAgent 를 다시 읽으면 한쪽만 고쳐진다.
 */
final class PhoneInstallGuide
{
    /** 카카오톡·인스타 같은 앱 속 브라우저 — 설치가 아예 안 된다. 브라우저를 바꿔야 한다. */
    public const IN_APP = 'in-app';

    /** 아이폰 사파리 — 공유 버튼으로 직접 추가한다. */
    public const IOS_SAFARI = 'ios-safari';

    /** 아이폰인데 사파리가 아니다 — 사파리로 옮겨야 한다. */
    public const IOS_OTHER = 'ios-other';

    /** 삼성 인터넷 — 메뉴 이름이 크롬과 다르다. */
    public const SAMSUNG = 'samsung';

    /** 안드로이드 크롬 — 대개 버튼 한 번으로 끝난다. */
    public const ANDROID = 'android';

    /** 컴퓨터 — 설치할 것이 없다. 폰으로 열라고 말해 준다. */
    public const DESKTOP = 'desktop';

    public const ALL = [
        self::IN_APP, self::IOS_SAFARI, self::IOS_OTHER,
        self::SAMSUNG, self::ANDROID, self::DESKTOP,
    ];

    /**
     * 이 UserAgent 는 어느 경우인가.
     *
     * 순서가 중요하다 — 카카오톡 인앱 브라우저의 UserAgent 에는 iPhone 도 Android 도
     * 함께 들어 있다. 인앱을 먼저 걸러내지 않으면 «사파리 공유 버튼을 누르세요» 라고
     * 안내하게 되는데, 그 화면에는 그 버튼이 없다.
     */
    public static function detect(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        if (self::isInApp($ua)) {
            return self::IN_APP;
        }

        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            // 아이폰의 크롬·파이어폭스·엣지도 UserAgent 에 Safari 를 달고 다닌다.
            // 그래서 «사파리인가» 가 아니라 «다른 브라우저 이름이 있는가» 로 가린다.
            return preg_match('/CriOS|FxiOS|EdgiOS|OPiOS|Whale/i', $ua)
                ? self::IOS_OTHER
                : self::IOS_SAFARI;
        }

        if (preg_match('/SamsungBrowser/i', $ua)) {
            return self::SAMSUNG;
        }

        if (preg_match('/Android/i', $ua)) {
            return self::ANDROID;
        }

        return self::DESKTOP;
    }

    /** 앱 속에서 열린 브라우저인가 — 여기서는 홈 화면 추가가 되지 않는다. */
    private static function isInApp(string $ua): bool
    {
        // KAKAOTALK · NAVER(인앱) · 라인 · 인스타그램 · 페이스북 · 위챗 · 틱톡.
        return (bool) preg_match('/KAKAOTALK|NAVER\(inapp|Line\/|Instagram|FBAN|FBAV|MicroMessenger|BytedanceWebview/i', $ua);
    }
}
