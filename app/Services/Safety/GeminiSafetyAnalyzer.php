<?php

namespace App\Services\Safety;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gemini-backed AI for the 작업안전관리 flow.
 *
 *   generatePlan()      작업내용 → PHA(위험성평가) · PTP(작업 전 점검) · 필수 PPE · TBM 주제 · 필요 허가 초안
 *   recommendProgress() 마감 보고 + 수량 → 추천 공정율(%) · 근거 · 상태
 *
 * Text-only generation with a structured responseSchema. Falls back across Gemini
 * model versions, mirroring the existing image analyzers.
 */
class GeminiSafetyAnalyzer
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @param  array<string, mixed>  $context  title, workText, project, location, crew, qty, unit
     * @return array<string, mixed>
     */
    public function generatePlan(array $context): array
    {
        $prompt = $this->planPrompt($context);

        return $this->generate($prompt, $this->planSchema());
    }

    /**
     * @param  array<string, mixed>  $context  title, workText, closeText, doneQty, totalQty, unit
     * @return array<string, mixed>
     */
    public function recommendProgress(array $context): array
    {
        $prompt = $this->progressPrompt($context);

        $result = $this->generate($prompt, $this->progressSchema());

        $result['recommended_progress'] = max(0, min(100, (int) ($result['recommended_progress'] ?? 0)));

        return $result;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function generate(string $prompt, array $schema): array
    {
        $apiKey = (string) config('services.gemini.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $models = $this->models();
        $lastException = null;

        foreach ($models as $model) {
            try {
                $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
                    . "/v1beta/models/{$model}:generateContent";

                $response = $this->http
                    ->timeout((int) config('services.gemini.timeout', 30))
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($endpoint, [
                        'contents' => [[
                            'parts' => [['text' => $prompt]],
                        ]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => $schema,
                        ],
                    ]);

                if ($response->failed()) {
                    throw new RuntimeException('Gemini API returned status ' . $response->status() . ': ' . $response->body());
                }

                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

                if (! is_string($text) || trim($text) === '') {
                    throw new RuntimeException('Gemini returned no text.');
                }

                $decoded = json_decode($this->stripJsonFence($text), true);

                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini response was not valid JSON.');
                }

                $decoded['model'] = $model;

                return $decoded;
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("Gemini safety model {$model} failed, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed. Last error: ' . ($lastException?->getMessage() ?? 'unknown'));
    }

    /**
     * @return array<int, string>
     */
    private function models(): array
    {
        $models = [
            'gemini-3.5-pro', 'gemini-2.5-pro', 'gemini-2.0-pro', 'gemini-1.5-pro',
            'gemini-3.5-flash', 'gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash',
        ];

        $configured = (string) config('services.gemini.model');
        if ($configured !== '') {
            $models = array_values(array_filter($models, fn ($m) => $m !== $configured));
            array_unshift($models, $configured);
        }

        return $models;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function planPrompt(array $c): string
    {
        $title = (string) ($c['title'] ?? '');
        $work = (string) ($c['workText'] ?? '');
        $project = (string) ($c['project'] ?? '');
        $location = (string) ($c['location'] ?? $c['site'] ?? '');
        $crew = (string) ($c['crew'] ?? '');
        $qty = (string) ($c['qty'] ?? '');
        $unit = (string) ($c['unit'] ?? '');

        return <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장(LG배터리·SK반도체·현대차 등) 기계·전기·배관 설치 현장의
안전관리자(Safety Manager)입니다. 아래 작업에 대한 **작업 전 안전계획 초안**을 한국어로 작성하세요.
OSHA 및 미국 건설 안전기준을 반영하고, 현실적이고 구체적으로 작성합니다. JSON만 반환하세요.

[작업 정보]
- 프로젝트: {$project}
- 작업장소: {$location}
- 작업명: {$title}
- 작업인원: {$crew}명
- 예정 작업량: {$qty} {$unit}
- 작업내용: {$work}

요구 항목:
- summary: 작업 요약 1~2문장.
- hazards: PHA 위험성평가. 각 항목 {hazard(위험요인), risk_level(상/중/하), control(대책)} 3~6개.
- ptp_steps: PTP 작업 전 점검 단계 4~7개(순서대로).
- required_ppe: 필수 개인보호구 목록.
- tbm_topics: TBM(툴박스미팅) 토의 주제 3~5개.
- permits: 필요한 작업허가(PTW) 목록(예: 화기작업, 고소작업, 밀폐공간, 전기 LOTO 등). 해당 없으면 빈 배열.
- key_risk: 가장 주의해야 할 핵심 위험 한 줄.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function progressPrompt(array $c): string
    {
        $title = (string) ($c['title'] ?? '');
        $close = (string) ($c['closeText'] ?? '');
        $done = (string) ($c['doneQty'] ?? '');
        $total = (string) ($c['totalQty'] ?? '');
        $unit = (string) ($c['unit'] ?? '');

        return <<<PROMPT
당신은 건설 현장 공정관리자입니다. 아래 작업 마감 보고를 바탕으로 **실제 공정율(%)을 추천**하세요.
단순 수량 비율만 보지 말고, 마감 보고의 재작업·지연·품질 이슈를 반영해 현실적으로 판단합니다.
JSON만 반환하세요.

[작업] {$title}
[예정 수량] {$total} {$unit}
[완료 수량] {$done} {$unit}
[마감 보고] {$close}

요구 항목:
- recommended_progress: 추천 공정율 정수(0~100).
- status: 완료 / 일부 완료 / 지연 / 재작업 필요 중 하나.
- rationale: 추천 근거 1~2문장(한국어).
- follow_up: 후속 조치 권장 1문장(없으면 빈 문자열).
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function planSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'hazards' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'hazard' => ['type' => 'string'],
                            'risk_level' => ['type' => 'string'],
                            'control' => ['type' => 'string'],
                        ],
                    ],
                ],
                'ptp_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'required_ppe' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tbm_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                'permits' => ['type' => 'array', 'items' => ['type' => 'string']],
                'key_risk' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recommended_progress' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
                'rationale' => ['type' => 'string'],
                'follow_up' => ['type' => 'string'],
            ],
        ];
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim((string) $text);
    }
}
