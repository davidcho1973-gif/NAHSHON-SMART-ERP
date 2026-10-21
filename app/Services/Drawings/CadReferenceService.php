<?php

namespace App\Services\Drawings;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Raster/PDF underlays retain their original bytes; the DXF contains only page extents. */
class CadReferenceService
{
    public function inspect(string $bytes, string $mime, ?array $input): ?array
    {
        if (! in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
            return null;
        }
        if ($mime === 'application/pdf') {
            if (! str_starts_with($bytes, '%PDF-') || ! $input || ! isset($input['page'], $input['width'], $input['height'])) {
                throw ValidationException::withMessages(['file' => 'PDF에서 편집할 페이지를 먼저 선택하세요.']);
            }
            $input = Validator::make($input, ['page' => 'required|integer|between:1,500', 'width' => 'required|numeric|between:1,100000', 'height' => 'required|numeric|between:1,100000'])->validate();

            return ['format' => 'pdf', 'page' => (int) $input['page'], 'width' => (float) $input['width'], 'height' => (float) $input['height'], 'mime' => $mime];
        }
        $size = @getimagesizefromstring($bytes);
        if (! $size || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $size[0] * $size[1] > 40000000) {
            throw ValidationException::withMessages(['file' => 'PNG/JPEG 이미지와 최대 4천만 화소를 확인하세요.']);
        }

        return ['format' => $size[2] === IMAGETYPE_PNG ? 'png' : 'jpg', 'page' => 1, 'width' => $size[0], 'height' => $size[1], 'mime' => $mime];
    }

    public function extents(array $reference): string
    {
        $w = $reference['width'];
        $h = $reference['height'];

        return "0\nSECTION\n2\nHEADER\n9\n\$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nPOINT\n8\n0\n10\n0\n20\n0\n0\nPOINT\n8\n0\n10\n{$w}\n20\n{$h}\n0\nENDSEC\n0\nEOF\n";
    }
}
