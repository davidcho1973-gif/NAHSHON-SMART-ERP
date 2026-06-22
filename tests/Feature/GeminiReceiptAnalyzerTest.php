<?php

namespace Tests\Feature;

use App\Services\GeminiReceiptAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiReceiptAnalyzerTest extends TestCase
{
    public function test_it_extracts_receipt_fields_from_gemini_json_response(): void
    {
        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-2.5-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'vendor_name' => 'McDonalds',
                                'amount' => 15.50,
                                'date' => '2026-06-20',
                                'category' => 'Meals & Entertainment',
                                'description' => 'Burger and fries',
                            ]),
                        ]],
                    ],
                ]],
            ]),
        ]);

        $path = $this->temporaryImagePath();

        $result = app(GeminiReceiptAnalyzer::class)->analyze($path, 'image/jpeg');

        $this->assertSame('McDonalds', $result['vendor_name']);
        $this->assertSame(15.50, $result['amount']);
        $this->assertSame('2026-06-20', $result['date']);
        $this->assertSame('Meals & Entertainment', $result['category']);
        $this->assertSame('Burger and fries', $result['description']);
        $this->assertSame('gemini-2.5-flash', $result['model']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/v1beta/models/gemini-2.5-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && data_get($data, 'contents.0.parts.0.inline_data.mime_type') === 'image/jpeg'
                && filled(data_get($data, 'contents.0.parts.0.inline_data.data'))
                && data_get($data, 'generationConfig.responseMimeType') === 'application/json'
                && data_get($data, 'generationConfig.responseSchema.type') === 'object';
        });
    }

    public function test_it_requires_a_gemini_api_key(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY');

        app(GeminiReceiptAnalyzer::class)->analyze($this->temporaryImagePath(), 'image/jpeg');
    }

    private function temporaryImagePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt-image-');
        file_put_contents($path, 'fake-image-bytes');

        return $path;
    }
}
