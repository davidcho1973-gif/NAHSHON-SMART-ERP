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
