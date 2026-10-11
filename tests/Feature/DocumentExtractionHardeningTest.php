<?php

namespace Tests\Feature;

use App\Models\IntelligentDocument;
use App\Services\Documents\DocumentAnalysisFailure;
use App\Services\Documents\DocumentCrossCheck;
use App\Services\Documents\DocumentIntelligenceAnalyzer;
use App\Services\Documents\DocumentTextExtractor;
use App\Services\Ocr\OcrEngine;
use App\Support\AnthropicChat;
use Tests\TestCase;

class DocumentExtractionHardeningTest extends TestCase
{
    private function archive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-office-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $text) {
            $zip->addFromString($name, $text);
        }
        $zip->close();
        try {
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    public function test_korean_character_before_blank_paragraphs_survives_docx_extraction(): void
    {
        $text = app(DocumentTextExtractor::class)->extract($this->archive([
            'word/document.xml' => '<w:document xmlns:w="urn:test"><w:p>천장 마감 시방서 석고보드 시험 합니다</w:p><w:p>핅</w:p><w:p></w:p><w:p></w:p><w:p>다음 항목 원문 유지</w:p></w:document>',
        ]), 'docx');
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringContainsString("핅\n\n다음", $text);
        $this->assertJson(json_encode(['text' => $text], JSON_THROW_ON_ERROR));
    }

    public function test_plain_text_and_email_preserve_multibyte_characters(): void
    {
        $body = "한글 핅\n\n\n본문과 스페인어 información, emoji 🏗️ are preserved.";
        foreach (['txt' => $body, 'csv' => $body, 'eml' => "Subject: 한글 핅 제목\r\nFrom: office@example.com\r\n\r\n".$body] as $extension => $source) {
            $text = app(DocumentTextExtractor::class)->extract($source, $extension);
            $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
            $this->assertStringContainsString("핅\n\n본문", $text);
            $this->assertStringContainsString('información', $text);
        }
    }

    public function test_multipart_email_decodes_headers_and_body_without_including_large_binary_attachments(): void
    {
        $plain = 'Review the ceiling café scope <703K> before Friday. The quoted price is $1,250.00.';
        $bytes = implode("\r\n", [
            'Subject: =?UTF-8?B?'.base64_encode('703K 천장').'?=',
            ' =?UTF-8?B?'.base64_encode(' 검토').'?=',
            'From: =?UTF-8?B?'.base64_encode('김 담당').'?= <office@example.com>',
            'To: manager@example.com,',
            ' owner@example.com',
            'Content-Type: multipart/mixed;',
            ' boundary="outer-boundary"', '',
            'This MIME preamble is not the message body.',
            '--outer-boundary', 'Content-Type: multipart/alternative; boundary="alternative"', '',
            '--alternative', 'Content-Type: text/plain; charset=windows-1252',
            'Content-Transfer-Encoding: quoted-printable', '',
            quoted_printable_encode(mb_convert_encoding($plain, 'Windows-1252', 'UTF-8')),
            '--alternative', 'Content-Type: text/html; charset=UTF-8', '',
            '<p>Alternative duplicate must not be included.</p>',
            '--alternative--',
            '--outer-boundary', 'Content-Type: application/pdf',
            "Content-Disposition: attachment; filename*0*=UTF-8''703K_%EC%B2%9C%EC%9E%A5;",
            ' filename*1*=%20%EA%B2%AC%EC%A0%81.pdf',
            'Content-Transfer-Encoding: base64', '',
            chunk_split(base64_encode(str_repeat('BINARY_ATTACHMENT_ONLY', 270000)), 76, "\r\n"),
            '--outer-boundary', 'Content-Type: text/plain; name="instructions.txt"',
            'Content-Disposition: attachment; filename="instructions.txt"', '',
            'Attached evidence must not be mistaken for the sender message.',
            '--outer-boundary--',
            'MIME epilogue must also be excluded.',
        ]);

        $text = app(DocumentTextExtractor::class)->extract($bytes, 'eml');

        $this->assertGreaterThan(7 * 1048576, strlen($bytes));
        $this->assertLessThan(1000, strlen($text));
        $this->assertStringContainsString('Subject: 703K 천장 검토', $text);
        $this->assertStringContainsString('From: 김 담당 <office@example.com>', $text);
        $this->assertStringContainsString('To: manager@example.com, owner@example.com', $text);
        $this->assertStringContainsString($plain, $text);
        $this->assertStringContainsString('703K_천장 견적.pdf (application/pdf); contents not analyzed.', $text);
        $this->assertStringContainsString('instructions.txt (text/plain); contents not analyzed.', $text);
        $this->assertSame(2, substr_count($text, DocumentTextExtractor::EMAIL_ATTACHMENT_REVIEW_MARKER));
        foreach (['Alternative duplicate', 'QklOQVJZX0FUVEFDSE1FTlRfT05MWQ', 'BINARY_ATTACHMENT_ONLY', 'Attached evidence', 'MIME preamble', 'MIME epilogue'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $text);
        }
    }

    public function test_html_email_decodes_base64_and_charset_and_preserves_readable_paragraphs(): void
    {
        $html = '<html><head><style>.hidden{color:red}</style></head><body><script>trackingScript()</script>'
            .'<p>천장 공사 검토</p><p>회신 기한: 금요일 &amp; 현장 확인</p><table><tr><td>항목</td><td>수량 20</td></tr></table></body></html>';
        $htmlPart = "Content-Type: text/html; charset=EUC-KR\nContent-Transfer-Encoding: base64\n\n"
            .chunk_split(base64_encode(mb_convert_encoding($html, 'EUC-KR', 'UTF-8')), 76, "\n");
        $emails = [
            "Subject: HTML email\n".$htmlPart,
            "Subject: Empty plain alternative\nContent-Type: multipart/alternative; boundary=empty-plain\n\n"
                ."--empty-plain\nContent-Type: text/plain\n\n--empty-plain\n".$htmlPart."--empty-plain--\n",
        ];
        foreach ($emails as $bytes) {
            $text = app(DocumentTextExtractor::class)->extract($bytes, null, 'message/rfc822');
            $this->assertStringContainsString("공사 검토\n회신 기한: 금요일 & 현장 확인\n항목 수량 20", $text);
            $this->assertStringNotContainsString('trackingScript', $text);
            $this->assertStringNotContainsString('.hidden', $text);
            $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
            $this->assertStringNotContainsString(DocumentTextExtractor::EMAIL_ATTACHMENT_REVIEW_MARKER, $text);
        }
    }

    public function test_unread_email_attachments_and_inline_images_both_require_review(): void
    {
        $engine = $this->mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->twice()->withArgs(function ($parts, $prompt) {
            return $parts === [] && str_contains($prompt, 'Please review the proposed ceiling scope before Friday.')
                && ! str_contains($prompt, 'UNREAD_ATTACHMENT_CONTENT');
        })->andReturn(['data' => ['title' => 'Email scope review'], 'model' => 'test']);
        $engine->shouldReceive('name')->andReturn('test');
        $analyzer = new DocumentIntelligenceAnalyzer($engine, new DocumentTextExtractor);

        foreach (['inline', 'attachment'] as $disposition) {
            $bytes = implode("\r\n", [
                'Subject: Ceiling scope', 'Content-Type: multipart/related; boundary="related"', '',
                '--related', 'Content-Type: text/plain; charset=UTF-8', '',
                'Please review the proposed ceiling scope before Friday.',
                '--related', 'Content-Type: image/png; name="scope.png"',
                'Content-Disposition: '.$disposition.'; filename="scope.png"',
                'Content-ID: <image001>', 'Content-Transfer-Encoding: base64', '',
                base64_encode('UNREAD_ATTACHMENT_CONTENT'), '--related--',
            ]);
            $result = $analyzer->analyze(new IntelligentDocument(['extension' => 'eml', 'mime_type' => 'message/rfc822']), $bytes);
            $this->assertStringContainsString('첨부 파일 내용은 분석되지 않았습니다', $result['data']['source_review_reason']);
            $this->assertStringNotContainsString('입력 한도', $result['data']['source_review_reason']);
            $this->assertStringContainsString(DocumentTextExtractor::EMAIL_ATTACHMENT_REVIEW_MARKER, $result['extracted_text']);
            if ($disposition === 'inline') {
                $this->assertStringContainsString('[Email inline image not analyzed]', $result['extracted_text']);
            }
        }
    }

    public function test_incomplete_mime_message_is_rejected_before_any_ai_call(): void
    {
        $engine = $this->mock(OcrEngine::class);
        $engine->shouldNotReceive('analyze');
        $bytes = "Subject: Incomplete email\r\nContent-Type: multipart/mixed; boundary=missing-close\r\n\r\n"
            ."--missing-close\r\nContent-Type: text/plain\r\n\r\nA message followed by a truncated attachment.";

        $this->expectExceptionMessage('[INVALID_EMAIL]');
        (new DocumentIntelligenceAnalyzer($engine, new DocumentTextExtractor))->analyze(new IntelligentDocument(['extension' => 'eml']), $bytes);
    }

    public function test_excel_resolves_actual_cells_and_non_contiguous_sheet_numbers(): void
    {
        $bytes = $this->archive([
            'xl/sharedStrings.xml' => '<sst><si><t>벽체 스터드</t></si><si><r><t>천장 </t></r><r><t>흡음판</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>1</v></c><c r="B1"><v>700</v></c><c r="C1" t="inlineStr"><is><t>SF</t></is></c></row></sheetData></worksheet>',
            'xl/worksheets/sheet73.xml' => '<worksheet><sheetData><row r="2"><c r="A2" t="s"><v>0</v></c><c r="B2"><f>10*2</f><v>20</v></c><c r="C2"><f>SUM(B1:B2)</f></c></row></sheetData></worksheet>',
        ]);
        $text = app(DocumentTextExtractor::class)->extract($bytes, 'xlsx');
        $this->assertStringContainsString('A1=천장 흡음판 B1=700 C1=SF', $text);
        $this->assertStringContainsString('sheet73.xml', $text);
        $this->assertStringContainsString('A2=벽체 스터드 B2=20', $text);
        $this->assertStringContainsString('formula without saved result: SUM(B1:B2)', $text);
    }

    public function test_bad_excel_string_reference_is_not_reported_as_a_quantity(): void
    {
        $this->expectExceptionMessage('[INVALID_WORKBOOK]');
        app(DocumentTextExtractor::class)->extract($this->archive([
            'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c r="A1" t="s"><v>900</v></c></row></sheetData></worksheet>',
        ]), 'xlsx');
    }

    public function test_oversized_expanded_office_xml_is_rejected_before_parsing(): void
    {
        $this->expectExceptionMessage('[EXTRACTION_LIMIT]');
        app(DocumentTextExtractor::class)->extract($this->archive([
            'word/document.xml' => str_repeat('x', 8 * 1048576 + 1),
        ]), 'docx');
    }

    public function test_invalid_utf8_is_stopped_before_any_ai_call(): void
    {
        $extractor = $this->mock(DocumentTextExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn("Enough source text but invalid: \xED\x95");
        $engine = $this->mock(OcrEngine::class);
        $engine->shouldNotReceive('analyze');
        $doc = new IntelligentDocument(['extension' => 'docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
        $this->expectExceptionMessage('[INPUT_ENCODING]');
        (new DocumentIntelligenceAnalyzer($engine, $extractor))->analyze($doc, 'bytes');
    }

    public function test_safe_error_categories_do_not_disclose_keys_or_retry_bad_auth(): void
    {
        $error = new \RuntimeException('Gemini API returned status 401: secret-api-key');
        $this->assertStringStartsWith('[AI_AUTH]', DocumentAnalysisFailure::preflightMessage($error));
        $this->assertStringNotContainsString('secret-api-key', DocumentAnalysisFailure::preflightMessage($error));
        $this->assertFalse(DocumentAnalysisFailure::retryWithText($error));
        $this->assertFalse(DocumentAnalysisFailure::retryWithText(new \RuntimeException('HTTP 429 quota')));
        $this->assertTrue(DocumentAnalysisFailure::retryWithText(new \RuntimeException('Request payload size exceeds limit')));
        $invalidEmail = new \RuntimeException('[INVALID_EMAIL] private raw MIME details');
        $this->assertStringStartsWith('[INVALID_EMAIL]', DocumentAnalysisFailure::preflightMessage($invalidEmail));
        $this->assertStringNotContainsString('private raw MIME details', DocumentAnalysisFailure::preflightMessage($invalidEmail));
        $this->assertFalse(DocumentAnalysisFailure::retryWithText($invalidEmail));
    }

    public function test_empty_provider_result_is_not_a_successful_document(): void
    {
        $engine = $this->mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->once()->andReturn(['data' => [], 'model' => 'test']);
        $this->expectExceptionMessage('[EMPTY_ANALYSIS]');
        (new DocumentIntelligenceAnalyzer($engine, new DocumentTextExtractor))->analyze(
            new IntelligentDocument(['extension' => 'txt']), 'A complete text document with sufficient length.'
        );
    }

    public function test_truncated_text_only_analysis_carries_a_review_reason(): void
    {
        $engine = $this->mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->once()->andReturn(['data' => ['title' => 'Partial document'], 'model' => 'test']);
        $engine->shouldReceive('name')->andReturn('test');
        $result = (new DocumentIntelligenceAnalyzer($engine, new DocumentTextExtractor))->analyze(
            new IntelligentDocument(['extension' => 'txt']), str_repeat('complete sentence ', 10000)
        );
        $this->assertNotEmpty($result['data']['source_review_reason']);
    }

    public function test_empty_workbook_is_not_made_readable_by_sheet_labels(): void
    {
        $this->assertNull(app(DocumentTextExtractor::class)->extract($this->archive([
            'xl/worksheets/sheet1.xml' => '<worksheet><sheetData/></worksheet>',
        ]), 'xlsx'));
    }

    public function test_external_entities_are_rejected_without_loading_them(): void
    {
        $this->expectExceptionMessage('[INVALID_WORKBOOK]');
        app(DocumentTextExtractor::class)->extract($this->archive([
            'xl/sharedStrings.xml' => '<!DOCTYPE sst [<!ENTITY secret SYSTEM "file:///not-read">]><sst><si><t>&secret;</t></si></sst>',
        ]), 'xlsx');
    }

    public function test_cross_check_uses_current_extraction_not_old_stored_text(): void
    {
        config(['document-intelligence.cross_check.enabled' => true]);
        $chat = $this->mock(AnthropicChat::class);
        $chat->shouldReceive('available')->andReturnTrue();
        $chat->shouldReceive('model')->andReturn('test');
        $chat->shouldReceive('json')->once()->withArgs(function ($payload) {
            $body = json_encode($payload);

            return str_contains($body, 'CURRENT SOURCE 2000') && ! str_contains($body, 'OLD SOURCE 9000');
        })->andReturn(['amount' => 2000, 'currency' => 'USD', 'flow' => 'out']);
        $doc = new IntelligentDocument(['mime_type' => 'text/plain', 'extracted_text' => 'OLD SOURCE 9000']);
        (new DocumentCrossCheck($chat))->check($doc,
            ['money' => ['amount' => 2000, 'flow' => 'out', 'currency' => 'USD']],
            'source', ['extracted_text' => 'CURRENT SOURCE 2000']);
    }
}
