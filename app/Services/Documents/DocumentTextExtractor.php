<?php

namespace App\Services\Documents;

use App\Support\PdfText;
use ZipArchive;

class DocumentTextExtractor
{
    public function extract(string $bytes, ?string $extension, ?string $mimeType = null): ?string
    {
        $extension = strtolower((string) $extension);
        $mimeType = strtolower((string) $mimeType);

        $text = match (true) {
            $extension === 'pdf' || str_contains($mimeType, 'pdf') => PdfText::extract($bytes),
            in_array($extension, ['txt', 'csv', 'json', 'xml'], true) => $bytes,
            $extension === 'rtf' => $this->fromRtf($bytes),
            $extension === 'docx' => $this->fromOfficeArchive($bytes, ['word/document.xml']),
            $extension === 'xlsx' => $this->fromSpreadsheetArchive($bytes),
            $extension === 'eml' => $this->fromEmail($bytes),
            default => null,
        };

        if (! is_string($text)) {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        return mb_strlen($text) >= 20 ? $text : null;
    }

    private function fromRtf(string $bytes): string
    {
        $text = preg_replace('/\\\\[a-z]+-?\d* ?/i', ' ', $bytes) ?? $bytes;
        $text = str_replace(['{', '}', '\\'], ' ', $text);

        return $text;
    }

    private function fromEmail(string $bytes): string
    {
        [$headers, $body] = array_pad(preg_split("/\R\R/", $bytes, 2) ?: [], 2, '');
        $importantHeaders = collect(preg_split('/\R/', $headers) ?: [])
            ->filter(fn (string $line): bool => preg_match('/^(subject|from|to|cc|date):/i', $line) === 1)
            ->implode("\n");

        return trim($importantHeaders."\n\n".strip_tags($body));
    }

    /** @param array<int, string> $entries */
    private function fromOfficeArchive(string $bytes, array $entries): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        return $this->withArchive($bytes, function (ZipArchive $zip) use ($entries): string {
            $parts = [];
            foreach ($entries as $entry) {
                $xml = $zip->getFromName($entry);
                if (is_string($xml)) {
                    $xml = str_replace(['</w:p>', '</w:tr>', '</a:p>'], "\n", $xml);
                    $parts[] = strip_tags($xml);
                }
            }

            return implode("\n", $parts);
        });
    }

    private function fromSpreadsheetArchive(string $bytes): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        return $this->withArchive($bytes, function (ZipArchive $zip): string {
            $parts = [];
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            if (is_string($shared)) {
                $shared = str_replace(['</si>', '</row>'], "\n", $shared);
                $parts[] = strip_tags($shared);
            }

            for ($i = 1; $i <= 50; $i++) {
                $sheet = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if (! is_string($sheet)) {
                    continue;
                }
                $sheet = str_replace(['</c>', '</row>'], ["\t", "\n"], $sheet);
                $parts[] = strip_tags($sheet);
            }

            return implode("\n", $parts);
        });
    }

    private function withArchive(string $bytes, callable $callback): ?string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'nahshon-doc-');
        if ($tempPath === false) {
            return null;
        }

        try {
            file_put_contents($tempPath, $bytes);
            $zip = new ZipArchive;
            if ($zip->open($tempPath) !== true) {
                return null;
            }

            try {
                $result = $callback($zip);
            } finally {
                $zip->close();
            }

            return is_string($result) ? $result : null;
        } finally {
            @unlink($tempPath);
        }
    }
}
