<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Throwable;

/**
 * QR 코드를 서버에서 SVG 로 직접 생성한다(외부 서비스 의존 제거).
 *
 * 왜: 기존엔 api.qrserver.com 외부 이미지를 썼는데, 현장 네트워크 정책/방화벽에서 막히면
 * 포스터의 QR 이 빈 칸으로 뜬다(스캔 불가). 로컬 SVG 는 오프라인·폐쇄망에서도 항상 렌더된다.
 */
final class QrSvg
{
    public static function svg(string $data, int $size = 320, int $margin = 2): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, $margin), new SvgImageBackEnd()));

        return $writer->writeString($data);
    }

    /** <img src="..."> 에 바로 넣을 수 있는 data URI. 실패 시 빈 문자열. */
    public static function dataUri(string $data, int $size = 320, int $margin = 2): string
    {
        try {
            return 'data:image/svg+xml;base64,'.base64_encode(self::svg($data, $size, $margin));
        } catch (Throwable) {
            return '';
        }
    }
}
