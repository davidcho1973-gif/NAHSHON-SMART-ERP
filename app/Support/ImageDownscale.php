<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * 비전 AI 에 넘기기 전 사진을 줄인다.
 *
 * 왜 필요한가: 요즘 폰 사진은 한 장에 4000×3000 · 3~8MB 다. 그대로 보내면
 *  - 요청 본문이 커져 업로드·전송에서 시간을 다 쓰고,
 *  - 비전 API 자체의 본문 한도(수십 MB)에 걸리며,
 *  - 정작 판독 정확도는 나아지지 않는다. 비전 모델이 내부적으로 어차피 장변 1,500px 안팎으로
 *    다시 줄여서 처리하기 때문이다.
 *
 * 그래서 "올릴 때는 크기 제한 없이 받고, AI 에 넘기기 직전에 서버가 줄인다". 사용자는 원본을
 * 그대로 올리면 되고(요청대로 용량 제한 없음), 판독은 안정적으로 끝난다.
 *
 * 회전 보정도 여기서 한다 — 폰으로 세로로 찍은 영수증이 눕혀진 채 들어가면 사람 눈엔 멀쩡한데
 * 판독만 조용히 실패한다. 원인 찾기가 가장 어려운 종류의 버그다.
 */
final class ImageDownscale
{
    /** 장변 목표 픽셀. 비전 모델이 내부에서 쓰는 해상도보다 넉넉하다. */
    public const MAX_EDGE = 1600;

    /** JPEG 품질 — 글자(납품서·영수증) 판독이 목적이라 과하게 낮추지 않는다. */
    public const QUALITY = 82;

    /** 이 크기 이하면 손대지 않는다(이미 작은 사진을 다시 굽지 않기 위해). */
    private const SKIP_UNDER_BYTES = 300 * 1024;

    /**
     * 원본 바이트를 받아 줄인 JPEG 바이트를 돌려준다.
     * 처리할 수 없는 형식(HEIC 등 GD 미지원)이면 원본을 그대로 돌려준다 — 줄이려다 못 올리는
     * 것보다, 크더라도 넘겨서 AI 가 읽어보게 하는 편이 낫다.
     *
     * @return array{data: string, mime: string, width: int, height: int, resized: bool}
     */
    public static function shrink(string $bytes, string $mime = 'image/jpeg', int $maxEdge = self::MAX_EDGE, int $quality = self::QUALITY): array
    {
        $original = ['data' => $bytes, 'mime' => $mime, 'width' => 0, 'height' => 0, 'resized' => false];

        if (! extension_loaded('gd')) {
            return $original;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return $original;   // GD 가 못 읽는 형식(HEIC 등) — 원본 그대로.
        }

        [$width, $height] = $info;
        $original['width'] = (int) $width;
        $original['height'] = (int) $height;

        $longEdge = max($width, $height);
        if ($longEdge <= $maxEdge && strlen($bytes) <= self::SKIP_UNDER_BYTES) {
            return $original;
        }

        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return $original;
            }

            $src = self::applyExifRotation($src, $bytes, $mime);
            $width = imagesx($src);
            $height = imagesy($src);
            $longEdge = max($width, $height);

            $scale = $longEdge > $maxEdge ? $maxEdge / $longEdge : 1.0;
            $targetW = max(1, (int) round($width * $scale));
            $targetH = max(1, (int) round($height * $scale));

            $dst = imagecreatetruecolor($targetW, $targetH);
            // 투명 PNG 대비 흰 배경 — 안 깔면 투명 영역이 JPEG 에서 새까맣게 변해 글씨가 사라진다.
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

            ob_start();
            imagejpeg($dst, null, $quality);
            $out = (string) ob_get_clean();

            imagedestroy($src);
            imagedestroy($dst);

            if ($out === '') {
                return $original;
            }

            return ['data' => $out, 'mime' => 'image/jpeg', 'width' => $targetW, 'height' => $targetH, 'resized' => true];
        } catch (\Throwable $e) {
            Log::warning('사진 축소 실패, 원본 사용: '.$e->getMessage());

            return $original;
        }
    }

    /**
     * EXIF Orientation 을 실제 픽셀에 반영한다. exif 확장이 없으면 원본 그대로 둔다.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private static function applyExifRotation($image, string $bytes, string $mime)
    {
        if (! function_exists('exif_read_data') || ! in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
            $orientation = (int) ($exif['Orientation'] ?? 0);

            $rotated = match ($orientation) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => null,
            };

            return $rotated ?: $image;
        } catch (\Throwable) {
            return $image;
        }
    }
}
