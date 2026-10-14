<?php

namespace App\Support;

/** A readable viewing/AI copy; callers must store the supplied original separately. */
final class EvidenceImage
{
    /** @return array{data: string, mime: string, width: int, height: int, resized: bool, original_sha256: string, derivative_sha256: string} */
    public static function prepare(string $bytes, string $mime): array
    {
        $hash = hash('sha256', $bytes);
        $original = ['data' => $bytes, 'mime' => $mime, 'width' => 0, 'height' => 0, 'resized' => false,
            'original_sha256' => $hash, 'derivative_sha256' => $hash];
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || strlen($bytes) > 16 * 1024 * 1024) {
            return $original;
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            return $original;
        }
        $original['width'] = (int) $info[0];
        $original['height'] = (int) $info[1];
        $pixels = $info[0] * $info[1];
        $configuredLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        $budget = min($configuredLimit > 0 ? $configuredLimit : 256 * 1024 * 1024, 256 * 1024 * 1024);
        // Source, EXIF rotation and destination buffers coexist. Fatal allocation
        // failures cannot be caught; retain the original when headroom is inadequate.
        $needed = $pixels * 14 + strlen($bytes) * 3 + 16 * 1024 * 1024;
        if ($pixels > 16000000 || memory_get_usage(true) + $needed > $budget) {
            return $original;
        }

        // Long receipts retain a readable short edge. Never squeeze an arbitrarily
        // tall receipt into a fixed long edge or force a byte target at the cost of text.
        $edge = (int) max(2560, max($info[0], $info[1]) * min(1, 1600 / min($info[0], $info[1])));
        $small = ImageDownscale::shrink($bytes, $mime, $edge, 90);
        if (! $small['resized'] || strlen($small['data']) >= strlen($bytes)) {
            return $original;
        }

        return $small + ['original_sha256' => $hash, 'derivative_sha256' => hash('sha256', $small['data'])];
    }
}
