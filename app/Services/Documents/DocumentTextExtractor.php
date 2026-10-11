<?php

namespace App\Services\Documents;

use App\Support\PdfText;
use ZipArchive;

class DocumentTextExtractor
{
    public const EMAIL_ATTACHMENT_REVIEW_MARKER = '[EMAIL_ATTACHMENT_REVIEW_REQUIRED]';

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
            $extension === 'eml' || $mimeType === 'message/rfc822' => $this->fromEmail($bytes),
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
        // Raw MIME contains encoded attachments, not just body text. Decode only body parts.
        [$headers, $body] = $this->emailEntity($bytes);
        $importantHeaders = [];
        foreach (['subject', 'from', 'to', 'cc', 'date'] as $name) {
            if (isset($headers[$name])) {
                $importantHeaders[] = ucfirst($name).': '.$this->emailHeader($headers[$name]);
            }
        }
        $attachments = [];
        $partCount = 0;
        $content = $this->emailBody($headers, $body, $attachments, $partCount);

        return trim(implode("\n", $importantHeaders)."\n\n".$content['text']."\n\n".implode("\n", $attachments));
    }

    /** @return array{array<string, string>, string} */
    private function emailEntity(string $bytes): array
    {
        // Mail separators are CRLF/LF, not arbitrary bytes inside multibyte text.
        if (str_starts_with($bytes, "\r\n") || str_starts_with($bytes, "\n")) {
            return [[], substr($bytes, str_starts_with($bytes, "\r\n") ? 2 : 1)];
        }
        $sections = preg_split('/\r?\n\r?\n/', $bytes, 2);
        if (count($sections ?: []) !== 2 || strlen($sections[0]) > 65536) {
            throw new \RuntimeException('[INVALID_EMAIL] 이메일 MIME 헤더를 읽을 수 없습니다. 원본 파일을 확인해 주세요.');
        }
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $sections[0]);
        $headers = [];
        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if (preg_match('/^([a-z0-9-]+):[ \t]*(.*)$/i', $line, $match)) {
                $name = strtolower($match[1]);
                $headers[$name] = isset($headers[$name]) ? $headers[$name].', '.$match[2] : $match[2];
            }
        }

        return [$headers, $sections[1]];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $attachments
     * @return array{text: string, plain: bool}
     */
    private function emailBody(array $headers, string $body, array &$attachments, int &$partCount, int $depth = 0): array
    {
        if (++$partCount > 500 || $depth > 20) {
            throw new \RuntimeException('[EXTRACTION_LIMIT] 이메일 MIME 구조가 안전 한도를 넘습니다. 첨부 파일을 따로 검토해 주세요.');
        }
        $contentType = $headers['content-type'] ?? 'text/plain';
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        $dispositionHeader = $headers['content-disposition'] ?? '';
        $disposition = strtolower(trim(explode(';', $dispositionHeader, 2)[0]));
        $filename = $this->emailParameter($dispositionHeader, 'filename') ?: $this->emailParameter($contentType, 'name');
        $isBody = in_array($type, ['text/plain', 'text/html'], true);

        if ($disposition === 'attachment' || $filename !== null || (! $isBody && ! str_starts_with($type, 'multipart/'))) {
            // CID/inline placement does not prove an image is decorative; it may contain evidence.
            $inlineImage = str_starts_with($type, 'image/') && $disposition !== 'attachment'
                && ($disposition === 'inline' || isset($headers['content-id']));
            $label = self::EMAIL_ATTACHMENT_REVIEW_MARKER.($inlineImage ? ' [Email inline image not analyzed]' : '');
            $name = $filename !== null ? $this->emailHeader($filename) : '(unnamed '.$type.' part)';
            $attachments[] = $label.' '.$name.' ('.$type.'); contents not analyzed.';

            return ['text' => '', 'plain' => false];
        }

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $this->emailParameter($contentType, 'boundary');
            if ($boundary === null || $boundary === '' || strlen($boundary) > 200) {
                throw new \RuntimeException('[INVALID_EMAIL] 이메일 MIME 경계가 없거나 손상되었습니다.');
            }
            $results = [];
            foreach ($this->emailParts($body, $boundary) as $part) {
                [$partHeaders, $partBody] = $this->emailEntity($part);
                $result = $this->emailBody($partHeaders, $partBody, $attachments, $partCount, $depth + 1);
                if (trim($result['text']) !== '') {
                    $results[] = $result;
                }
            }
            // Alternatives describe the same message: prefer the readable plain text once.
            if ($type === 'multipart/alternative') {
                foreach ($results as $result) {
                    if ($result['plain']) {
                        return $result;
                    }
                }

                return $results === [] ? ['text' => '', 'plain' => false] : $results[array_key_last($results)];
            }

            return ['text' => implode("\n\n", array_column($results, 'text')), 'plain' => false];
        }

        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $decoded = match ($encoding) {
            'base64' => base64_decode(preg_replace('/[\r\n\t ]+/', '', $body), true),
            'quoted-printable' => quoted_printable_decode($body),
            '7bit', '8bit', 'binary', '' => $body,
            default => false,
        };
        if ($decoded === false) {
            throw new \RuntimeException('[INVALID_EMAIL] 이메일 본문 인코딩을 읽을 수 없습니다.');
        }
        $decoded = $this->emailCharset($decoded, $this->emailParameter($contentType, 'charset'));
        if ($type === 'text/html') {
            $decoded = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1\s*>~is', '', $decoded) ?? $decoded;
            $decoded = preg_replace('~<(?:br\b[^>]*|/(?:p|div|li|tr|h[1-6]|table)\s*)>~i', "\n", $decoded) ?? $decoded;
            $decoded = preg_replace('~</t[dh]\s*>~i', "\t", $decoded) ?? $decoded;
            $decoded = html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return ['text' => trim($decoded), 'plain' => $type === 'text/plain'];
    }

    /** @return \Generator<int, string> */
    private function emailParts(string $body, string $boundary): \Generator
    {
        preg_match_all('~^--'.preg_quote($boundary, '~').'(--)?[ \t]*\r?$~m', $body, $matches, PREG_OFFSET_CAPTURE);
        $start = null;
        foreach ($matches[0] as $index => [$delimiter, $offset]) {
            if ($start !== null) {
                yield substr($body, $start, $offset - $start);
            }
            if (($matches[1][$index][0] ?? '') === '--') {
                return;
            }
            $start = $offset + strlen($delimiter) + 1;
        }

        // Do not present a partial MIME message as a complete analysis.
        throw new \RuntimeException('[INVALID_EMAIL] 이메일 MIME 본문이 완결되지 않았습니다. 원본 파일을 다시 저장해 주세요.');
    }

    private function emailParameter(string $header, string $name): ?string
    {
        preg_match_all('/;\s*([a-z0-9_*.-]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/i', $header, $matches, PREG_SET_ORDER);
        $parameters = [];
        foreach ($matches as $match) {
            $value = trim($match[2]);
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = preg_replace('/\\\\(.)/s', '$1', substr($value, 1, -1));
            }
            $parameters[strtolower($match[1])] = $value;
        }
        $encoded = $parameters[$name.'*'] ?? null;
        if ($encoded === null && (isset($parameters[$name.'*0*']) || isset($parameters[$name.'*0']))) {
            $encoded = '';
            $charset = null;
            for ($i = 0; isset($parameters[$name.'*'.$i.'*']) || isset($parameters[$name.'*'.$i]); $i++) {
                $isEncoded = isset($parameters[$name.'*'.$i.'*']);
                $segment = $parameters[$name.'*'.$i.($isEncoded ? '*' : '')];
                if ($i === 0 && $isEncoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $segment, $match)) {
                    $charset = $match[1];
                    $segment = $match[2];
                }
                $encoded .= $isEncoded ? rawurldecode($segment) : $segment;
            }

            return $this->emailCharset($encoded, $charset);
        }
        if ($encoded !== null) {
            $charset = null;
            if (preg_match("/^([^']*)'[^']*'(.*)$/s", $encoded, $match)) {
                $charset = $match[1];
                $encoded = $match[2];
            }

            return $this->emailCharset(rawurldecode($encoded), $charset);
        }

        return $parameters[$name] ?? null;
    }

    private function emailCharset(string $text, ?string $charset): string
    {
        if ($charset === null || $charset === '') {
            return $text;
        }
        try {
            return mb_convert_encoding($text, 'UTF-8', $charset);
        } catch (\ValueError $e) {
            throw new \RuntimeException('[INVALID_EMAIL] 지원하지 않는 이메일 문자 인코딩입니다.', previous: $e);
        }
    }

    private function emailHeader(string $value): string
    {
        // Decode encoded words only: mb_decode_mimeheader treats raw UTF-8 as ASCII.
        $value = preg_replace('/(=\?[^?\s]+\?[bq]\?[^?]*\?=)[ \t]+(?==\?[^?\s]+\?[bq]\?)/i', '$1', $value) ?? $value;

        return preg_replace_callback('/=\?[^?\s]+\?[bq]\?[^?]*\?=/i',
            static fn (array $match): string => mb_decode_mimeheader($match[0]), $value) ?? $value;
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
