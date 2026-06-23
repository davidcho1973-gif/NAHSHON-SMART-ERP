<?php

namespace App\Support;

class ReceiptFilePayload
{
    private const PREFIX = 'base64:';

    public static function encode(?string $contents): ?string
    {
        if ($contents === null || $contents === '') {
            return null;
        }

        return self::PREFIX . base64_encode($contents);
    }

    public static function decode(mixed $contents): ?string
    {
        if ($contents === null || $contents === '') {
            return null;
        }

        if (is_resource($contents)) {
            $contents = stream_get_contents($contents);
        }

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        if (str_starts_with($contents, self::PREFIX)) {
            $decoded = base64_decode(substr($contents, strlen(self::PREFIX)), true);

            return is_string($decoded) ? $decoded : null;
        }

        return $contents;
    }
}
