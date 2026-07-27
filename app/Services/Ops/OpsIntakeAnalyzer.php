<?php

namespace App\Services\Ops;

use App\Services\Ocr\OcrEngine;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 현장 상황실 판독기 — 형식 없이 쓴 현장 대화를 읽어 "무슨 일이 있었는지"를 구조화한다.
 *
 * 카톡 대화 통째로 붙여넣어도 여러 건으로 쪼개고, 잡담은 noise 로 걸러낸다.
 * 공정표·조달 후보 목록을 함께 넘겨, 사람이 부르는 이름("천장배관")을 실제 코드(A100)에 맞춘다.
 */
class OpsIntakeAnalyzer
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly OcrEngine $ocr,
    ) {
    }

    /**
     * @param  array<int, array{code: string, name: string, status?: string, start?: string, end?: string}>  $activities
     * @param  array<int, array{po: string, vendor: string, eta?: string}>  $purchases
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @return array<int, array<string, mixed>>
     */
    public function read(string $text, array $activities, array $purchases, string $today, array $images = [], string $learned = ''): array
    {
        $prompt = $this->prompt($text, $activities, $purchases, $today, $images !== []) . $learned;

        $result = $images !== []
            ? $this->ocr->analyze($images, $prompt, $this->schema())['data'] ?? []
            : $this->generate($prompt, $this->schema());

        $items = $result['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $activities
     * @param  array<int, array<string, mixed>>  $purchases
     */
    private function prompt(string $text, array $activities, array $purchases, string $today, bool $withImages = false): string
    {
        $photoRule = $withImages ? <<<'P'

## 첨부 사진 판독 (사진이 함께 왔습니다)
- 사진에 **실제로 보이는 시공 상태**로 진행 정도를 판단하세요(예: 트레이 3구간 중 2구간 포설 완료 → 약 66%).
- 영수증 사진이면 category=expense 로, 상호·금액을 summary 에 적으세요.
- 자재 납품/송장 사진이면 category=procurement 로 보세요.
- 안전 위험(개구부·추락·정리불량·PPE 미착용)이 보이면 category=issue 로 잡으세요.
- 글에 대상 작업이 적혀 있으면 **그 작업을 우선**하고, 사진은 상태 판정에만 쓰세요.
- 사진만으로 대상을 확신할 수 없으면 target_code 를 비우고 question 에 되물으세요.
- 사진에서 **확인되지 않는 수치는 만들지 마세요.**
P : '';

        return $this->body($text, $activities, $purchases, $today) . $photoRule;
    }

    /**
     * @param  array<int, array<string, mixed>>  $activities
     * @param  array<int, array<string, mixed>>  $purchases
     */
    private function body(string $text, array $activities, array $purchases, string $today): string
    {
        $actList = collect($activities)->take(200)->map(
            fn (array $a) => sprintf(
                '- [%s] %s (상태:%s, 예정:%s~%s)',
                $a['code'] ?? '', $a['name'] ?? '', $a['status'] ?? '-', $a['start'] ?? '-', $a['end'] ?? '-',
            ),
        )->implode("\n") ?: '(등록된 공정 없음)';

        $poList = collect($purchases)->take(100)->map(
            fn (array $p) => sprintf('- [%s] %s (ETA:%s)', $p['po'] ?? '', $p['vendor'] ?? '', $p['eta'] ?? '-'),
        )->implode("\n") ?: '(등록된 발주 없음)';

        return <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장 설치현장(기계·전기·배관)의 공정관리자입니다.
아래는 현장 사람들이 **형식 없이 주고받은 대화/메모**입니다. 카카오톡 대화를 통째로 붙여넣었을
수도 있습니다. 이것을 읽고 **업무에 반영할 항목만** 뽑아 구조화하세요. JSON 만 반환합니다.

[오늘 날짜] {$today}

[등록된 공정 목록 — 반드시 이 중에서 대상을 고르세요]
{$actList}

[등록된 발주 목록]
{$poList}

[현장 대화 원문]
{$text}

## 분류 규칙 (category)
- progress : 이미 한 일. 마감·완료·진행률. (과거형: "했다", "끝냈다", "20개 중 12개")
- plan     : 앞으로 할 일. 내일/다음주 계획, 신규 작업 추가, 인원 투입 예정.
- procurement : 자재 발주·납기·입고. ("화요일 도착", "발주 넣었다")
- labor    : 인력 투입·출역·결원. ("3명 투입", "김씨 오늘 못 나옴")
- expense  : 지출·영수증·구매 비용.
- issue    : 사고·안전·하자·민원·작업중단 사유.
- noise    : 업무와 무관한 잡담(인사, 식사, 날씨 한담 등). **반드시 noise 로 분류하고 무시되게 하세요.**

## 매우 중요한 규칙
1. 대화에 여러 건이 섞여 있으면 **건별로 나눠** items 에 각각 넣으세요.
2. 대상 공정을 고를 때는 **반드시 위 [등록된 공정 목록]의 code 를 그대로** target_code 에 쓰세요.
   목록에 없거나 확신이 없으면 target_code 를 빈 문자열로 두세요. **절대 코드를 지어내지 마세요.**
3. 사람들은 코드가 아니라 이름으로 부릅니다("천장 배관", "2층 전기"). 이름으로 목록과 대조해 찾으세요.
4. 후보가 둘 이상이라 확정 못 하면 confidence 를 50 이하로 주고 question 에 되물을 말을 쓰세요.
   예: "천장배관이 A100(1층)인지 A140(2층)인지 알려주세요."
5. 날짜는 반드시 YYYY-MM-DD 로 환산하세요("내일", "다음주 화요일" → 오늘 기준 실제 날짜).
6. **추측으로 값을 만들지 마세요.** 대화에 없는 내용은 비워 두세요.

## proposed(변경안) 작성법 — 확실한 것만
- 진행률: {"progress": 60}
- 상태:   {"status": "진행중"} 또는 {"status": "완료"}
- 일정:   {"planned_start": "2026-07-28", "planned_end": "2026-07-30"}
- 인원:   {"crew_size": 3}
- 발주ETA:{"eta": "2026-07-29"}
대화에서 확실히 읽히는 항목만 넣고, 나머지 키는 아예 넣지 마세요.

## 반환 항목 (items 배열)
- raw_text     : 이 항목의 근거가 된 원문 조각(그대로 인용)
- speaker      : 말한 사람 이름(대화에 있으면, 없으면 빈 문자열)
- category     : 위 분류 중 하나
- confidence   : 0~100 (대상·내용 확신도)
- summary      : 한국어 한 줄 요약(관리자가 읽을 문장)
- target_type  : "wbs" | "procurement" | "" (대상 없으면 빈 문자열)
- target_code  : 위 목록의 code/PO번호 그대로. 확신 없으면 빈 문자열
- target_name  : 대상의 이름(표시용)
- occurred_on  : 이 내용이 해당하는 날짜(YYYY-MM-DD). 모르면 빈 문자열
- proposed     : 위 형식의 변경안 객체. 없으면 빈 객체 {}
- question     : 확인이 필요할 때 관리자에게 되물을 한 문장. 없으면 빈 문자열
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'raw_text' => ['type' => 'string'],
                            'speaker' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'confidence' => ['type' => 'integer'],
                            'summary' => ['type' => 'string'],
                            'target_type' => ['type' => 'string'],
                            'target_code' => ['type' => 'string'],
                            'target_name' => ['type' => 'string'],
                            'occurred_on' => ['type' => 'string'],
                            'proposed' => ['type' => 'object'],
                            'question' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
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

        $lastException = null;
        foreach ($this->models() as $model) {
            try {
                $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
                    . "/v1beta/models/{$model}:generateContent";

                $response = $this->http
                    ->timeout((int) config('services.gemini.timeout', 60))
                    ->withHeaders(['x-goog-api-key' => $apiKey, 'Content-Type' => 'application/json'])
                    ->post($endpoint, [
                        'contents' => [['parts' => [['text' => $prompt]]]],
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

                $decoded = json_decode($this->stripFence($text), true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini response was not valid JSON.');
                }

                return $decoded;
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("상황실 판독 모델 {$model} 실패, 폴백: " . $e->getMessage());
            }
        }

        throw new RuntimeException('상황실 판독 실패: ' . ($lastException?->getMessage() ?? 'unknown'));
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

    private function stripFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim((string) $text);
    }
}
