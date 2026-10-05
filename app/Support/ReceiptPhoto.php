<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** One storage policy for new receipt photos; PDFs and unsupported formats remain intact. */
final class ReceiptPhoto
{
    public static function store(UploadedFile $file): string
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $info = @getimagesizefromstring($bytes);
        if ($info && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            // A long receipt needs readable width, not an unconditional 2560px long edge.
            $edge = (int) min(8192, max(2560, max($info[0], $info[1]) * min(1, 1600 / max(1, min($info[0], $info[1])))));
            // Decode only when its pixel buffers fit a conservative per-request budget.
            if ($info[0] * $info[1] <= 16000000 && strlen($bytes) <= 16 * 1024 * 1024) {
                $small = ImageDownscale::shrink($bytes, $mime, $edge, 90);
                if ($small['resized'] && strlen($small['data']) < strlen($bytes)) {
                    $path = 'receipts/'.Str::uuid().'.jpg';
                    Storage::disk('public')->put($path, $small['data']);

                    return $path;
                }
            }
        }

        return $file->store('receipts', 'public');
    }
}
