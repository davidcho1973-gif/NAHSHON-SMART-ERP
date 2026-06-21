<?php

namespace Tests\Feature;

use App\Services\GeminiBadgeAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiBadgeAnalyzerTest extends TestCase
{
    public function test_it_extracts_badge_fields_from_gemini_json_response(): void
    {
        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-3.5-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'company_name' => 'AI KOREA',
                                'first_name' => 'David',
                                'last_name' => 'Cho',
                                'full_name' => 'David Cho',
                                'role' => 'Foreman',
                                'issued_on' => '06/20/2026',
                                'badge_number' => 'BB-100',
                                'confidence' => 91,
                            ]),
                        ]],
                    ],
                ]],
            ]),
        ]);

        $path = $this->temporaryImagePath();

        $result = app(GeminiBadgeAnalyzer::class)->analyze($path, 'image/jpeg');

        $this->assertSame('AI KOREA', $result['company_name']);
        $this->assertSame('David', $result['first_name']);
        $this->assertSame('Cho', $result['last_name']);
        $this->assertSame('David Cho', $result['full_name']);
        $this->assertSame('Foreman', $result['role']);
        $this->assertSame('2026-06-20', $result['issued_on']);
        $this->assertSame('BB-100', $result['badge_number']);
        $this->assertSame(91, $result['confidence']);
        $this->assertSame('gemini-3.5-flash', $result['model']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/v1beta/models/gemini-3.5-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && data_get($data, 'contents.0.parts.0.inline_data.mime_type') === 'image/jpeg'
                && filled(data_get($data, 'contents.0.parts.0.inline_data.data'))
                && data_get($data, 'generationConfig.responseFormat.text.mimeType') === 'application/json';
        });
    }

    public function test_it_requires_a_gemini_api_key(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY');

        app(GeminiBadgeAnalyzer::class)->analyze($this->temporaryImagePath(), 'image/jpeg');
    }

    private function temporaryImagePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'badge-image-');
        file_put_contents($path, 'fake-image-bytes');

        return $path;
    }
}
