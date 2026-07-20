<?php

namespace Tests\Feature;

use App\Support\PdfText;
use Tests\TestCase;

/**
 * PDF 텍스트 추출 — 공정표 vision 미추출 문제를 피하려 서버가 표 텍스트를 뽑아 AI 에 정본으로 준다.
 * 여기서는 폴백 가드(스캔본/비PDF → null)를 고정한다. 실제 CPM 추출은 라이브로 확인함(80행).
 */
class PdfTextTest extends TestCase
{
    public function test_non_pdf_bytes_return_null(): void
    {
        $this->assertNull(PdfText::extract('this is not a pdf'));
        $this->assertNull(PdfText::extract(''));
    }

    public function test_payload_guard_ignores_non_pdf_media_type(): void
    {
        // 이미지 업로드는 텍스트 추출 대상이 아니다 → null → vision 경로로 폴백.
        $this->assertNull(PdfText::fromPayload(['data' => base64_encode('%PDF-1.4 tiny'), 'media_type' => 'image/png']));
        $this->assertNull(PdfText::fromPayload(null));
        $this->assertNull(PdfText::fromPayload(['media_type' => 'application/pdf'])); // no data
    }

    public function test_short_pdf_text_falls_back_to_null(): void
    {
        // %PDF 로 시작해도 의미 있는 텍스트가 없으면(스캔본 등) null.
        $this->assertNull(PdfText::extract('%PDF-1.4 no real text stream here'));
    }
}
