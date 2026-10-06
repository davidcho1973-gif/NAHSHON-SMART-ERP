<?php

namespace Tests\Feature;

use App\Support\DocumentPhoto;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DocumentPhotoTest extends TestCase
{
    public function test_large_photo_is_reduced_with_matching_name_and_mime(): void
    {
        $image = imagecreatetruecolor(4000, 3000);
        imagefilledrectangle($image, 0, 0, 3999, 2999, imagecolorallocate($image, 245, 245, 245));
        imagestring($image, 5, 100, 100, 'Invoice 123 Total $1500.00', imagecolorallocate($image, 0, 0, 0));
        ob_start(); imagepng($image, null, 1); $bytes = ob_get_clean(); imagedestroy($image);
        $file = UploadedFile::fake()->createWithContent('invoice.png', $bytes);
        $small = DocumentPhoto::prepare($file);
        $this->assertSame('image/jpeg', $small->getMimeType());
        $this->assertSame('invoice.jpg', $small->getClientOriginalName());
        $this->assertLessThan($file->getSize(), $small->getSize());
        $this->assertSame([2560, 1920], array_slice(getimagesize($small->getRealPath()), 0, 2));
    }

    public function test_pdf_and_small_photo_bytes_remain_intact(): void
    {
        foreach ([UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4'), UploadedFile::fake()->image('small.jpg', 100, 100)] as $file) {
            $this->assertSame($file, DocumentPhoto::prepare($file));
        }
    }
}
