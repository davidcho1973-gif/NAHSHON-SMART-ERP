<?php

namespace App\Services\Documents;

use App\Models\IntelligentDocument;
use App\Support\AnthropicChat;
use Throwable;

/**
 * 문서 교차검증 — Gemini 가 읽은 것을 Claude 가 원본에서 다시 읽는다.
 *
 * 문서함에 올린 영수증 한 장이 곧바로 재무 승인대기와 장비 대장으로 흘러간다.
 * 금액을 한 자리 잘못 읽으면 잘못된 금액이 장부 앞까지 와서 사람 앞에 놓인다.
 * AI 한 대는 자기가 틀렸는지 모른다 — 스스로 "확신 있다" 고 답하기 때문이다.
 *
 * 그래서 회사가 다른 두 번째 눈을 붙인다. 같은 회사 모델은 같은 실수를 함께 하지만,
 * 다른 회사 모델은 대체로 다른 지점에서 틀린다.
 *
 * 설계에서 지킨 것:
 *
 *  1. <b>검증자는 1차 결과를 보지 않는다.</b> 남의 답을 보여주면 대체로 "맞다" 고
 *     동의해 버린다(끌려가기). 원본만 주고 독립적으로 읽게 한 뒤, 비교는 코드가 한다.
 *  2. <b>판정은 코드가 한다.</b> AI 에게 심판까지 맡기면 심판을 검증할 방법이 없다.
 *  3. <b>항상 부르지 않는다.</b> 큰 금액·장비·계약처럼 틀리면 손해가 큰 문서만 소집한다.
 *     모든 문서를 두 번 읽으면 요금과 시간만 두 배가 된다.
 *  4. <b>검증 실패가 분석을 막지 않는다.</b> 키가 없거나 호출이 죽어도 원래의 분석과
 *     모듈 연결은 그대로 살아야 한다. 검증은 관문이 아니라 추가 의견이다.
 *  5. <b>불일치는 지우지 않고 표시한다.</b> 어느 쪽이 맞는지 코드가 정하지 않는다 —
 *     사람에게 "여기 두 판독이 다릅니다" 라고 알리고 확인시킨다.
 */
class DocumentCrossCheck
{
    /** 검증자가 원본에서 다시 읽는 값들. 돈과 물건 — 틀리면 장부가 틀어지는 것들. */
    private const FIELDS = ['amount', 'flow', 'paid_on', 'payee', 'category_hint', 'equipment_involved'];

    public function __construct(private readonly AnthropicChat $chat) {}

    /**
     * 이 문서에 두 번째 눈이 필요한가.
     *
     * @param  array<string, mixed>  $data  1차 분석 결과(ai_payload)
     */
    public function shouldCheck(array $data, ?IntelligentDocument $document = null): bool
    {
        if (! (bool) config('document-intelligence.cross_check.enabled', true)) {
            return false;
        }

        if (! $this->chat->available()) {
            return false; // 키가 없으면 조용히 물러난다 — 분석은 원래대로 진행된다.
        }

        $money = (array) ($data['money'] ?? []);
        $amount = is_numeric($money['amount'] ?? null) ? (float) $money['amount'] : 0.0;
        $minimum = (float) config('document-intelligence.cross_check.min_amount', 1000);

        if ($amount >= $minimum && $amount > 0) {
            return true;
        }

        // 장비는 금액이 작아도 대장(자산·반납일)까지 흔든다.
        $involved = strtolower(trim((string) (((array) ($data['equipment'] ?? []))['involved'] ?? '')));
        if ($involved !== '' && $involved !== 'none') {
            return true;
        }

        $type = strtolower(trim((string) ($data['document_type'] ?? $document?->document_type ?? '')));

        return in_array($type, (array) config('document-intelligence.cross_check.always_types', []), true);
    }

    /**
     * 원본을 Claude 에게 독립적으로 다시 읽히고, 1차 결과와 코드로 대조한다.
     *
     * @param  array<string, mixed>  $data  1차 분석 결과
     * @param  array<string, mixed>  $analysis  분석 메타(engine/model)
     * @return array<string, mixed>|null  소집하지 않았으면 null
     */
    public function check(IntelligentDocument $document, array $data, string $bytes, array $analysis = []): ?array
    {
        if (! $this->shouldCheck($data, $document)) {
            return null;
        }

        $base = [
            'checked_at' => now()->toIso8601String(),
            'primary' => [
                'engine' => (string) ($analysis['engine'] ?? $document->ai_engine ?? ''),
                'model' => (string) ($analysis['model'] ?? $document->ai_model ?? ''),
            ],
            'verifier' => ['engine' => 'anthropic', 'model' => $this->chat->model()],
        ];

        try {
            $read = $this->reread($document, $bytes);
        } catch (Throwable $e) {
            report($e);

            // 검증이 죽어도 문서 분석은 살아 있어야 한다. "검증 못 했다" 로 남긴다.
            return $base + ['status' => 'failed', 'fields' => [], 'disagreements' => [], 'note' => $e->getMessage()];
        }

        if ($read === null) {
            return $base + ['status' => 'failed', 'fields' => [], 'disagreements' => [], 'note' => '검증자가 형식에 맞는 답을 주지 않았습니다.'];
        }

        return $base + $this->compare($data, $read);
    }

    // ── 검증자 호출 ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function reread(IntelligentDocument $document, string $bytes): ?array
    {
        $content = [];
        $maxBytes = (int) config('document-intelligence.native_max_bytes', 15728640);
        $mime = (string) ($document->mime_type ?: '');

        // 원본을 그대로 보여주는 게 가장 정확하다. 너무 크거나 원본을 붙일 수 없는
        // 형식이면 1차가 뽑아둔 글자로 대신한다(그래도 숫자 대조는 된다).
        if ($bytes !== '' && strlen($bytes) <= $maxBytes && $this->nativeMime($mime) !== null) {
            $content[] = [
                'type' => str_contains($mime, 'pdf') ? 'document' : 'image',
                'source' => ['type' => 'base64', 'media_type' => $this->nativeMime($mime), 'data' => base64_encode($bytes)],
            ];
        } else {
            $text = trim((string) $document->extracted_text);
            if ($text === '') {
                return null;
            }
            $content[] = ['type' => 'text', 'text' => "[문서에서 추출한 글자]\n".mb_substr($text, 0, 12000)];
        }

        $content[] = ['type' => 'text', 'text' => implode("\n", [
            '이 문서를 처음 보는 사람처럼 직접 읽고, 아래 값만 뽑아 JSON 으로만 답하세요.',
            '다른 분석 결과는 주어지지 않습니다. 추측하지 말고, 문서에서 확인할 수 없는 값은 null 로 두세요.',
            '',
            '{',
            '  "amount": 문서가 말하는 총 결제/청구 금액(숫자만, 통화기호·쉼표 없이) 또는 null,',
            '  "currency": "USD" 같은 통화 코드 또는 null,',
            '  "flow": 회사에서 돈이 나갔으면 "out", 들어왔으면 "in", 돈 문서가 아니면 "none",',
            '  "paid_on": 결제/발행일 "YYYY-MM-DD" 또는 null,',
            '  "payee": 돈을 받는 상대(업체명) 또는 null,',
            '  "category_hint": payroll|materials|equipment|lodging|fuel|meals|utilities|other 중 하나 또는 null,',
            '  "equipment_involved": 장비 임대면 "rental", 장비 구매면 "purchase", 아니면 "none",',
            '  "note": 판독하며 눈에 띈 점 한 문장(한국어)',
            '}',
        ])];

        return $this->chat->json([
            'max_tokens' => 1200,
            'system' => '당신은 회계 서류를 검증하는 감사자입니다. 문서에 적힌 그대로만 읽고, 없는 값을 지어내지 않습니다.',
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);
    }

    private function nativeMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));

        return match (true) {
            str_contains($mime, 'pdf') => 'application/pdf',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'image/jpeg',
            str_contains($mime, 'png') => 'image/png',
            str_contains($mime, 'webp') => 'image/webp',
            default => null,
        };
    }

    // ── 대조(판정은 코드가) ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function compare(array $data, array $read): array
    {
        $money = (array) ($data['money'] ?? []);
        $equipment = (array) ($data['equipment'] ?? []);

        $primary = [
            'amount' => is_numeric($money['amount'] ?? null) ? (float) $money['amount'] : null,
            'flow' => $this->word($money['flow'] ?? null),
            'paid_on' => $this->day($money['paid_on'] ?? null),
            'payee' => $this->name($money['payee'] ?? null),
            'category_hint' => $this->word($money['category_hint'] ?? null),
            'equipment_involved' => $this->word($equipment['involved'] ?? null),
        ];

        $verifier = [
            'amount' => is_numeric($read['amount'] ?? null) ? (float) $read['amount'] : null,
            'flow' => $this->word($read['flow'] ?? null),
            'paid_on' => $this->day($read['paid_on'] ?? null),
            'payee' => $this->name($read['payee'] ?? null),
            'category_hint' => $this->word($read['category_hint'] ?? null),
            'equipment_involved' => $this->word($read['equipment_involved'] ?? null),
        ];

        $fields = [];
        $disagreements = [];
        $compared = 0;

        foreach (self::FIELDS as $field) {
            $mine = $primary[$field];
            $theirs = $verifier[$field];

            // 한쪽이 못 읽은 값은 "다르다" 가 아니다 — 확인되지 않았을 뿐이다.
            if ($mine === null || $theirs === null) {
                $fields[$field] = ['primary' => $mine, 'verifier' => $theirs, 'match' => null];

                continue;
            }

            $compared++;
            $match = $field === 'amount'
                ? abs((float) $mine - (float) $theirs) < 0.01
                : ($field === 'payee' ? $this->namesLookSame((string) $mine, (string) $theirs) : $mine === $theirs);

            $fields[$field] = ['primary' => $mine, 'verifier' => $theirs, 'match' => $match];

            if (! $match) {
                $disagreements[] = $field;
            }
        }

        return [
            'status' => $compared === 0 ? 'unverified' : ($disagreements === [] ? 'agreed' : 'disagreed'),
            'fields' => $fields,
            'disagreements' => $disagreements,
            'note' => trim((string) ($read['note'] ?? '')),
        ];
    }

    private function word(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function day(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function name(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * 업체명은 글자 그대로 같기를 기대할 수 없다 — "SUNBELT RENTALS, INC." 와
     * "Sunbelt Rentals" 는 같은 회사다. 한쪽이 다른 쪽을 품으면 같다고 본다.
     */
    private function namesLookSame(string $a, string $b): bool
    {
        $normalize = static function (string $v): string {
            $v = strtolower($v);
            $v = (string) preg_replace('/\b(inc|llc|ltd|corp|corporation|co|company)\b/', '', $v);
            $v = (string) preg_replace('/[^a-z0-9가-힣]/u', '', $v);

            return $v;
        };

        $a = $normalize($a);
        $b = $normalize($b);

        if ($a === '' || $b === '') {
            return true;
        }

        return $a === $b || str_contains($a, $b) || str_contains($b, $a);
    }
}
