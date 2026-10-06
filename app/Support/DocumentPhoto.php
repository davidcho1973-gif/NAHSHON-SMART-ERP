<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/** Reduce photos at intake as well, for clients that do not run browser compression. */
final class DocumentPhoto
{
    public static function prepare(UploadedFile $file): UploadedFile
    {
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)
            || $file->getSize() > 16 * 1024 * 1024) {
            return $file;
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] * $info[1] > 16000000) {
            return $file;
        }
        $edge = (int) min(8192, max(2560, max($info[0], $info[1]) * min(1, 1600 / min($info[0], $info[1]))));
        $small = ImageDownscale::shrink($bytes, $file->getMimeType(), $edge, 90);
        if (! $small['resized'] || strlen($small['data']) >= strlen($bytes)) {
            return $file;
        }
        $path = tempnam(sys_get_temp_dir(), 'doc-photo-');
        if ($path === false) {
            return $file;
        }
        register_shutdown_function(static function () use ($path): void { @unlink($path); });
        if (file_put_contents($path, $small['data']) === false) {
            return $file;
        }

        return new UploadedFile($path, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg', 'image/jpeg', null, true);
    }
}
