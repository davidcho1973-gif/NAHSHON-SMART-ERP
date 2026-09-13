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

        // 어떤 추출기가 됐든 깨진 바이트는 여기서 걷어낸다 — json_encode·Postgres 공통 방어선.
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        // Entity decoding must not reintroduce forbidden PostgreSQL NUL/controls.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        // Byte-mode \R treats 0x85 inside a Korean UTF-8 character as a newline.
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
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
        // Mail separators are CRLF/LF, not arbitrary bytes inside multibyte text.
        [$headers, $body] = array_pad(preg_split('/\r?\n\r?\n/', $bytes, 2) ?: [], 2, '');
        $importantHeaders = collect(preg_split('/\r?\n/', $headers) ?: [])
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
            $populatedRows = 0;
            $strings = [];
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            if (is_string($shared)) {
                foreach ($this->xml($shared)->xpath('//*[local-name()="si"]') ?: [] as $item) {
                    $strings[] = implode('', array_map(static fn ($t) => (string) $t, $item->xpath('.//*[local-name()="t"]') ?: []));
                }
            }

            // Deleted/renamed sheets leave gaps; shared-string IDs are not cell values.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! preg_match('~^xl/worksheets/[^/]+\.xml$~', (string) $name)) {
                    continue;
                }
                $parts[] = '[Worksheet '.$name.'; formulas use saved values, not recalculated]';
                $sheet = $this->xml((string) $zip->getFromIndex($i));
                foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
                    $cells = [];
                    foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                        $value = (string) (($cell->xpath('./*[local-name()="v"]') ?: [])[0] ?? '');
                        $type = (string) $cell['t'];
                        if ($type === 's') {
                            if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                                throw new \RuntimeException('[INVALID_WORKBOOK] Excel 문자열 참조가 손상되었습니다. Excel에서 다시 저장해 주세요.');
                            }
                            $value = $strings[(int) $value];
                        } elseif ($type === 'inlineStr') {
                            $value = implode('', array_map(static fn ($t) => (string) $t, $cell->xpath('.//*[local-name()="t"]') ?: []));
                        }
                        $formula = (string) (($cell->xpath('./*[local-name()="f"]') ?: [])[0] ?? '');
                        if ($formula !== '' && $value === '') {
                            $value = '[formula without saved result: '.$formula.']';
                        }
                        if ($value !== '') {
                            $cells[] = (string) $cell['r'].'='.$value;
                        }
                    }
                    if ($cells !== []) {
                        $parts[] = implode("\t", $cells);
                        $populatedRows++;
                    }
                }
            }

            return $populatedRows > 0 ? implode("\n", $parts) : '';
        });
    }

    private function withArchive(string $bytes, callable $callback): ?string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'erp-doc-');
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
                // Check expanded XML sizes before ZIP extraction/DOM allocation.
                $total = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->statIndex($i);
                    if (str_ends_with(strtolower((string) ($entry['name'] ?? '')), '.xml')) {
                        $size = (int) ($entry['size'] ?? 0);
                        $total += $size;
                        if ($size > 8 * 1048576 || $total > 32 * 1048576) {
                            throw new \RuntimeException('[EXTRACTION_LIMIT] Office 압축 해제 본문이 안전 한도를 넘습니다. 시트 또는 문서를 나눠 주세요.');
                        }
                    }
                }
                $result = $callback($zip);
            } finally {
                $zip->close();
            }

            return is_string($result) ? $result : null;
        } finally {
            @unlink($tempPath);
        }
    }

    private function xml(string $xml): \SimpleXMLElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException('[INVALID_WORKBOOK] 외부 엔터티를 포함한 Excel XML은 분석하지 않습니다.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($parsed === false) {
                throw new \RuntimeException('[INVALID_WORKBOOK] Excel XML을 읽을 수 없습니다. 파일을 다시 저장해 주세요.');
            }

            return $parsed;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
