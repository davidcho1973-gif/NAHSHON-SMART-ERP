<?php

namespace App\Support;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Claude(Anthropic) 를 부르는 자리가 모이는 한 곳.
 *
 * 도면 판독이 처음으로 Claude 를 불렀고, 이제 문서 교차검증이 두 번째로 부른다.
 * 인증 머리글·거부(refusal) 처리·응답에서 글자 모으기를 각자 복사하면, 언젠가
 * 한쪽만 고쳐져 "도면은 되는데 검증은 안 되는" 상태가 된다 — 그래서 여기 모은다.
 *
 * 키가 없으면 available() 이 false 다. 호출자는 그때 조용히 물러나야 한다:
 * 검증이 없다고 원래 하던 분석까지 막히면 안 된다.
 */
class AnthropicChat
{
    public function __construct(private readonly HttpFactory $http) {}

    public function available(): bool
    {
        return trim((string) config('services.anthropic.api_key')) !== '';
    }

    public function model(): string
    {
        return (string) config('services.anthropic.model', 'claude-opus-4-8');
    }

    /**
     * 응답 원문(JSON 구조) 그대로. 호출자가 content 블록을 직접 다룰 때 쓴다.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function raw(array $payload): array
    {
        $endpoint = rtrim((string) config('services.anthropic.endpoint', 'https://api.anthropic.com'), '/').'/v1/messages';

        $response = $this->http
            ->timeout((int) config('services.anthropic.timeout', 180))
            ->withHeaders([
                'x-api-key' => (string) config('services.anthropic.api_key'),
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
            ->post($endpoint, $payload + ['model' => $this->model()]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic API returned status '.$response->status().': '.$response->body());
        }

        $json = $response->json();

        // 안전 분류기가 요청을 물린 경우다. 내용이 비어 있으므로 그대로 두면
        // 호출자가 "빈 답"을 정상으로 오해한다 — 예외로 분명히 알린다.
        if (is_array($json) && ($json['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('Claude 가 요청을 거부했습니다(refusal).');
        }

        return is_array($json) ? $json : [];
    }

    /**
     * 응답에서 글자만 모아 돌려준다(```json 코드펜스 제거).
     *
     * @param  array<string, mixed>  $json
     */
    public function textOf(array $json): string
    {
        $text = '';
        foreach ((is_array($json['content'] ?? null) ? $json['content'] : []) as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        $text = trim($text);
        $text = (string) preg_replace('/^```(?:json)?/i', '', $text);
        $text = (string) preg_replace('/```$/', '', $text);

        return trim($text);
    }

    /**
     * JSON 으로 답하라고 시킨 요청의 결과를 배열로. 형식이 깨졌으면 null —
     * 깨진 답을 억지로 해석하느니 "검증 못 함" 으로 두는 편이 안전하다.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function json(array $payload): ?array
    {
        $decoded = json_decode($this->textOf($this->raw($payload)), true);

        return is_array($decoded) ? $decoded : null;
    }
}
