<?php

namespace App\Services\Ocr;

use App\Support\AiMeter;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * OpenAI(GPT) 비전 분석 엔진 — 세 번째 눈.
 *
 * Gemini 는 원문에서 뽑고, Claude 는 그 값을 시방과 대조해 따지고, 이 엔진은 계산과
 * 표를 맞춘다. 세 답이 갈리면 평균 내지 않고 사람에게 묻는 것이 이 구조의 요점이다 —
 * 평균값은 아무 근거가 없는 숫자다.
 *
 * 형식은 앞의 두 엔진과 다르다:
 *  - 이미지는 image_url 에 data URI 로 넣는다(별도 image 블록이 없다).
 *  - PDF 는 vision 입력으로 못 넣는다. 그래서 PDF 는 이 엔진이 받지 않고 예외를 던져
 *    호출한 쪽이 다른 엔진으로 가게 한다 — 조용히 빈 결과를 주면 그게 더 나쁘다.
 *  - 구조 강제는 response_format.json_schema(strict) 이며, strict 모드는 모든 object 에
 *    additionalProperties:false 와 required 를 요구한다(Claude 와 같은 요구라 정규화를 공유한다).
 */
class OpenAiOcrEngine implements OcrEngine
{
    public function __construct(private readonly HttpFactory $http) {}

    public function name(): string
    {
        return 'openai';
    }

    public static function available(): bool
    {
        return filled(config('services.openai.api_key'));
    }

    /**
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, model: string}
     */
    public function analyze(array $images, string $prompt, array $schema): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $endpoint = rtrim((string) config('services.openai.endpoint', 'https://api.openai.com'), '/').'/v1/chat/completions';
        $model = (string) config('services.openai.model', 'gpt-5');

        $content = [];
        foreach ($images as $img) {
            $data = (string) ($img['data'] ?? '');
            if ($data === '') {
                continue;
            }
            $mime = (string) ($img['mime_type'] ?? 'image/jpeg');
            if (str_contains($mime, 'pdf')) {
                // 여기서 삼키면 "PDF 만 답이 부실한" 원인 모를 증상이 된다.
                throw new RuntimeException('OpenAI 비전 입력은 PDF 를 직접 받지 않습니다. 이미지로 변환하거나 다른 엔진을 쓰세요.');
            }
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$data}"]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $startedAt = microtime(true);
        $response = $this->http
            ->timeout((int) config('services.openai.timeout', 180))
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($endpoint, [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $content]],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'analysis',
                        'strict' => true,
                        'schema' => JsonSchemaNormalizer::strict($schema),
                    ],
                ],
            ]);
        $ms = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            AiMeter::record('openai', 'ocr', $model, durationMs: $ms, ok: false, error: 'HTTP '.$response->status());

            throw new RuntimeException('OpenAI API returned status '.$response->status().': '.$response->body());
        }

        $json = $response->json();
        AiMeter::record('openai', 'ocr', (string) ($json['model'] ?? $model), is_array($json['usage'] ?? null) ? $json['usage'] : [], $ms);

        // strict 모드에서도 모델이 거절할 수 있다 — 그때는 refusal 에 사유가 온다.
        $refusal = $json['choices'][0]['message']['refusal'] ?? null;
        if (is_string($refusal) && trim($refusal) !== '') {
            throw new RuntimeException('OpenAI 가 분석을 거부했습니다: '.$refusal);
        }

        $text = (string) ($json['choices'][0]['message']['content'] ?? '');
        if (trim($text) === '') {
            throw new RuntimeException('OpenAI returned no analysis text.');
        }

        $decoded = json_decode($this->stripJsonFence($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI analysis was not valid JSON.');
        }

        return ['data' => $decoded, 'model' => (string) ($json['model'] ?? $model)];
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim((string) $text);
    }
}
