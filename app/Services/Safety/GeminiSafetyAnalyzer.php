<?php

namespace App\Services\Safety;

use App\Services\Ocr\OcrEngine;
use App\Support\ImageParts;
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
    public function __construct(
        private readonly HttpFactory $http,
        private readonly OcrEngine $ocr,
    ) {
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
     * @param  array<string, mixed>  $context  title, workText, closeText, doneQty, totalQty, unit, photos
     * @return array<string, mixed>
     */
    public function recommendProgress(array $context): array
    {
        $photos = ImageParts::sanitize($context['photos'] ?? []);
        $withPhotos = $photos !== [];

        if ($withPhotos) {
            // 사진이 있으면 비전 엔진(Gemini/Claude)에 사진+마감보고를 함께 넘겨 종합 분석한다.
            $out = $this->ocr->analyze($photos, $this->progressPrompt($context, true), $this->progressSchema(true));
            $result = is_array($out['data'] ?? null) ? $out['data'] : [];
            $result['model'] = $out['model'] ?? '';
        } else {
            $result = $this->generate($this->progressPrompt($context, false), $this->progressSchema(false));
        }

        $result['recommended_progress'] = max(0, min(100, (int) ($result['recommended_progress'] ?? 0)));
        foreach (['photo_findings', 'quality_flags', 'safety_flags'] as $k) {
            $result[$k] = array_values(array_filter(
                array_map(fn ($s) => trim((string) $s), (array) ($result[$k] ?? [])),
                fn ($s) => $s !== '',
            ));
        }
        $result['summary'] = (string) ($result['summary'] ?? '');
        $result['photo_count'] = count($photos);

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
        $trade = (string) ($c['trade'] ?? '');
        $ehs = (string) ($c['ehs'] ?? '');
        $equipment = (string) ($c['equipment'] ?? '');
        $crewText = (string) ($c['crew_text'] ?? '');

        return <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장(LG배터리·SK반도체·현대차 등) 기계·전기·배관 설치 현장의
안전관리자(Safety Manager)입니다. 아래 작업에 대한 **작업 전 안전계획 초안**을 한국어로 작성하세요.
OSHA 및 미국 건설 안전기준을 반영하고, 현실적이고 구체적으로 작성합니다. JSON만 반환하세요.

[작업 정보]
- 프로젝트: {$project}
- 작업장소: {$location}
- 작업명: {$title}
- 공종: {$trade}
- 안전위험도(EHS): {$ehs}
- 투입조: {$crewText}
- 사용장비: {$equipment}
- 작업인원: {$crew}명
- 예정 작업량: {$qty} {$unit}
- 작업내용: {$work}

**공종·안전위험도·사용장비에 딱 맞춰** 위험요인·PPE·작업허가를 구체화하세요
(예: 전기=감전·아크플래시·전기 LOTO·절연장갑, 고소/리프트=추락·안전대·고소작업허가,
용접=화상·화기작업허가·차광면, 밀폐=산소결핍·밀폐공간허가·가스측정).

요구 항목:
- summary: 작업 요약 1~2문장.
- hazards: PHA 위험성평가. 각 항목 {hazard(위험요인), risk_level(상/중/하), control(대책)} 3~6개.
- ptp_steps: PTP 작업 전 점검 단계 4~7개(순서대로).
- required_ppe: 공종·위험에 맞는 필수 개인보호구(기본 안전모·안전화·보안경 + 공종별 추가).
- tbm_topics: TBM(툴박스미팅) 토의 주제 3~5개.
- permits: 필요한 작업허가(PTW) 목록(화기작업/고소작업/밀폐공간/전기 LOTO 등). 해당 없으면 빈 배열.
- heat_environment: 현장·계절 환경 대책 2~4개. 특히 미국 애리조나(피닉스) 여름 폭염 시 열사병 예방
  (수분 섭취 주기·그늘 휴식·초기증상 공유)과 현장 환경(분진·소음·조도·밀폐 등) 대책.
- key_risk: 가장 주의해야 할 핵심 위험 한 줄.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function progressPrompt(array $c, bool $withPhotos = false): string
    {
        $title = (string) ($c['title'] ?? '');
        $close = (string) ($c['closeText'] ?? '');
        $done = (string) ($c['doneQty'] ?? '');
        $total = (string) ($c['totalQty'] ?? '');
        $unit = (string) ($c['unit'] ?? '');

        if ($withPhotos) {
            return <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장 기계·전기·배관 설치 현장의 공정관리자 겸 품질/안전 검측관입니다.
현장 작업자가 **작업 마감과 함께 올린 현장 사진들**과 아래 마감 보고를 **종합 분석**해서
실제 공정율(%)과 품질·안전 소견을 판단하세요. JSON만 반환하세요.

[작업] {$title}
[예정 수량] {$total} {$unit}
[완료 수량(작업자 보고)] {$done} {$unit}
[마감 보고] {$close}

분석 지침:
1. 사진에 실제로 보이는 설치/시공 상태로 작업자 보고 수량·완료도를 **교차 검증**합니다.
   보고와 사진이 어긋나면(예: 완료라는데 미시공 구간이 보임) 사진을 우선해 현실적으로 판정합니다.
2. 사진에서 보이는 **품질 결함**(마감 불량, 배관·전선 정리 미흡, 누락, 오시공, 손상 등)을 지적합니다.
3. 사진에서 보이는 **안전 위험**(정리정돈 불량/걸림, 개구부·추락위험, 전기·화기 위험, PPE 미착용,
   자재 적재 불안정 등 OSHA 관점)을 지적합니다.
4. 근거 없는 추측은 하지 말고, 사진에서 확인 가능한 것만 소견에 적습니다.

요구 항목:
- recommended_progress: 추천 공정율 정수(0~100). 사진 근거로 판단.
- status: 완료 / 일부 완료 / 지연 / 재작업 필요 중 하나.
- summary: 사진+보고 종합 요약 2~3문장(한국어).
- rationale: 공정율 추천 근거 1~2문장(사진 근거 명시).
- photo_findings: 사진에서 확인된 시공/진행 상태 소견 2~5개(문자열 배열).
- quality_flags: 품질 결함·지적 사항(문자열 배열, 없으면 빈 배열).
- safety_flags: 안전 위험·지적 사항(문자열 배열, 없으면 빈 배열).
- follow_up: 후속 조치 권장 1문장(없으면 빈 문자열).
PROMPT;
        }

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
- summary: 마감 보고 요약 1~2문장(한국어).
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
                'heat_environment' => ['type' => 'array', 'items' => ['type' => 'string']],
                'key_risk' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressSchema(bool $withPhotos = false): array
    {
        $props = [
            'recommended_progress' => ['type' => 'integer'],
            'status' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'rationale' => ['type' => 'string'],
            'follow_up' => ['type' => 'string'],
        ];

        if ($withPhotos) {
            $props['photo_findings'] = ['type' => 'array', 'items' => ['type' => 'string']];
            $props['quality_flags'] = ['type' => 'array', 'items' => ['type' => 'string']];
            $props['safety_flags'] = ['type' => 'array', 'items' => ['type' => 'string']];
        }

        return ['type' => 'object', 'properties' => $props];
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim((string) $text);
    }
}
