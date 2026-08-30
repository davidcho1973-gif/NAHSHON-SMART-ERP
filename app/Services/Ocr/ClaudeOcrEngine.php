<?php

namespace App\Services\Ocr;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Claude(Anthropic) 비전 분석 엔진. 이미지/PDF 를 네이티브로 읽어(문서 이해=OCR) 구조화 JSON 을 만든다.
 * 도메인 스키마(Gemini responseSchema 형식)를 그대로 받아 Anthropic structured outputs 형식으로
 * 변환(모든 object 에 additionalProperties:false 주입)한 뒤 output_config.format 으로 강제한다.
 */
class ClaudeOcrEngine implements OcrEngine
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function name(): string
    {
        return 'claude';
    }

    /**
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, model: string}
     */
    public function analyze(array $images, string $prompt, array $schema): array
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $endpoint = rtrim((string) config('services.anthropic.endpoint', 'https://api.anthropic.com'), '/') . '/v1/messages';
        $model = (string) config('services.anthropic.model', 'claude-opus-4-8');

        $content = [];
        foreach ($images as $img) {
            $data = (string) ($img['data'] ?? '');
            if ($data === '') {
                continue;
            }
            $mime = (string) ($img['mime_type'] ?? 'image/jpeg');
            // PDF 는 document 블록, 이미지는 image 블록으로 넣는다.
            $content[] = str_contains($mime, 'pdf')
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $startedAt = microtime(true);
        $response = $this->http
            ->timeout((int) config('services.anthropic.timeout', 180))
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
            ->post($endpoint, [
                'model' => $model,
                'max_tokens' => 8000,
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => JsonSchemaNormalizer::strict($schema)]],
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);

        $ms = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            \App\Support\AiMeter::record('claude', 'ocr', $model, durationMs: $ms, ok: false, error: 'HTTP '.$response->status());

            throw new RuntimeException('Anthropic API returned status ' . $response->status() . ': ' . $response->body());
        }

        $json = $response->json();
        \App\Support\AiMeter::record('claude', 'ocr', (string) ($json['model'] ?? $model), is_array($json['usage'] ?? null) ? $json['usage'] : [], $ms);

        if (($json['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('Claude 가 이미지/문서 분석을 거부했습니다(refusal).');
        }

        $text = '';
        foreach ((is_array($json['content'] ?? null) ? $json['content'] : []) as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        if (trim($text) === '') {
            throw new RuntimeException('Claude returned no analysis text.');
        }

        $decoded = json_decode($this->stripJsonFence($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Claude analysis was not valid JSON.');
        }

        return ['data' => $decoded, 'model' => $model];
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim((string) $text);
    }
}
