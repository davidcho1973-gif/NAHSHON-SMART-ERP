<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class GeminiBadgeAnalyzer
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
            throw new RuntimeException('Badge image file is not readable.');
        }

        $models = [
            'gemini-3.5-pro',
            'gemini-2.5-pro',
            'gemini-2.0-pro',
            'gemini-1.5-pro',
            'gemini-3.5-flash',
            'gemini-2.5-flash',
            'gemini-2.0-flash',
            'gemini-1.5-flash'
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
                    throw new RuntimeException('Gemini returned no badge analysis text.');
                }

                $decoded = json_decode($this->stripJsonFence($text), true);

                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini badge analysis was not valid JSON.');
                }

                return $this->normalize($decoded, $model);

            } catch (\Throwable $e) {
                $lastException = $e;
                \Illuminate\Support\Facades\Log::warning("Gemini model {$model} failed, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed. Last error: ' . $lastException->getMessage());
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Analyze this HOFFMAN site access badge photo and extract only visible information.
Return JSON only. Do not guess missing values.

Badge-specific rules:
- Do not use the HOFFMAN logo as the company name.
- company_name is the red contractor/company text directly under the HOFFMAN logo.
- last_name is printed below the red company text.
- first_name is printed below the last name.
- role is printed below the first name.
- issued_on is the date printed next to "ISSUED ON" under the portrait photo.
- If the badge shows another visible printed badge code, return it as printed_badge_number.
- Never return or invent the NFC chip UID. NFC IDs are created only from the hardware reader UID.

Fields:
- company_name: red contractor/company name under HOFFMAN.
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
