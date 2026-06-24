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
Analyze the attached files: a rental contract document (PDF or image) and equipment condition photos.
Extract the following information from the rental contract and/or photos.
Provide JSON only. Do not guess missing values.

Fields to extract:
- equipment_type: Equipment category/type (e.g. "Excavator", "Forklift", "Boom Lift", "Generator", "Skid Steer", "Crane", "Compressor", "Other").
- model: Model of the equipment (e.g. "CAT 320", "Genie S-60").
- vendor: Rental vendor/company (e.g. "United Rentals", "Sunbelt", "Herc").
- rent_start: Date the rental starts (YYYY-MM-DD format). If not found, return empty string.
- rent_end: Date the rental ends (YYYY-MM-DD format). If not found, return empty string.
- daily_rate: Daily rental rate (integer).
- delivery_fee: Delivery/transportation fee (integer).
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
                'equipment_type' => ['type' => 'string'],
                'model' => ['type' => 'string'],
                'vendor' => ['type' => 'string'],
                'rent_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD format or empty string'],
                'rent_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD format or empty string'],
                'daily_rate' => ['type' => 'integer'],
                'delivery_fee' => ['type' => 'integer'],
            ],
            'required' => ['equipment_type', 'model', 'vendor', 'rent_start', 'rent_end', 'daily_rate', 'daily_rate', 'delivery_fee'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, string $model): array
    {
        return [
            'equipment_type' => trim((string) ($data['equipment_type'] ?? 'Other')),
            'model' => trim((string) ($data['model'] ?? '')),
            'vendor' => trim((string) ($data['vendor'] ?? '')),
            'rent_start' => $this->normalizeDate($data['rent_start'] ?? null),
            'rent_end' => $this->normalizeDate($data['rent_end'] ?? null),
            'daily_rate' => is_numeric($data['daily_rate'] ?? null) ? (int) $data['daily_rate'] : 0,
            'delivery_fee' => is_numeric($data['delivery_fee'] ?? null) ? (int) $data['delivery_fee'] : 0,
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
