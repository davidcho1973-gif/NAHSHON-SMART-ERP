<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class GeminiReceiptAnalyzer
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $imagePath, ?string $mimeType = null): array
    {
        $apiKey = (string) config('services.gemini.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new RuntimeException('Receipt image file is not readable.');
        }

        $models = [
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.0-flash',
            'gemini-1.5-flash',
        ];

        $configuredModel = (string) config('services.gemini.model');
        if ($configuredModel !== '') {
            $models = array_filter($models, fn($m) => $m !== $configuredModel);
            array_unshift($models, $configuredModel);
        }

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
                            'parts' => [
                                [
                                    'inline_data' => [
                                        'mime_type' => $mimeType ?: mime_content_type($imagePath) ?: 'image/jpeg',
                                        'data' => base64_encode((string) file_get_contents($imagePath)),
                                    ],
                                ],
                                [
                                    'text' => $this->prompt(),
                                ],
                            ],
                        ]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => $this->schema(),
                        ],
                    ]);

                if ($response->failed()) {
                    throw new RuntimeException('Gemini API returned status ' . $response->status() . ': ' . $response->body());
                }

                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

                if (! is_string($text) || trim($text) === '') {
                    throw new RuntimeException('Gemini returned no receipt analysis text.');
                }

                $decoded = json_decode($this->stripJsonFence($text), true);

                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini receipt analysis was not valid JSON.');
                }

                return $this->normalize($decoded, $model);

            } catch (\Throwable $e) {
                $lastException = $e;
                \Illuminate\Support\Facades\Log::warning("Gemini model {$model} failed for receipt, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed for receipt. Last error: ' . $lastException->getMessage());
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
- category: Predict the category from: 'Computer & Software', 'Automobile Expense', 'Utilities', 'Travel & Lodging', 'Office Supplies', 'Meals & Entertainment', 'Other'.
- accounting_account: Choose the best accounting account from: '6120 Computer & Software', '6140 Automobile Expense', '6150 Utilities', '6160 Travel & Lodging', '6170 Office Supplies', '6180 Meals & Entertainment', '6999 Other Expense'.
- description: Brief details of items bought.
- handwritten_notes: Any handwritten memo visible on the receipt, including job/site notes, purpose, initials, added totals, or short comments. Return an empty string if none is visible.
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
        $category = $this->normalizeCategory($data['category'] ?? '');

        return [
            'vendor_name' => trim((string) ($data['vendor_name'] ?? '')),
            'amount' => is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : 0.0,
            'date' => $this->normalizeDate($data['date'] ?? null),
            'category' => $category,
            'accounting_account' => $this->normalizeAccountingAccount($data['accounting_account'] ?? '', $category),
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

    private function normalizeCategory(string $category): string
    {
        $allowed = [
            'Computer & Software',
            'Automobile Expense',
            'Utilities',
            'Travel & Lodging',
            'Office Supplies',
            'Meals & Entertainment',
            'Other',
        ];

        $category = trim($category);

        foreach ($allowed as $item) {
            if (strcasecmp($category, $item) === 0) {
                return $item;
            }
        }

        return 'Other';
    }

    private function normalizeAccountingAccount(mixed $account, string $category): string
    {
        $allowed = [
            'Computer & Software' => '6120 Computer & Software',
            'Automobile Expense' => '6140 Automobile Expense',
            'Utilities' => '6150 Utilities',
            'Travel & Lodging' => '6160 Travel & Lodging',
            'Office Supplies' => '6170 Office Supplies',
            'Meals & Entertainment' => '6180 Meals & Entertainment',
            'Other' => '6999 Other Expense',
        ];

        $account = trim((string) $account);

        foreach ($allowed as $label) {
            if (strcasecmp($account, $label) === 0) {
                return $label;
            }
        }

        foreach ($allowed as $categoryName => $label) {
            if (strcasecmp($account, $categoryName) === 0 || str_contains(strtolower($account), strtolower($categoryName))) {
                return $label;
            }
        }

        return $allowed[$category] ?? $allowed['Other'];
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
