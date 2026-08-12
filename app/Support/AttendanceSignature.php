<?php

namespace App\Support;

use RuntimeException;

/**
 * 오프라인 출퇴근 기록에 붙이는 서명 키.
 *
 * 현장에 신호가 없을 때 휴대폰이 기록을 안고 있다가 나중에 올린다. 그 사이에 시각과
 * 위치를 고칠 수 있으므로 브라우저에서 서명을 붙이고 서버가 확인한다. 그러려면 키가
 * 브라우저에도 있어야 한다 — 이 구조에서는 피할 수 없다.
 *
 * 문제는 <b>어떤 키를 주느냐</b>였다. 예전에는 APP_KEY 를 그대로 화면에 박아 보냈다.
 * APP_KEY 는 세션·쿠키·암호화 전부를 여는 열쇠라, 화면 소스를 볼 수 있는 사람이면
 * 누구나 그걸 가져갔다. 출퇴근 하나 지키려고 집 열쇠를 통째로 준 셈이다.
 *
 * 그래서 APP_KEY 에서 <b>파생한</b> 키를 준다. 이 값으로 출퇴근 서명은 확인되지만,
 * 되돌려서 APP_KEY 를 얻을 수는 없다. 새어 나가도 잃는 것이 출퇴근 서명뿐이다.
 */
final class AttendanceSignature
{
    /** 키를 바꿔야 할 때 올린다. 올리면 그 전에 쌓인 오프라인 기록은 검증에 실패한다. */
    private const PURPOSE = 'attendance-offline-queue-v1';

    public static function key(): string
    {
        $app = (string) config('app.key');

        // 예비 키를 소스에 두지 않는다. 두면 그게 곧 진짜 키가 되어, 저장소를 볼 수
        // 있는 사람은 누구나 임의의 시각·위치로 서명할 수 있다.
        if ($app === '') {
            throw new RuntimeException('APP_KEY 가 없어 출퇴근 서명을 만들 수 없습니다.');
        }

        return hash_hmac('sha256', self::PURPOSE, $app);
    }

    public static function sign(string $message): string
    {
        return hash_hmac('sha256', $message, self::key());
    }
}
