<?php

namespace App\Services;

use App\Services\Ocr\OcrEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 배지/NFC OCR 분석. 공통 OcrEngine(Gemini/Claude 전환)에 위임한다.
 */
class GeminiBadgeAnalyzer
{
    public function __construct(private readonly OcrEngine $engine)
    {
    }

    /**
     * @param  string|null  $generalContractor  이 배지를 발급한 원청사 이름. 현장마다 다르므로
     *                                          호출하는 쪽(현장 → 원청사)에서 넘긴다. 코드에
     *                                          특정 원청사 이름을 적어 두면, 다른 원청사 현장의
     *                                          배지에서 엉뚱한 칸을 회사 이름으로 읽는다.
     * @return array<string, mixed>
     */
    public function analyze(string $imagePath, ?string $mimeType = null, ?string $generalContractor = null): array
    {
        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new RuntimeException('Badge image file is not readable.');
        }

        $result = $this->engine->analyze(
            [[
                'data' => base64_encode((string) file_get_contents($imagePath)),
                'mime_type' => $mimeType ?: (mime_content_type($imagePath) ?: 'image/jpeg'),
            ]],
            $this->prompt($generalContractor),
            $this->schema(),
        );

        return $this->normalize($result['data'], $result['model']);
    }

    private function prompt(?string $generalContractor = null): string
    {
        // 원청사 이름을 알면 «그 로고는 회사 이름이 아니다» 를 정확히 말해 줄 수 있다.
        // 모르면 이름 없이 같은 뜻을 말한다 — 어느 현장 배지든 맨 위 큰 로고는
        // 그 현장의 원청사이고, 사람이 속한 협력사는 그 아래에 인쇄된다.
        $gc = trim((string) $generalContractor);
        $logo = $gc !== '' ? "the {$gc} logo" : 'the general contractor logo at the top of the badge';
        $under = $gc !== '' ? "under the {$gc} logo" : 'under that logo';

        return <<<PROMPT
Analyze this construction site access badge photo and extract only visible information.
Return JSON only. Do not guess missing values.

Badge-specific rules:
- Do not use {$logo} as the company name. That is the general contractor who issued
  the badge, not the company the person works for.
- company_name is the contractor/company text printed directly {$under}. It is often
  set in a different colour (commonly red) from the rest of the badge.
- last_name is printed below that company text.
- first_name is printed below the last name.
- role is printed below the first name.
- issued_on is the date printed next to "ISSUED ON" under the portrait photo.
- If the badge shows another visible printed badge code, return it as printed_badge_number.
- Never return or invent the NFC chip UID. NFC IDs are created only from the hardware reader UID.

Fields:
- company_name: the contractor/company name printed {$under}.
- first_name: given name.
- last_name: family/surname.
- full_name: complete person name as printed.
- role: job title, trade, position, or worker role.
- issued_on: badge issue date in YYYY-MM-DD if visible, otherwise null.
- printed_badge_number: visible printed badge number or printed employee/badge code if present.
- confidence: 0-100 confidence score for the extraction.
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
                'company_name' => ['type' => 'string'],
                'first_name' => ['type' => 'string'],
                'last_name' => ['type' => 'string'],
                'full_name' => ['type' => 'string'],
                'role' => ['type' => 'string'],
                'issued_on' => ['type' => 'string', 'description' => 'YYYY-MM-DD or empty string'],
                'printed_badge_number' => ['type' => 'string'],
                'confidence' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, string $model): array
    {
        $firstName = $this->clean($data['first_name'] ?? null);
        $lastName = $this->clean($data['last_name'] ?? null);
        $fullName = $this->clean($data['full_name'] ?? null);

        if ($fullName === null) {
            $fullName = trim(implode(' ', array_filter([$firstName, $lastName]))) ?: null;
        }

        return [
            'company_name' => $this->clean($data['company_name'] ?? null),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'role' => $this->clean($data['role'] ?? null),
            'issued_on' => $this->normalizeDate($data['issued_on'] ?? null),
            'printed_badge_number' => $this->clean($data['printed_badge_number'] ?? $data['badge_number'] ?? null),
            'confidence' => is_numeric($data['confidence'] ?? null) ? max(0, min(100, (int) $data['confidence'])) : null,
            'model' => $model,
            'raw' => $data,
        ];
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || Str::lower($value) === 'null') {
            return null;
        }

        return $value;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }

        return trim($text);
    }
}
