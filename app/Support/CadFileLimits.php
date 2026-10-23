<?php

namespace App\Support;

final class CadFileLimits
{
    public static function drawingBytes(): int
    {
        return (int) config('cad.max_bytes');
    }

    public static function label(): string
    {
        return rtrim(rtrim(number_format(self::drawingBytes() / 1048576, 2, '.', ''), '0'), '.').'MB';
    }

    public static function capabilities(): array
    {
        return [
            'maxUploadBytes' => self::effectiveUploadBytes(self::drawingBytes(), UploadLimits::uploadMaxBytes(), UploadLimits::postMaxBytes()),
            'maxDrawingBytes' => self::drawingBytes(),
            'maxRequestBytes' => UploadLimits::postMaxBytes(),
        ];
    }

    public static function effectiveUploadBytes(int $drawing, int $upload, int $post): int
    {
        // Leave room for the multipart boundary, title and PDF page metadata.
        return min($drawing, $upload > 0 ? $upload : $drawing, $post > 0 ? max(0, $post - 1048576) : $drawing);
    }
}
