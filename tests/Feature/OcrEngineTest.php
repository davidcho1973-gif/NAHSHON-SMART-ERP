<?php

namespace Tests\Feature;

use App\Services\GeminiReceiptAnalyzer;
use App\Services\Ocr\ClaudeOcrEngine;
use App\Services\Ocr\GeminiOcrEngine;
use App\Services\Ocr\OcrEngine;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 공통 OCR 엔진: 엔진 선택(gemini/claude 자동·강제) + Claude 경로가 실제로 동작하는지.
 */
class OcrEngineTest extends TestCase
{
    private function imagePath(): string
    {
        $p = tempnam(sys_get_temp_dir(), 'ocr-');
        file_put_contents($p, 'fake-image-bytes');

        return $p;
    }

    public function test_resolver_defaults_to_gemini_without_anthropic_key(): void
    {
        config(['services.ai_ocr.engine' => null, 'services.anthropic.api_key' => null]);
        $this->assertInstanceOf(GeminiOcrEngine::class, app(OcrEngine::class));
    }

    public function test_resolver_auto_selects_claude_when_anthropic_key_present(): void
    {
        config(['services.ai_ocr.engine' => null, 'services.anthropic.api_key' => 'sk-ant-x']);
        $this->assertInstanceOf(ClaudeOcrEngine::class, app(OcrEngine::class));
    }

    public function test_resolver_honours_explicit_engine_override(): void
    {
        // Force gemini even though an anthropic key exists.
        config(['services.ai_ocr.engine' => 'gemini', 'services.anthropic.api_key' => 'sk-ant-x']);
        $this->assertInstanceOf(GeminiOcrEngine::class, app(OcrEngine::class));

        config(['services.ai_ocr.engine' => 'claude', 'services.anthropic.api_key' => null]);
        $this->assertInstanceOf(ClaudeOcrEngine::class, app(OcrEngine::class));
    }

    public function test_receipt_analyzer_runs_through_claude_engine(): void
    {
        // Route the shared engine to Claude and confirm a domain analyzer uses the Anthropic API.
        config([
            'services.ai_ocr.engine' => 'claude',
            'services.anthropic.api_key' => 'sk-ant-test',
            'services.anthropic.model' => 'claude-opus-4-8',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'vendor_name' => 'Home Depot',
                        'amount' => 42.10,
                        'date' => '2026-07-10',
                        'category' => '6300 Job Materials',
                        'accounting_account' => '6300 Job Materials',
                        'description' => 'Conduit fittings',
                        'handwritten_notes' => '',
                    ]),
                ]],
            ]),
        ]);

        $result = app(GeminiReceiptAnalyzer::class)->analyze($this->imagePath(), 'image/jpeg');

        $this->assertSame('Home Depot', $result['vendor_name']);
        $this->assertSame(42.10, $result['amount']);
        $this->assertSame('claude-opus-4-8', $result['model']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'api.anthropic.com/v1/messages')
                && $request->hasHeader('x-api-key', 'sk-ant-test')
                // image block first, then the text prompt
                && data_get($data, 'messages.0.content.0.type') === 'image'
                && data_get($data, 'messages.0.content.1.type') === 'text'
                // schema was made strict for Anthropic structured outputs
                && data_get($data, 'output_config.format.type') === 'json_schema'
                && data_get($data, 'output_config.format.schema.additionalProperties') === false;
        });
    }
}
