<?php

namespace App\Services;

use App\Services\Ocr\OcrEngine;
use RuntimeException;

/**
 * 차량(렌탈 계약/사진) OCR 분석. 공통 OcrEngine(Gemini/Claude 전환)에 위임한다.
 */
class GeminiVehicleAnalyzer
{
    public function __construct(private readonly OcrEngine $engine)
    {
    }

    /**
     * Analyze rental contract and/or vehicle photos.
     *
     * @param array<array{path: string, mime_type?: string}> $files
     * @return array<string, mixed>
     */
    public function analyze(array $files): array
    {
        $images = [];

        foreach ($files as $file) {
            $path = $file['path'];
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("Upload file '{$path}' is not readable.");
            }

            $images[] = [
                'data' => base64_encode((string) file_get_contents($path)),
                'mime_type' => $file['mime_type'] ?? (mime_content_type($path) ?: 'image/jpeg'),
            ];
        }

        if (empty($images)) {
            throw new RuntimeException('No files provided for AI analysis.');
        }

        $result = $this->engine->analyze($images, $this->prompt(), $this->schema());

        return $this->normalize($result['data'], $result['model']);
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Analyze the attached files: a rental contract document (PDF or image) and vehicle condition photos (front, rear, left, right directions of a car).
Extract the following information from the rental contract and/or photos.
Provide JSON only. Do not guess missing values.

Fields to extract:
- plate_number: Vehicle license plate number/registration tag (if visible).
- model: Model of the vehicle (e.g. "Ford F-250", "Toyota Sienna", "Chevy Tahoe").
- vendor: Rental vendor/company (e.g. "Enterprise", "Hertz", "United Rentals", "Sunbelt").
- rent_start: Date the rental starts (YYYY-MM-DD format). If not found, return empty string.
- rent_end: Date the rental ends (YYYY-MM-DD format). If not found, return empty string.
- insurance_expiry: Date the vehicle insurance expires (YYYY-MM-DD format). If not found, return empty string.
- current_mileage: Current mileage/odometer reading (integer). Extract from contract or odometer photo if visible, otherwise return 0.
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
                'plate_number' => ['type' => 'string'],
                'model' => ['type' => 'string'],
                'vendor' => ['type' => 'string'],
                'rent_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD format or empty string'],
                'rent_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD format or empty string'],
                'insurance_expiry' => ['type' => 'string', 'description' => 'YYYY-MM-DD format or empty string'],
                'current_mileage' => ['type' => 'integer'],
            ],
            'required' => ['plate_number', 'model', 'vendor', 'rent_start', 'rent_end', 'insurance_expiry', 'current_mileage'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, string $model): array
    {
        return [
            'plate_number' => trim((string) ($data['plate_number'] ?? '')),
            'model' => trim((string) ($data['model'] ?? '')),
            'vendor' => trim((string) ($data['vendor'] ?? '')),
            'rent_start' => $this->normalizeDate($data['rent_start'] ?? null),
            'rent_end' => $this->normalizeDate($data['rent_end'] ?? null),
            'insurance_expiry' => $this->normalizeDate($data['insurance_expiry'] ?? null),
            'current_mileage' => is_numeric($data['current_mileage'] ?? null) ? (int) $data['current_mileage'] : 0,
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
