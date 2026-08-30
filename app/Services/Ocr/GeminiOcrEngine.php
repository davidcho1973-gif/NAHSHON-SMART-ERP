<?php

namespace App\Services\Ocr;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gemini(Google) 비전 분석 엔진. 기존 Gemini*Analyzer 들이 쓰던 요청 형태를 그대로 재현한다
 * (contents.parts = inline_data[] + text, generationConfig.responseSchema, 모델 폴백 체인).
 */
class GeminiOcrEngine implements OcrEngine
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function name(): string
    {
        return 'gemini';
    }

    /**
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, model: string}
     */
    public function analyze(array $images, string $prompt, array $schema): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $parts = [];
        foreach ($images as $img) {
            if (($img['data'] ?? '') === '') {
                continue;
            }
            $parts[] = ['inline_data' => [
                'mime_type' => (string) ($img['mime_type'] ?? 'image/jpeg'),
                'data' => (string) $img['data'],
            ]];
        }
        $parts[] = ['text' => $prompt];

        $lastException = null;

        foreach ($this->models() as $model) {
            try {
                $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
                    . "/v1beta/models/{$model}:generateContent";

                $startedAt = microtime(true);
                $response = $this->http
                    ->timeout((int) config('services.gemini.timeout', 30))
                    ->withHeaders(['x-goog-api-key' => $apiKey, 'Content-Type' => 'application/json'])
                    ->post($endpoint, [
                        'contents' => [['parts' => $parts]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => $schema,
                        ],
                    ]);

                $ms = (int) round((microtime(true) - $startedAt) * 1000);

                if ($response->failed()) {
                    \App\Support\AiMeter::record('gemini', 'ocr', $model, durationMs: $ms, ok: false, error: 'HTTP '.$response->status());

                    throw new RuntimeException('Gemini API returned status ' . $response->status() . ': ' . $response->body());
                }

                // 모델 폴백이 있어 여러 번 부를 수 있다 — 실제로 돈이 나간 호출마다 적는다.
                \App\Support\AiMeter::record('gemini', 'ocr', $model, is_array($response->json('usageMetadata')) ? $response->json('usageMetadata') : [], $ms);

                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                if (! is_string($text) || trim($text) === '') {
                    throw new RuntimeException('Gemini returned no analysis text.');
                }

                $decoded = json_decode($this->stripJsonFence($text), true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini analysis was not valid JSON.');
                }

                return ['data' => $decoded, 'model' => $model];
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("Gemini OCR model {$model} failed, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed. Last error: ' . ($lastException?->getMessage() ?? 'unknown'));
    }

    /**
     * 시도할 Gemini 모델 목록 — 실제 사용 가능한 모델을 조회해 고른다(하드코딩 404 방지).
     *
     * @return array<int, string>
     */
    private function models(): array
    {
        return app(\App\Support\GeminiModelResolver::class)->candidates();
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```json')) {
            $text = substr($text, 7);
        } elseif (str_starts_with($text, '```')) {
            $text = substr($text, 3);
        }
        if (str_ends_with($text, '```')) {
            $text = substr($text, 0, -3);
        }

        return trim($text);
    }
}
