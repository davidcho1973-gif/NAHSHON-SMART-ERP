<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiEquipmentPhotoAnalyzer
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * Analyze equipment photo.
     *
     * @param string $imagePath
     * @param string|null $mimeType
     * @return array<string, mixed>
     */
    public function analyze(string $imagePath, ?string $mimeType = null): array
    {
        $apiKey = (string) config('services.gemini.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new RuntimeException('Equipment image file is not readable.');
        }

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
                    throw new RuntimeException('Gemini returned no equipment analysis text.');
                }

                $decoded = json_decode($this->stripJsonFence($text), true);

                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini equipment analysis was not valid JSON.');
                }

                return $this->normalize($decoded, $model);

            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("Gemini model {$model} failed for equipment photo scan, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed for equipment photo scan. Last error: ' . $lastException->getMessage());
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Analyze this photo of a piece of equipment, machine, or tool (typically found in a construction site or tool container).
Identify the equipment type/category, the model name/number, and the manufacturer/brand.
Return JSON only. Do not guess wild values; if you cannot find the model or vendor, return an empty string.

Fields:
- equipment_type: The category of the equipment. Choose the best match from:
  "Generator (발전기)", "Welding Machine (용접기)", "Power Tool (전동공구)", "Hand Tool (수공구)", "Forklift (지게차)", "Boom Lift (고소작업대)", "Excavator (굴착기)", "Skid Steer (스키드로더)", "Compressor (콤프레샤)", "Other (기타)".
- model: The specific model name or number visible on the equipment (e.g. "EU2200i", "DCD771", "LXT"). If no model is visible, provide a short 2-3 word description of the item.
- vendor: The brand, manufacturer, or company name (e.g. "Honda", "DeWalt", "Makita", "Bosch", "Caterpillar", "JLG").
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
                'equipment_type' => [
                    'type' => 'string',
                    'description' => 'Equipment category. e.g. Generator (발전기), Power Tool (전동공구)'
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Specific model name/number'
                ],
                'vendor' => [
                    'type' => 'string',
                    'description' => 'Brand/manufacturer name'
                ],
            ],
            'required' => ['equipment_type', 'model', 'vendor'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, string $modelName): array
    {
        return [
            'equipment_type' => trim((string) ($data['equipment_type'] ?? 'Other (기타)')),
            'model' => trim((string) ($data['model'] ?? '')),
            'vendor' => trim((string) ($data['vendor'] ?? '')),
            'model_name' => $modelName,
        ];
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
