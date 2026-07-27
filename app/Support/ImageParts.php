<?php

namespace App\Support;

/**
 * 클라이언트가 보낸 이미지 파트를 비전 AI 에 넘길 수 있게 검증·정리한다.
 *
 * data URL 접두 제거, 이미지 MIME 허용목록, 장수 상한 — 마감사진·상황실 등에서 공통으로 쓴다.
 */
class ImageParts
{
    /** 허용 MIME. 그 외(PDF 등)는 비전 입력에서 제외한다. */
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    /**
     * @return array<int, array{data: string, mime_type: string}>
     */
    public static function sanitize(mixed $images, int $max = 6): array
    {
        if (! is_array($images)) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($images), 0, $max) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $data = (string) ($p['data'] ?? '');
            $mime = (string) ($p['mime_type'] ?? $p['mimeType'] ?? 'image/jpeg');

            // "data:image/png;base64,XXXX" 형태면 접두를 벗겨 실제 base64 만 남긴다.
            if (str_starts_with($data, 'data:') && str_contains($data, ',')) {
                if (preg_match('#^data:([^;]+);#', $data, $m)) {
                    $mime = $m[1];
                }
                $data = substr($data, strpos($data, ',') + 1);
            }

            $mime = strtolower(trim($mime));
            $mime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;

            if ($data === '' || ! in_array($mime, self::ALLOWED, true)) {
                continue;
            }
            $out[] = ['data' => $data, 'mime_type' => $mime];
        }

        return $out;
    }
}
