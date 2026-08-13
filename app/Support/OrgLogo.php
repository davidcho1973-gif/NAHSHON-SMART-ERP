<?php

namespace App\Support;

use RuntimeException;

/**
 * 고객이 올린 로고 그림을 받을 수 있는 모양으로 다듬는다.
 *
 * 로고는 화면을 열 때마다 모든 사람이 내려받는 그림이다. 그래서 올라온 파일을
 * 그대로 두지 않는다 — 회사 로고 원본은 인쇄용으로 만들어져서 3000px · 5MB 인
 * 경우가 흔하고, 그걸 32px 배지 자리에 그대로 쓰면 첫 화면이 느려지는 이유가
 * 아무 데도 안 적힌 채로 남는다.
 *
 * 그림 파일은 두 갈래로 나뉜다.
 *
 *   래스터(PNG·JPEG·WEBP) — 512px 안으로 줄이고 PNG 로 통일한다. PNG 로 맞추는
 *       이유는 투명 배경 때문이다. 로고는 대개 배경이 뚫려 있고, JPEG 으로 구우면
 *       그 자리가 흰 사각형이 되어 어두운 사이드바에서 흰 판때기로 보인다.
 *
 *   SVG — 줄일 필요가 없다(벡터라 어느 크기에도 선명하다). 대신 SVG 는 그림이
 *       아니라 문서라서 스크립트를 품을 수 있다. 우리 도메인에서 그대로 돌려주면
 *       그 스크립트도 우리 도메인 것이 된다. 그래서 실행될 수 있는 것을 걷어낸다.
 */
final class OrgLogo
{
    /** 올릴 수 있는 최대 크기. 이보다 큰 로고 파일은 로고가 아니라 인쇄 원본이다. */
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    /** 다듬은 뒤 장변 픽셀. 사이드바 배지는 32px, 로그인 화면은 82px 이다. */
    public const MAX_EDGE = 512;

    /** @var array<string, string> 사람이 고를 법한 확장자 → 실제 형식 */
    public const ACCEPTED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    /**
     * 받은 바이트를 저장할 수 있는 형태로 돌려준다.
     *
     * 못 받는 파일이면 사람이 읽고 무엇을 고쳐야 할지 아는 문장으로 던진다.
     * "Invalid file" 만 뜨면 고객은 우리한테 연락하는 수밖에 없다.
     *
     * @return array{data: string, mime: string}
     */
    public static function normalize(string $bytes): array
    {
        if ($bytes === '') {
            throw new RuntimeException('빈 파일입니다.');
        }

        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('파일이 너무 큽니다. '
                .round(self::MAX_UPLOAD_BYTES / 1024 / 1024, 1).'MB 이하로 올려 주세요.');
        }

        if (self::looksLikeSvg($bytes)) {
            return ['data' => self::sanitizeSvg($bytes), 'mime' => 'image/svg+xml'];
        }

        return self::shrinkToPng($bytes);
    }

    /**
     * 이 그림을 브라우저에 돌려줄 때 함께 보내야 하는 머리글.
     *
     * SVG 를 그냥 돌려주면 주소창에 직접 열었을 때 그 안의 내용이 우리 도메인에서
     * 실행될 수 있다. 걷어내는 것과 별개로, 브라우저에게도 "이 문서는 아무것도
     * 불러오지 말라" 고 못을 박아 둔다. 걷어내기와 이 머리글 중 하나가 뚫려도
     * 나머지 하나가 남는다.
     *
     * @return array<string, string>
     */
    public static function safeHeaders(string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="logo"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ];
    }

    private static function looksLikeSvg(string $bytes): bool
    {
        $head = strtolower(substr($bytes, 0, 2048));

        return str_contains($head, '<svg');
    }

    /**
     * SVG 에서 실행될 수 있는 것을 걷어낸다.
     *
     * 완벽한 SVG 세정기를 여기서 만들 생각은 없다 — 그건 라이브러리가 할 일이다.
     * 여기서 막는 것은 실제로 쓰이는 통로다: 스크립트 태그, on… 이벤트 속성,
     * javascript: 주소, 그리고 SVG 안에 HTML 을 다시 여는 foreignObject.
     */
    private static function sanitizeSvg(string $bytes): string
    {
        $svg = $bytes;

        // 태그 통째로 — 열고 닫는 짝이 안 맞아도 지운다.
        $svg = (string) preg_replace('~<\s*(script|foreignObject|iframe|object|embed|handler)\b[^>]*>.*?<\s*/\s*\1\s*>~is', '', $svg);
        $svg = (string) preg_replace('~<\s*/?\s*(script|foreignObject|iframe|object|embed|handler)\b[^>]*>~i', '', $svg);

        // on… 이벤트 속성 (onload, onclick …)
        $svg = (string) preg_replace('~\son[a-z-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)~is', '', $svg);

        // javascript: 주소 — href·xlink:href·style 어디에 있든.
        $svg = (string) preg_replace('~(?:java|vb)\s*script\s*:~i', '', $svg);

        // 밖에서 무언가를 끌어오는 통로. 로고는 자기 안에서 완결돼야 한다.
        $svg = (string) preg_replace('~<\s*(use|image)\b[^>]*(?:xlink:)?href\s*=\s*["\']?\s*(?:https?:)?//[^>]*>~i', '', $svg);

        if (trim($svg) === '' || ! self::looksLikeSvg($svg)) {
            throw new RuntimeException('SVG 파일을 읽지 못했습니다. PNG 로 올려 보세요.');
        }

        return $svg;
    }

    /**
     * @return array{data: string, mime: string}
     */
    private static function shrinkToPng(string $bytes): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('서버가 그림을 처리할 수 없습니다. 관리자에게 알려 주세요.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new RuntimeException('그림 파일이 아닙니다. PNG · JPG · WEBP · SVG 만 올릴 수 있습니다.');
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new RuntimeException('그림을 읽지 못했습니다. PNG 로 저장해서 다시 올려 보세요.');
        }

        try {
            $width = imagesx($src);
            $height = imagesy($src);
            $longEdge = max($width, $height);
            $scale = $longEdge > self::MAX_EDGE ? self::MAX_EDGE / $longEdge : 1.0;
            $targetW = max(1, (int) round($width * $scale));
            $targetH = max(1, (int) round($height * $scale));

            $dst = imagecreatetruecolor($targetW, $targetH);

            // 투명한 자리를 투명한 채로 남긴다. 이걸 안 하면 뚫린 배경이 검게 채워져
            // 어두운 사이드바에 검은 사각형이 하나 붙는다.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

            ob_start();
            imagepng($dst, null, 9);
            $out = (string) ob_get_clean();

            imagedestroy($dst);

            if ($out === '') {
                throw new RuntimeException('그림을 저장하지 못했습니다.');
            }

            return ['data' => $out, 'mime' => 'image/png'];
        } finally {
            if (is_resource($src) || $src instanceof \GdImage) {
                @imagedestroy($src);
            }
        }
    }
}
