<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiEquipmentAnalyzer
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * Analyze rental contract and/or equipment photos.
     *
     * @param array<array{path: string, mime_type?: string}> $files
     * @return array<string, mixed>
     */
    public function analyze(array $files): array
    {
        $apiKey = (string) config('services.gemini.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $parts = [];

        foreach ($files as $file) {
            $path = $file['path'];
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("Upload file '{$path}' is not readable.");
            }

            $mimeType = $file['mime_type'] ?? mime_content_type($path) ?: 'image/jpeg';
            $data = base64_encode((string) file_get_contents($path));

            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => $data,
                ],
            ];
        }

        if (empty($parts)) {
            throw new RuntimeException('No files provided for AI analysis.');
        }

        $parts[] = [
            'text' => $this->prompt(),
        ];

        $models = [
            'gemini-2.5-flash',
            'gemini-3.5-flash',
            'gemini-2.0-flash',
            'gemini-1.5-flash',
            'gemini-2.5-pro',
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
                    ->timeout((int) config('services.gemini.timeout', 45))
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($endpoint, [
                        'contents' => [[
                            'parts' => $parts,
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
                    throw new RuntimeException('Gemini returned no equipment analysis text.');
                }

                $decoded = json_decode($this->stripJsonFence($text), true);

                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini equipment analysis was not valid JSON.');
                }

                return $this->normalize($decoded, $model);

            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("Gemini model {$model} failed for equipment scan, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed for equipment scan. Last error: ' . $lastException->getMessage());
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
You are analyzing a US construction equipment/material rental agreement, lease contract, purchase agreement, sales invoice, or purchase receipt document (e.g. rental from United Rentals/Sunbelt/Herc, modular lease from WillScot, or purchase/sales invoices from Home Depot, Lowe's, CAT, or other dealers), plus optional equipment condition photos. Extract EVERY field you can read. Return JSON only.
Do NOT invent values — use an empty string / 0 / empty array when a field is absent.

Read carefully: these documents contain far more than type/model/vendor. Capture the agreement identifiers, both parties, the ship-to/delivery site, the full pricing breakdown (recurring rate or purchase rate, delivery fee, totals with and without tax), quantity of units, specs, and any terms.

If the document is a purchase receipt, sales agreement, or invoice rather than a rental contract:
- Treat the purchase/invoice date as 'rent_start', and leave 'rent_end' as "".
- Treat the purchase unit cost or total asset price as 'rate_amount' or 'daily_rate'.
- Treat the seller/dealer as 'vendor'.

Top-level fields:
- equipment_type: category (e.g. "Storage Container", "Boom Lift", "Excavator", "Generator", "Modular Office", "Power Tool (전동공구)", "Hand Tool (수공구)", "Safety & PPE (안전 용품)", "Other Materials (기타 자재/공구)", or "Other").
- model: model/description (e.g. "40' CONTAINER", "Genie S-60", "CAT 320", "Hilti TE 70").
- vendor: rental/seller company short name (e.g. "WillScot", "United Rentals", "Home Depot", "Lowe's").
- quantity: number of identical units on this order (integer, default 1).
- rent_start: start/estimated delivery date / purchase date (YYYY-MM-DD) or "".
- rent_end: end date (YYYY-MM-DD) or "" if open/month-to-month or purchase.
- rate_amount: the recurring rate or purchase unit price (number).
- rate_period: the billing period (e.g. "day", "week", "month", "once", "one-time") or "".
- daily_rate: best daily-equivalent rate or purchase unit cost (integer).
- delivery_fee: delivery/transport charge (integer).
- return_fee: estimated return/pickup charge (integer, default 0).

details: a nested object with the full contract metadata:
- quote_no, document_type (e.g., "Rental Contract", "Purchase Invoice", "Sales Receipt", "Proposal"), revision, quote_date (YYYY-MM-DD), expiration_date (YYYY-MM-DD).
- account_no: customer account number.
- ship_to_address: full delivery/job-site address.
- delivery_date (YYYY-MM-DD).
- sales_rep: { name, phone, email }.
- lessor: { name, address, phone } (the rental company or seller).
- lessee: { name, address, contact_name, contact_phone, contact_email } (the customer or buyer).
- specs: free-text dimensions/volume/capacity/serial numbers.
- scope_of_work: what is included.
- addons: array of { name, quantity, price }.
- pricing: { recurring_per_cycle, recurring_with_tax, delivery, return, total, total_with_tax } (numbers).
- terms: { billing_cycle, payment_terms, min_lease_term, late_interest, lease_type, taxes_responsibility } (strings).
- po_number.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $str = ['type' => 'string'];
        $num = ['type' => 'number'];

        return [
            'type' => 'object',
            'properties' => [
                'equipment_type' => $str,
                'model' => $str,
                'vendor' => $str,
                'quantity' => ['type' => 'integer'],
                'rent_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD or empty string'],
                'rent_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD or empty string'],
                'rate_amount' => $num,
                'rate_period' => $str,
                'daily_rate' => ['type' => 'integer'],
                'delivery_fee' => ['type' => 'integer'],
                'return_fee' => ['type' => 'integer'],
                'details' => [
                    'type' => 'object',
                    'properties' => [
                        'quote_no' => $str,
                        'document_type' => $str,
                        'revision' => $str,
                        'quote_date' => $str,
                        'expiration_date' => $str,
                        'account_no' => $str,
                        'ship_to_address' => $str,
                        'delivery_date' => $str,
                        'sales_rep' => [
                            'type' => 'object',
                            'properties' => ['name' => $str, 'phone' => $str, 'email' => $str],
                        ],
                        'lessor' => [
                            'type' => 'object',
                            'properties' => ['name' => $str, 'address' => $str, 'phone' => $str],
                        ],
                        'lessee' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => $str, 'address' => $str,
                                'contact_name' => $str, 'contact_phone' => $str, 'contact_email' => $str,
                            ],
                        ],
                        'specs' => $str,
                        'scope_of_work' => $str,
                        'addons' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => ['name' => $str, 'quantity' => ['type' => 'integer'], 'price' => $num],
                            ],
                        ],
                        'pricing' => [
                            'type' => 'object',
                            'properties' => [
                                'recurring_per_cycle' => $num, 'recurring_with_tax' => $num,
                                'delivery' => $num, 'return' => $num,
                                'total' => $num, 'total_with_tax' => $num,
                            ],
                        ],
                        'terms' => [
                            'type' => 'object',
                            'properties' => [
                                'billing_cycle' => $str, 'payment_terms' => $str, 'min_lease_term' => $str,
                                'late_interest' => $str, 'lease_type' => $str, 'taxes_responsibility' => $str,
                            ],
                        ],
                        'po_number' => $str,
                    ],
                ],
            ],
            'required' => ['equipment_type', 'model', 'vendor', 'quantity', 'rent_start', 'rent_end', 'daily_rate', 'delivery_fee'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, string $model): array
    {
        $rateAmount = is_numeric($data['rate_amount'] ?? null) ? (float) $data['rate_amount'] : 0.0;
        $dailyRate = is_numeric($data['daily_rate'] ?? null) ? (int) $data['daily_rate'] : 0;

        // If the AI gave a recurring rate but no daily figure, surface the recurring amount
        // so the form no longer shows 0 (a container is priced per 28-day cycle, not per day).
        if ($dailyRate === 0 && $rateAmount > 0) {
            $dailyRate = (int) round($rateAmount);
        }

        $quantity = is_numeric($data['quantity'] ?? null) ? max(1, (int) $data['quantity']) : 1;

        return [
            'equipment_type' => trim((string) ($data['equipment_type'] ?? 'Other')),
            'model' => trim((string) ($data['model'] ?? '')),
            'vendor' => trim((string) ($data['vendor'] ?? '')),
            'quantity' => $quantity,
            'rent_start' => $this->normalizeDate($data['rent_start'] ?? null),
            'rent_end' => $this->normalizeDate($data['rent_end'] ?? null),
            'rate_amount' => $rateAmount,
            'rate_period' => trim((string) ($data['rate_period'] ?? '')),
            'daily_rate' => $dailyRate,
            'delivery_fee' => is_numeric($data['delivery_fee'] ?? null) ? (int) $data['delivery_fee'] : 0,
            'return_fee' => is_numeric($data['return_fee'] ?? null) ? (int) $data['return_fee'] : 0,
            'details' => is_array($data['details'] ?? null) ? $data['details'] : [],
            'model_name' => $model,
        ];
    }

    private function normalizeDate(mixed $date): string
    {
        if (! is_string($date) || trim($date) === '') {
            return '';
        }

        $date = trim($date);
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
