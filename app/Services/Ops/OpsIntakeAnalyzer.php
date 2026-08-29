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
    ) {}

    /**
     * @param  array<int, array{code: string, name: string, status?: string, start?: string, end?: string}>  $activities
     * @param  array<int, array{po: string, vendor: string, eta?: string}>  $purchases
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @return array<int, array<string, mixed>>
     */
    public function read(string $text, array $activities, array $purchases, string $today, array $images = [], string $learned = '', array $photoKinds = [], array $specs = [], array $inspections = []): array
    {
        $prompt = $this->prompt($text, $activities, $purchases, $today, $images !== [], $photoKinds, $specs, $inspections).$learned;

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
    private function prompt(string $text, array $activities, array $purchases, string $today, bool $withImages = false, array $photoKinds = [], array $specs = [], array $inspections = []): string
    {
        $kindLine = $photoKinds === [] ? '' : ("\n[첨부 사진 종류(자동 판별)]\n".collect($photoKinds)
            ->map(fn (array $k, int $i) => sprintf('- %d번째 사진: %s%s', $i + 1, $k['label'] ?? '', ($k['summary'] ?? '') !== '' ? ' — '.$k['summary'] : ''))
            ->implode("\n")."\n");

        $photoRule = $withImages ? <<<'P'

## 첨부 사진 판독 (사진이 함께 왔습니다)
- 사진에 **실제로 보이는 시공 상태**로 진행 정도를 판단하세요(예: 트레이 3구간 중 2구간 포설 완료 → 약 66%).
- 영수증 사진이면 category=expense 로, 상호·금액을 summary 에 적으세요.
- 자재 납품/송장 사진이면 category=procurement 로 보세요.
- 안전 위험(개구부·추락·정리불량·PPE 미착용)이 보이면 category=issue 로 잡으세요.
- 글에 대상 작업이 적혀 있으면 **그 작업을 우선**하고, 사진은 상태 판정에만 쓰세요.
- 사진만으로 대상을 확신할 수 없으면 target_code 를 비우고 question 에 되물으세요.
- 출역 명부·인원 명단 사진이면 category=labor 로, proposed 에 {"headcount": 인원수, "company": "업체명"} 을
  넣으세요. 업체별로 나뉘어 있으면 **업체마다 별도 item** 으로 만드세요.
- 사람이 찍힌 작업 사진이면 **보이는 인원수를 세어** category=labor 로 넣되 confidence 를 낮추고
  question 에 "사진에 N명 보입니다. 맞나요?" 라고 되물으세요.
- 사진에서 **확인되지 않는 수치는 만들지 마세요.**
- **글이 비어 있어도 사진만으로 판독하세요.** 사진이 곧 보고 내용입니다. 빈 결과를 돌려주지 마세요.
P : '';

        return $this->body($text, $activities, $purchases, $today, $specs, $inspections).$kindLine.$photoRule;
    }

    /**
     * @param  array<int, array<string, mixed>>  $activities
     * @param  array<int, array<string, mixed>>  $purchases
     * @param  array<int, array<string, mixed>>  $inspections
     */
    private function body(string $text, array $activities, array $purchases, string $today, array $specs = [], array $inspections = []): string
    {
        // 검사·시험 후보 — "앵커 검사" 같은 말을 제출물 대장 번호로 잇게 한다.
        $inspList = collect($inspections)->take(150)->map(
            fn (array $s) => sprintf(
                '- [%s] %s — %s (계획일:%s, 상태:%s%s)',
                $s['seq'] ?? '', $s['section'] ?? '', $s['title'] ?? '',
                ($s['planned_on'] ?? '') !== '' ? $s['planned_on'] : '미등록',
                $s['status'] ?? '-',
                ($s['gate'] ?? '') === 'Y' ? ', ★정지조항' : '',
            ),
        )->implode("\n") ?: '(등록된 검사·시험 항목 없음)';

        $actList = collect($activities)->take(200)->map(
            fn (array $a) => sprintf(
                '- [%s] %s (상태:%s, 예정:%s~%s)',
                $a['code'] ?? '', $a['name'] ?? '', $a['status'] ?? '-', $a['start'] ?? '-', $a['end'] ?? '-',
            ),
        )->implode("\n") ?: '(등록된 공정 없음)';

        $poList = collect($purchases)->take(100)->map(
            fn (array $p) => sprintf('- [%s] %s (ETA:%s)', $p['po'] ?? '', $p['vendor'] ?? '', $p['eta'] ?? '-'),
        )->implode("\n") ?: '(등록된 발주 없음)';

        // 이미 확정된 사양 — 대화가 이것과 어긋나면 그것이 개입의 근거가 된다.
        $specList = collect($specs)->take(25)->map(function (array $s): string {
            $facts = collect($s['facts'] ?? [])->take(8)->map(fn ($f): string => '  · '.(is_string($f) ? $f : json_encode($f, JSON_UNESCAPED_UNICODE)))->implode("\n");

            return sprintf("- [%s] %s\n%s", $s['source'] ?? '', $s['title'] ?? '', $facts);
        })->implode("\n") ?: '(판독된 도면·문서 없음)';

        return <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장 설치현장(기계·전기·배관)의 공정관리자입니다.
아래는 현장에서 올라온 보고입니다. 형식이 없는 대화/메모일 수도 있고(카카오톡 통째로 붙여넣기),
**사진만 있고 글이 비어 있을 수도 있습니다.** 어느 쪽이든 **업무에 반영할 항목만** 뽑아
구조화하세요. JSON 만 반환합니다.

이 현장의 가장 중요한 보고는 **"오늘 몇 명이 나와서 무슨 일을 했나"** 입니다.
인원과 진행 상황이 보이면 반드시 빠뜨리지 말고 뽑으세요.

그리고 **지시·요청·승인·결정 사항을 절대 버리지 마세요.** 현장 대화의 절반은 공정표에 적을 수
없는 것들입니다("화기작업 승인 받으세요", "연장작업 신청합니다", "보안경 준비해주세요").
이런 것이 빠지면 다음날 준비가 무너집니다. request/approval/decision/todo 로 반드시 잡으세요.

[오늘 날짜] {$today}

[등록된 공정 목록 — 반드시 이 중에서 대상을 고르세요]
{$actList}

[등록된 발주 목록]
{$poList}

[등록된 검사·시험 항목 — 검사 일정은 반드시 이 중에서 고르세요]
{$inspList}

[이미 확정된 사양 — 도면·문서에서 읽은 것]
{$specList}

[현장 대화 원문]
{$text}

## 분류 규칙 (category)
- progress : 이미 한 일. 마감·완료·진행률. (과거형: "했다", "끝냈다", "20개 중 12개")
- plan     : 앞으로 할 일. 내일/다음주 계획, 신규 작업 추가, 인원 투입 예정.
- procurement : 자재 발주·납기·입고. ("화요일 도착", "발주 넣었다")
- labor    : **출역 인원 보고**. ("한빛전기 3명 나왔습니다", "오늘 전기 5명", "김씨 오늘 못 나옴")
             이 현장의 핵심 보고다. 업체별로 나뉘면 업체마다 별도 item 으로 만들 것.
- inspection : **검사·검측·입회·시험 일정**. ("다음 주 화요일 앵커 특별검사 입회", "수분시험 목요일 오전",
             "카운티 인스펙션 9/10 잡혔습니다") 제3자(검사기관·감리·발주처·관할기관)가 와서 보는 일정이
             여기다. 우리끼리 하는 작업 계획은 plan 이다. target_type="submittal", target_code 는
             위 [등록된 검사·시험 항목]의 번호를 그대로 쓸 것.
- expense  : 지출·영수증·구매 비용.
- issue    : 사고·안전·하자·민원·작업중단 사유.
- request  : **누가 누구에게 시킨 일·요청**. ("화기작업 승인 받으세요", "작업사진 송부 부탁드립니다",
             "스파이더 위치 알려주세요", "도면 확인 필요") — 원청·상급자 지시가 대부분이다.
- approval : **승인 요청과 그 결과**. ("연장작업 신청합니다" / "네 그러시죠") 승인 여부를 함께 적을 것.
- decision : **결정이 필요한 사항**. ("29,000불 냈다는데 네고할까요?", "업체 선정 확정 필요")
             금액·업체·일정처럼 사람이 판단해야 하는 것.
- todo     : 준비물·잡무. ("보안경 2-3 bag 준비", "용접가스 확보", "Pallet jack 필요")
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
7. **인원 보고인데 인원수가 없으면 버리지 말고 되물으세요.** ("플러밍팀 도착", "인원 충원됩니다")
   category=labor, proposed 는 {"company": "플러밍팀"} 만 넣고 confidence 를 40 이하로,
   question 에 "플러밍팀 몇 명 오셨나요?" 라고 적으세요. **인원 보고가 사라지는 것이 가장 나쁩니다.**
8. 같은 사람이 여러 줄에 걸쳐 한 가지를 말하면 **하나의 item 으로 합치세요.**
9. **대화가 [이미 확정된 사양]과 어긋나면 conflict 를 채우세요.** (예: 도면에 4인치인데 6인치로
   시공한다고 함, 자재 규격이 다름, 확정 일정과 다른 날짜)
   - conflict = {"with": "M-101 Rev.2", "expected": "3층 배관 4인치", "heard": "6인치로 시공"}
   - **어긋남을 발견해도 proposed 로 값을 바꾸지 마세요.** 도면이 바뀐 것인지 착오인지는
     사람만 압니다. 어긋났다는 사실과 근거만 적고, question 에 되물을 말을 쓰세요.
   - 확실하지 않으면 conflict 를 비우세요. 근거 없는 지적은 잔소리가 되어 아무도 안 읽게 됩니다.

## proposed(변경안) 작성법 — 확실한 것만
- 진행률: {"progress": 60}
- 상태:   {"status": "진행중"} 또는 {"status": "완료"}
- 일정:   {"planned_start": "2026-07-28", "planned_end": "2026-07-30"}
- 인원:   {"crew_size": 3}
- 출역 보고: {"headcount": 5, "company": "한빛전기", "trade": "전기"}
  ("한빛전기 5명 나왔습니다" 처럼 **실제 출역 인원 보고**일 때. 계획 투입 인원은 crew_size 를 쓰세요.)
- 지출:   {"amount": 340.50, "vendor": "Home Depot", "spent_on": "2026-07-28"}
- 지시·승인·결정·준비(request/approval/decision/todo):
  {"title": "화기작업 승인 신청", "requester": "Sungwook Kim", "assignee": "M.C.KANG",
   "due_on": "2026-07-28", "is_blocker": true, "approved": true}
  · title    : 할 일을 동사로 끝나게 한 줄 (예: "보안경 2~3 bag 구매")
  · requester: 시킨 사람 이름(대화에 있으면). assignee: 할 사람 이름(있으면)
  · due_on   : 언제까지. "내일" 이면 내일 날짜로 환산. 모르면 넣지 말 것
  · is_blocker: 이게 안 되면 다른 작업이 막히는 경우 true (예: 화기작업 승인 없으면 커팅 불가)
  · approved : approval 일 때만. 승인됐으면 true, 아직이면 false
- 발주ETA:{"eta": "2026-07-29"}
- 검사 일정(inspection): {"planned_on": "2026-09-08"}
  (필요하면 {"assignee": "검사기관/입회자", "notes": "오전 9시, 21일 전 통보 완료"} 를 함께.
   날짜는 **반드시 YYYY-MM-DD** 로 환산할 것 — "다음 주 화요일" 을 그대로 쓰면 버려집니다.
   검사가 끝났다는 보고면 {"status": "승인"} 처럼 상태를 함께 넣으세요.)
대화에서 확실히 읽히는 항목만 넣고, 나머지 키는 아예 넣지 마세요.

## 반환 항목 (items 배열)
- raw_text     : 이 항목의 근거가 된 원문 조각(그대로 인용)
- speaker      : 말한 사람 이름(대화에 있으면, 없으면 빈 문자열)
- category     : 위 분류 중 하나
- confidence   : 0~100 (대상·내용 확신도)
- summary      : 한국어 한 줄 요약(관리자가 읽을 문장)
- target_type  : "wbs" | "procurement" | "submittal" | "" (대상 없으면 빈 문자열)
- target_code  : 위 목록의 code/PO번호/검사항목번호 그대로. 확신 없으면 빈 문자열
- target_name  : 대상의 이름(표시용)
- occurred_on  : 이 내용이 해당하는 날짜(YYYY-MM-DD). 모르면 빈 문자열
- proposed     : 위 형식의 변경안 객체. 없으면 빈 객체 {}
- question     : 확인이 필요할 때 관리자에게 되물을 한 문장. 없으면 빈 문자열
- conflict     : 확정된 사양과 어긋날 때 {"with":"근거 문서","expected":"기록된 내용","heard":"대화 내용"}. 없으면 빈 객체 {}
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
                            'conflict' => ['type' => 'object'],
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
                    ."/v1beta/models/{$model}:generateContent";

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
                    throw new RuntimeException('Gemini API returned status '.$response->status().': '.$response->body());
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
                Log::warning("상황실 판독 모델 {$model} 실패, 폴백: ".$e->getMessage());
            }
        }

        throw new RuntimeException('상황실 판독 실패: '.($lastException?->getMessage() ?? 'unknown'));
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
