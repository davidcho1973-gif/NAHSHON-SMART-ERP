<?php

namespace Tests\Feature;

use App\Models\IntelligentDocument;
use App\Services\Documents\DocumentIntelligenceAnalyzer;
use App\Services\Documents\DocumentTextExtractor;
use App\Services\Ocr\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 원본 첨부 분석이 실패하면 텍스트만으로 재시도한다.
 *
 * 10MB대 PDF 는 base64 로 1.3배로 불어 AI 요청 한도·타임아웃에 걸리기 쉽다.
 * 역설적으로 15MB 를 넘는 파일은 처음부터 첨부 없이 텍스트 경로라 성공하고,
 * 12MB 파일이 "분석 실패" 로 남는 구멍이 있었다(실사례: 08_배관_위생.pdf 12.2MB).
 */
class DocumentAnalyzerFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function document(): IntelligentDocument
    {
        return new IntelligentDocument([
            'original_file_name' => '08_배관_위생.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
        ]);
    }

    private function analyzer(OcrEngine $engine, ?string $extractedText): DocumentIntelligenceAnalyzer
    {
        $extractor = new class($extractedText) extends DocumentTextExtractor
        {
            public function __construct(private readonly ?string $text) {}

            public function extract(string $bytes, ?string $extension, ?string $mimeType = null): ?string
            {
                return $this->text;
            }
        };

        return new DocumentIntelligenceAnalyzer($engine, $extractor);
    }

    public function test_첨부_분석이_실패하면_텍스트만으로_재시도해_성공한다(): void
    {
        $engine = new class implements OcrEngine
        {
            public int $calls = 0;

            public function analyze(array $images, string $prompt, array $schema): array
            {
                $this->calls++;
                if ($images !== []) {
                    throw new \RuntimeException('Request payload size exceeds the limit'); // 첨부 경로 실패
                }

                return ['data' => ['title' => '배관 위생 도면', 'category' => 'drawing_spec'], 'model' => 'test'];
            }

            public function name(): string
            {
                return 'test';
            }
        };

        $result = $this->analyzer($engine, '배관 위생 시방 본문...')->analyze($this->document(), str_repeat('x', 1000));

        $this->assertSame(2, $engine->calls, '첨부 실패 후 텍스트 전용으로 한 번 더 시도해야 한다');
        $this->assertSame('배관 위생 도면', $result['data']['title']);
    }

    public function test_추출된_본문이_없으면_원래_오류를_그대로_던진다(): void
    {
        // 스캔 PDF 처럼 본문 추출이 안 되는 파일은 재시도할 재료가 없다 —
        // 조용히 빈 결과를 만드는 것보다 실패 이유를 남기는 게 낫다.
        $engine = new class implements OcrEngine
        {
            public function analyze(array $images, string $prompt, array $schema): array
            {
                throw new \RuntimeException('timeout');
            }

            public function name(): string
            {
                return 'test';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('timeout');

        $this->analyzer($engine, null)->analyze($this->document(), str_repeat('x', 1000));
    }
}
