<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * PHP 가 실제로 받아 주는 크기 — 화면과 서버가 같은 숫자를 본다.
 *
 * ── 왜 따로 두는가 ─────────────────────────────────────────────────────
 * 한도가 두 층에 있다. 애플리케이션이 정한 «파일 하나 최대 몇 MB» 와, PHP 가 정한
 * «요청 본문 전체 최대 몇 MB»(post_max_size) 다. 앞의 것만 화면에 적어 두면
 * 거짓말이 된다 — 파일 여러 개를 한 요청에 담아 보내는 화면에서는 뒤의 것이 먼저 걸린다.
 *
 * 2026-09-05 나손에서 실제로 그랬다. 문서함 드롭존이 「파일당 최대 50MB」라고 적어 두고
 * 고른 파일을 <b>전부 한 요청에</b> 담아 보냈다. 사장이 도면 8장(합계 70.9MB)을 올리자
 * 화면이 멈춘 것처럼 보였다. 한 장 한 장은 한도 안이었는데 합계가 한도 언저리였다.
 *
 * post_max_size 를 넘긴 요청은 PHP 가 본문을 <b>통째로 버린다</b>. 그래서 서버에는
 * «파일이 안 왔다» 로 보이고, 검증기는 «파일을 선택하세요» 라고 답한다 — 방금 8장을
 * 고른 사람에게 가장 쓸모없는 문장이다. Content-Length 를 보면 구별할 수 있다.
 */
final class UploadLimits
{
    /** 요청 본문 전체의 한도(바이트). 못 읽으면 0. */
    public static function postMaxBytes(): int
    {
        return self::iniBytes((string) ini_get('post_max_size'));
    }

    /** 파일 하나의 한도(바이트). 못 읽으면 0. */
    public static function uploadMaxBytes(): int
    {
        return self::iniBytes((string) ini_get('upload_max_filesize'));
    }

    /**
     * 이 요청이 post_max_size 를 넘겨 버려졌는가.
     *
     * PHP 가 본문을 버린 뒤라 $_POST·$_FILES 로는 알 수 없다. Content-Length 는
     * 헤더라서 남아 있다 — 그것과 한도를 비교하는 것이 유일한 단서다.
     */
    public static function bodyOverflowed(Request $request): bool
    {
        $length = (int) $request->server('CONTENT_LENGTH', 0);
        $limit = self::postMaxBytes();

        return $length > 0 && $limit > 0 && $length > $limit;
    }

    /** "72M" · "512K" · "1G" · "1048576" 을 바이트로. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtoupper(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
