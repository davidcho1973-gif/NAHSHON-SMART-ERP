<?php

namespace Tests\Feature;

use App\Services\GeminiDocumentAnalyzer;
use App\Services\Ocr\OcrEngine;
use RuntimeException;
use Tests\TestCase;

/**
 * 계약·컴플라이언스 문서 AI 자동분석 — 공통 OcrEngine 위임 + 정규화.
 */
class GeminiDocumentAnalyzerTest extends TestCase
{
    private function fakeEngine(array $data): void
    {
        $this->app->instance(OcrEngine::class, new class($data) implements OcrEngine {
            public function __construct(private array $data) {}

            public function analyze(array $images, string $prompt, array $schema): array
            {
                return ['data' => $this->data, 'model' => 'gemini-test'];
            }

            public function name(): string
            {
                return 'gemini';
            }
        });
    }

    private function tempFile(string $suffix, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'doc') . $suffix;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_normalizes_extracted_document_fields(): void
    {
        $this->fakeEngine([
            'document_type' => 'certificate_of_insurance', 'title' => 'COI - Acme', 'document_number' => 'GL-12345',
            'issued_on' => '2026-01-01', 'effective_on' => '2026-01-01', 'expires_on' => '2026-12-31',
            'issuer' => 'Acme Insurance', 'counterparty' => 'NAHSHON MEP', 'amount' => 2000000, 'currency' => 'USD',
            'summary' => '일반배상책임 200만불',
            'fields' => [['label' => '보장한도', 'value' => '$2,000,000'], ['label' => '', 'value' => 'skip']],
        ]);
        $tmp = $this->tempFile('.png', "\x89PNG fake image bytes");

        $data = app(GeminiDocumentAnalyzer::class)->analyze($tmp, 'image/png');
        @unlink($tmp);

        $this->assertSame('certificate_of_insurance', $data['document_type']);
        $this->assertSame('2026-12-31', $data['expires_on']);   // 만료일 = 컴플라이언스 핵심
        $this->assertSame(2000000.0, $data['amount']);
        $this->assertSame('USD', $data['currency']);
        $this->assertSame(['보장한도' => '$2,000,000'], $data['fields']); // 빈 라벨 항목은 제외
        $this->assertSame('gemini', $data['engine']);
    }

    public function test_invalid_dates_become_null(): void
    {
        $this->fakeEngine(['document_type' => 'w9', 'issued_on' => 'unknown', 'expires_on' => '', 'fields' => []]);
        $tmp = $this->tempFile('.png', 'x');

        $data = app(GeminiDocumentAnalyzer::class)->analyze($tmp, 'image/png');
        @unlink($tmp);

        $this->assertNull($data['issued_on']);
        $this->assertNull($data['expires_on']);
        $this->assertSame('w9', $data['document_type']);
    }

    public function test_unsupported_file_type_throws(): void
    {
        $this->fakeEngine([]);
        $tmp = $this->tempFile('.doc', 'MSWORD binary');

        $this->expectException(RuntimeException::class);
        try {
            app(GeminiDocumentAnalyzer::class)->analyze($tmp, 'application/msword');
        } finally {
            @unlink($tmp);
        }
    }

    public function test_docx_word_document_is_extracted_and_analyzed(): void
    {
        $this->fakeEngine([
            'document_type' => 'safety_plan', 'title' => 'JSA - Electrical', 'summary' => '전기배관 JSA',
            'fields' => [['label' => 'PPE', 'value' => 'gloves, harness']],
        ]);

        // 최소 .docx(OOXML zip) 를 즉석에서 만든다.
        $tmp = tempnam(sys_get_temp_dir(), 'doc') . '.docx';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE);
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'
            . '<w:p><w:r><w:t>JOB SAFETY ANALYSIS (JSA)</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Task: Electrical conduit installation. Hazard: shock. PPE: gloves.</w:t></w:r></w:p>'
            . '</w:body></w:document>');
        $zip->close();

        $data = app(GeminiDocumentAnalyzer::class)->analyze(
            $tmp,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
        @unlink($tmp);

        $this->assertSame('safety_plan', $data['document_type']);
        $this->assertSame('JSA - Electrical', $data['title']);
        $this->assertSame(['PPE' => 'gloves, harness'], $data['fields']);
    }

    public function test_pptx_presentation_is_extracted_and_analyzed(): void
    {
        $this->fakeEngine(['document_type' => 'safety_plan', 'title' => '안전교육 자료', 'fields' => []]);

        $tmp = tempnam(sys_get_temp_dir(), 'ppt') . '.pptx';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE);
        $zip->addFromString('ppt/slides/slide1.xml', '<p:sld xmlns:a="a"><a:t>현장 안전 교육 자료</a:t><a:t>추락 방지 대책 및 PPE 착용</a:t></p:sld>');
        $zip->close();

        $data = app(GeminiDocumentAnalyzer::class)->analyze(
            $tmp,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        );
        @unlink($tmp);

        $this->assertSame('safety_plan', $data['document_type']);
        $this->assertSame('안전교육 자료', $data['title']);
    }
}
