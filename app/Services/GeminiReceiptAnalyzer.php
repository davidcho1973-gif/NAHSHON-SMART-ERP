<?php

namespace App\Services;

use App\Services\Ocr\OcrEngine;
use App\Support\FinanceChartOfAccounts;
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

        $result = $this->engine->analyze(
            [[
                'data' => base64_encode((string) file_get_contents($imagePath)),
                'mime_type' => $mimeType ?: (mime_content_type($imagePath) ?: 'image/jpeg'),
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
- date: Transaction date in YYYY-MM-DD format (if visible, otherwise empty string).
- accounting_account: Choose the best accounting account from the chart below. Return the exact code and name.
- category: Return the same exact value as accounting_account for ERP compatibility.
- description: Brief details of items bought.
- handwritten_notes: Any handwritten memo visible on the receipt, including job/site notes, purpose, initials, added totals, or short comments. Return an empty string if none is visible.
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
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD format or empty string'],
                'category' => ['type' => 'string'],
                'accounting_account' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'handwritten_notes' => ['type' => 'string'],
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
            'date' => $this->normalizeDate($data['date'] ?? null),
            'category' => $accountingAccount,
            'accounting_account' => $accountingAccount,
            'description' => trim((string) ($data['description'] ?? '')),
            'handwritten_notes' => trim((string) ($data['handwritten_notes'] ?? '')),
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
