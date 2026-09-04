<?php

namespace App\Services;

use App\Services\Ocr\OcrEngine;
use App\Support\FinanceChartOfAccounts;
use App\Support\ImageDownscale;
use RuntimeException;

/**
 * 영수증 OCR 분석. 공통 OcrEngine(Gemini/Claude 전환)에 위임한다. 프롬프트·스키마·정규화만 소유.
 */
class GeminiReceiptAnalyzer
{
    public function __construct(private readonly OcrEngine $engine)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $imagePath, ?string $mimeType = null): array
    {
        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new RuntimeException('Receipt image file is not readable.');
        }

        return $this->analyzeBytes(
            (string) file_get_contents($imagePath),
            $mimeType ?: (mime_content_type($imagePath) ?: 'image/jpeg'),
        );
    }

    /**
     * 판독기에 넘기기 직전에 사진을 줄인다 — 원본은 그대로 보관하고, AI 에는 장변 1,600px 만 간다.
     *
     * 폰 사진 한 장(4000×3000, 3~8MB)을 그대로 보내면 요청 본문이 커져 전송에서 시간을
     * 다 쓰고 게이트웨이 시간 안에 못 돌아온다. 그러면 사람은 «서버 오류» 한 줄만 본다.
     * 판독 정확도는 줄여도 같다 — 비전 모델이 내부에서 어차피 그 크기로 다시 줄인다.
     * PDF 와 GD 가 못 읽는 형식(HEIC)은 원본 그대로 간다.
     *
     * @return array<string, mixed>
     */
    public function analyzeBytes(string $bytes, string $mimeType): array
    {
        $mimeType = $mimeType !== '' ? $mimeType : 'image/jpeg';

        if (! str_contains($mimeType, 'pdf')) {
            $shrunk = ImageDownscale::shrink($bytes, $mimeType);
            $bytes = $shrunk['data'];
            $mimeType = $shrunk['mime'];
        }

        $result = $this->engine->analyze(
            [[
                'data' => base64_encode($bytes),
                'mime_type' => $mimeType,
            ]],
            $this->prompt(),
            $this->schema(),
        );

        return $this->normalize($result['data'], $result['model']);
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Analyze this receipt image and extract the receipt fields in JSON format.
Do not guess missing values. If a value is completely missing, return an empty string or null as specified by the type.

Fields to extract:
- vendor_name: Name of the store, merchant, or supplier.
- amount: The total price/amount paid (decimal/float).
- subtotal: Pre-tax subtotal if printed (decimal). 0 if not visible.
- tax: Sales tax amount if printed (decimal). 0 if not visible. Never guess or compute it.
- tip: Tip/gratuity amount if printed or handwritten (decimal). 0 if none.
- date: Transaction date in YYYY-MM-DD format (if visible, otherwise empty string).
- accounting_account: Choose the best accounting account from the chart below. Return the exact code and name.
- category: Return the same exact value as accounting_account for ERP compatibility.
- description: Brief details of items bought.
- handwritten_notes: Any handwritten memo visible on the receipt, including job/site notes, purpose, initials, added totals, or short comments. Return an empty string if none is visible.
- site_hint: If the receipt (printed or handwritten) names a job site, project, building, or work area (e.g. "LGES", "HFF-02", "B동 현장"), return that name/code exactly as written. Empty string if none.
PROMPT
            . "\n\nChart of accounts:\n"
            . FinanceChartOfAccounts::promptList();
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vendor_name' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
                'subtotal' => ['type' => 'number'],
                'tax' => ['type' => 'number'],
                'tip' => ['type' => 'number'],
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD format or empty string'],
                'category' => ['type' => 'string'],
                'accounting_account' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'handwritten_notes' => ['type' => 'string'],
                'site_hint' => ['type' => 'string'],
            ],
            'required' => ['vendor_name', 'amount', 'date', 'category', 'accounting_account', 'description', 'handwritten_notes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, string $model): array
    {
        $context = implode(' ', [
            (string) ($data['vendor_name'] ?? ''),
            (string) ($data['category'] ?? ''),
            (string) ($data['description'] ?? ''),
            (string) ($data['handwritten_notes'] ?? ''),
        ]);
        $accountingAccount = FinanceChartOfAccounts::normalize($data['accounting_account'] ?? $data['category'] ?? '', $context);

        return [
            'vendor_name' => trim((string) ($data['vendor_name'] ?? '')),
            'amount' => is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : 0.0,
            // 세금·팁·소계 — 총액 하나만 뽑으면 세액 집계·식대 공제 판정이 안 된다.
            // 안 보이면 0 — 추측한 세액은 없는 세액보다 나쁘다.
            'subtotal' => is_numeric($data['subtotal'] ?? null) ? (float) $data['subtotal'] : 0.0,
            'tax' => is_numeric($data['tax'] ?? null) ? (float) $data['tax'] : 0.0,
            'tip' => is_numeric($data['tip'] ?? null) ? (float) $data['tip'] : 0.0,
            'date' => $this->normalizeDate($data['date'] ?? null),
            'category' => $accountingAccount,
            'accounting_account' => $accountingAccount,
            'description' => trim((string) ($data['description'] ?? '')),
            'handwritten_notes' => trim((string) ($data['handwritten_notes'] ?? '')),
            // 현장 힌트 — 수기 메모의 "HFF 현장" 한 줄이 경비의 현장 귀속이 된다.
            'site_hint' => trim((string) ($data['site_hint'] ?? '')),
            'model' => $model,
        ];
    }

    private function normalizeDate(mixed $date): string
    {
        if (! is_string($date) || trim($date) === '') {
            return '';
        }

        $date = trim($date);

        // Try standard Y-m-d format
        $parsed = date_create($date);
        if ($parsed !== false) {
            return $parsed->format('Y-m-d');
        }

        return '';
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
