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

    /**
     * Analyze multiple equipment photos together and list all detected items.
     *
     * @param array<string> $imagePaths
     * @param array<string|null> $mimeTypes
     * @return array<string, mixed>
     */
    public function analyzeCollection(array $imagePaths, array $mimeTypes = []): array
    {
        $apiKey = (string) config('services.gemini.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        if (empty($imagePaths)) {
            return ['items' => []];
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

        $parts = [];
        foreach ($imagePaths as $i => $imagePath) {
            if (! is_file($imagePath) || ! is_readable($imagePath)) {
                throw new RuntimeException("Image file at {$imagePath} is not readable.");
            }
            $mime = $mimeTypes[$i] ?? null;
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mime ?: mime_content_type($imagePath) ?: 'image/jpeg',
                    'data' => base64_encode((string) file_get_contents($imagePath)),
                ]
            ];
        }

        $parts[] = [
            'text' => $this->promptCollection(),
        ];

        $lastException = null;

        foreach ($models as $model) {
            try {
                $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
                    . "/v1beta/models/{$model}:generateContent";

                $response = $this->http
                    ->timeout((int) config('services.gemini.timeout', 60))
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
                            'responseSchema' => $this->schemaCollection(),
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

                if (! is_array($decoded) || ! isset($decoded['items'])) {
                    throw new RuntimeException('Gemini equipment analysis was not valid JSON array of items.');
                }

                $normalizedItems = [];
                foreach ($decoded['items'] as $item) {
                    $normalizedItems[] = [
                        'equipment_type' => trim((string) ($item['equipment_type'] ?? 'Other (기타)')),
                        'model' => trim((string) ($item['model'] ?? '')),
                        'vendor' => trim((string) ($item['vendor'] ?? '')),
                        'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                        'is_bulk' => (bool) ($item['is_bulk'] ?? false),
                        'photo_index' => (int) ($item['photo_index'] ?? 0),
                    ];
                }

                return [
                    'items' => $normalizedItems,
                    'model_name' => $model,
                ];

            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("Gemini model {$model} failed for equipment collection scan, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed for equipment collection scan. Last error: ' . $lastException->getMessage());
    }

    private function promptCollection(): string
    {
        return <<<'PROMPT'
Analyze this collection of photos representing a set of tools, equipment, materials, or assets found in a construction site or container.
Look at all the images collectively and identify ALL the distinct tools, machines, materials, and consumables visible across the entire collection.

For each identified item, determine:
1. equipment_type: category of the equipment. Choose the best match from:
   - "Power Tool (전동공구)" (e.g. drills, grinders, saws, impact drivers, rotary hammers)
   - "Hand Tool (수공구)" (e.g. hammers, wrenches, screwdrivers, pliers, tape measures, levels)
   - "Pipes & Fittings (배관 자재)" (e.g. copper/PVC/steel pipes, elbows, couplings, tees, flanges, pipe clamps)
   - "Conduit & Electrical (전선관/전기 자재)" (e.g. electrical conduits, junction boxes, outlets, switches, fittings)
   - "Wires & Cables (전선/케이블)" (e.g. extension cords, reels of wire, cables, wire harnesses)
   - "Valves & Controls (밸브/계측기)" (e.g. ball valves, gate valves, pressure gauges, thermostats, meters)
   - "Fasteners & Anchors (체결류/피스)" (e.g. screws, drywall screws, bolts, nuts, washers, concrete anchors)
   - "Generator & Power (발전기/동력원)" (e.g. generators, battery packs, power chargers, transformers)
   - "Welding Machine (용접기)" (e.g. arc welders, MIG/TIG machines, gas torches, welding masks)
   - "Heavy Equipment (중장비)" (e.g. forklifts, boom lifts, scissor lifts, excavators, skid steers)
   - "Safety & PPE (안전 용품)" (e.g. safety vests, hard hats, safety glasses, gloves, harnesses, traffic cones)
   - "Other Materials (기타 자재/공구)" (e.g. toolboxes, ladders, tape, caulking, adhesives, cleaning supplies, paint, rope)
2. model: specific model name or number (e.g. "EU2200i", "DCD771", "LXT"). If no model is visible, provide a short 2-3 word description of the item.
3. vendor: brand, manufacturer, or company name (e.g. "Honda", "DeWalt", "Makita", "Bosch", "Caterpillar", "JLG").
4. quantity: the count of this specific tool/material visible in the images.
5. is_bulk: true if it is a bulk material, consumable, or loose items (e.g., boxes of screws, coils of wire, pipes, lumber, safety vests, gloves), false if it is a serialized asset/individual tool.
6. photo_index: the 0-based index of the image in the collection where this item is most clearly visible (0 for the first image, 1 for the second image, 2 for the third image, etc. corresponding to the order of images provided).

Do not duplicate items if they appear in multiple photos, just assign them to the photo where they are clearest.
Return JSON only matching the schema.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaCollection(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'List of detected tools and materials across all images',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'equipment_type' => [
                                'type' => 'string',
                                'description' => 'Category: Power Tool (전동공구), Hand Tool (수공구), Pipes & Fittings (배관 자재), Conduit & Electrical (전선관/전기 자재), Wires & Cables (전선/케이블), Valves & Controls (밸브/계측기), Fasteners & Anchors (체결류/피스), Generator & Power (발전기/동력원), Welding Machine (용접기), Heavy Equipment (중장비), Safety & PPE (안전 용품), Other Materials (기타 자재/공구)'
                            ],
                            'model' => [
                                'type' => 'string',
                                'description' => 'Model name/number or 2-3 word description'
                            ],
                            'vendor' => [
                                'type' => 'string',
                                'description' => 'Brand/manufacturer name'
                            ],
                            'quantity' => [
                                'type' => 'integer',
                                'description' => 'Estimated count of this item visible'
                            ],
                            'is_bulk' => [
                                'type' => 'boolean',
                                'description' => 'True for bulk/consumable items, false for individual serialized tools'
                            ],
                            'photo_index' => [
                                'type' => 'integer',
                                'description' => '0-based index of the photo showing this item'
                            ],
                        ],
                        'required' => ['equipment_type', 'model', 'vendor', 'quantity', 'is_bulk', 'photo_index'],
                    ]
                ]
            ],
            'required' => ['items'],
        ];
    }


    private function prompt(): string
    {
        return <<<'PROMPT'
Analyze this photo of a piece of equipment, machine, or tool (typically found in a construction site or tool container).
Identify the equipment type/category, the model name/number, and the manufacturer/brand.
Return JSON only. Do not guess wild values; if you cannot find the model or vendor, return an empty string.

Fields:
- equipment_type: The category of the equipment. Choose the best match from:
  "Power Tool (전동공구)", "Hand Tool (수공구)", "Pipes & Fittings (배관 자재)", "Conduit & Electrical (전선관/전기 자재)", "Wires & Cables (전선/케이블)", "Valves & Controls (밸브/계측기)", "Fasteners & Anchors (체결류/피스)", "Generator & Power (발전기/동력원)", "Welding Machine (용접기)", "Heavy Equipment (중장비)", "Safety & PPE (안전 용품)", "Other Materials (기타 자재/공구)".
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
                    'description' => 'Equipment category: Power Tool (전동공구), Hand Tool (수공구), Pipes & Fittings (배관 자재), Conduit & Electrical (전선관/전기 자재), Wires & Cables (전선/케이블), Valves & Controls (밸브/계측기), Fasteners & Anchors (체결류/피스), Generator & Power (발전기/동력원), Welding Machine (용접기), Heavy Equipment (중장비), Safety & PPE (안전 용품), Other Materials (기타 자재/공구)'
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
