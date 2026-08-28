<?php

namespace App\Support;

use Throwable;
use ZipArchive;

/**
 * Word(.docx)·Excel(.xlsx) 본문 텍스트를 서버에서 직접 추출한다.
 *
 * 왜: AI 문서 분석기는 PDF/이미지만 읽는다. 하지만 현장에서 올리는 JSA·PTP·견적서 상당수가
 * Word/Excel(OOXML) 이다. OOXML 은 zip 컨테이너 안의 XML 이므로 외부 라이브러리 없이
 * ZipArchive 로 본문 텍스트를 뽑아, 텍스트 PDF 와 똑같이 프롬프트에 정본으로 넣어 분석한다.
 * (구형 이진 .doc/.xls 는 지원하지 않는다 — PDF 로 변환해 올리도록 안내.)
 */
final class OfficeText
{
    /**
     * 확장자/마임으로 OOXML 여부를 판별해 텍스트를 뽑는다. 대상 아님/실패면 null.
     */
    public static function extract(string $bytes, string $mime = '', string $filename = ''): ?string
    {
        $name = strtolower($filename);
        $mime = strtolower($mime);

        $isWord = str_contains($mime, 'wordprocessingml') || str_ends_with($name, '.docx');
        $isExcel = str_contains($mime, 'spreadsheetml') || str_ends_with($name, '.xlsx');
        $isPpt = str_contains($mime, 'presentationml') || str_ends_with($name, '.pptx');

        if ($isWord) {
            return self::docx($bytes);
        }
        if ($isExcel) {
            return self::xlsx($bytes);
        }
        if ($isPpt) {
            return self::pptx($bytes);
        }

        return null;
    }

    public static function isSupported(string $mime = '', string $filename = ''): bool
    {
        $name = strtolower($filename);
        $mime = strtolower($mime);

        return str_contains($mime, 'wordprocessingml') || str_ends_with($name, '.docx')
            || str_contains($mime, 'spreadsheetml') || str_ends_with($name, '.xlsx')
            || str_contains($mime, 'presentationml') || str_ends_with($name, '.pptx');
    }

    private static function docx(string $bytes): ?string
    {
        $xml = self::readZipEntry($bytes, 'word/document.xml');
        if ($xml === null) {
            return null;
        }

        // 문단/줄바꿈/탭을 실제 개행으로 바꾼 뒤 태그를 제거 — 본문 텍스트만 남긴다.
        $xml = preg_replace('/<w:p\b[^>]*>/', "\n", $xml) ?? $xml;
        $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>', '<w:br />'], ["\n", "\t", "\n", "\n"], $xml);

        return self::cleanup(strip_tags($xml));
    }

    private static function xlsx(string $bytes): ?string
    {
        // sharedStrings 에 시트의 문자열 값이 모여 있다(라벨·항목명 등 분류에 필요한 텍스트).
        $shared = self::readZipEntry($bytes, 'xl/sharedStrings.xml');
        $texts = [];
        if ($shared !== null && preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $shared, $m)) {
            $texts = $m[1];
        }

        // 첫 시트의 인라인 문자열도 보강(sharedStrings 를 안 쓰는 경우).
        $sheet = self::readZipEntry($bytes, 'xl/worksheets/sheet1.xml');
        if ($sheet !== null && preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $sheet, $m2)) {
            $texts = array_merge($texts, $m2[1]);
        }

        if ($texts === []) {
            return null;
        }

        return self::cleanup(implode("\n", $texts));
    }

    private static function pptx(string $bytes): ?string
    {
        // 모든 슬라이드(ppt/slides/slideN.xml)의 텍스트런 <a:t> 를 순서대로 모은다.
        $slides = self::readZipEntriesMatching($bytes, '#^ppt/slides/slide\d+\.xml$#');
        if ($slides === []) {
            return null;
        }

        // 슬라이드 번호 순으로 정렬(slide2 가 slide10 보다 앞).
        uksort($slides, static function (string $a, string $b): int {
            preg_match('/(\d+)/', $a, $ma);
            preg_match('/(\d+)/', $b, $mb);

            return ((int) ($ma[1] ?? 0)) <=> ((int) ($mb[1] ?? 0));
        });

        $texts = [];
        foreach ($slides as $xml) {
            if (preg_match_all('/<a:t[^>]*>(.*?)<\/a:t>/s', $xml, $m)) {
                $texts = array_merge($texts, $m[1]);
            }
        }

        return $texts === [] ? null : self::cleanup(implode("\n", $texts));
    }

    /**
     * zip 안에서 정규식에 맞는 엔트리들을 [경로 => 내용] 으로 읽는다.
     *
     * @return array<string, string>
     */
    private static function readZipEntriesMatching(string $bytes, string $regex): array
    {
        if ($bytes === '') {
            return [];
        }

        $viaExt = self::readWithZipArchive($bytes, fn (string $n): bool => (bool) preg_match($regex, $n));
        if ($viaExt !== []) {
            return $viaExt;
        }

        // ext-zip 이 없거나(서버리스 런타임) 임시파일을 못 만드는 환경 폴백 — 순수 PHP 파서.
        return self::readWithPurePhp($bytes, fn (string $n): bool => (bool) preg_match($regex, $n));
    }

    private static function readZipEntry(string $bytes, string $entry): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $viaExt = self::readWithZipArchive($bytes, fn (string $n): bool => $n === $entry);
        if (isset($viaExt[$entry])) {
            return $viaExt[$entry];
        }

        $pure = self::readWithPurePhp($bytes, fn (string $n): bool => $n === $entry);

        return $pure[$entry] ?? null;
    }

    /**
     * ZipArchive 경로 — 확장이 있으면 가장 견고하다. 실패는 조용히 빈 배열.
     *
     * @param callable(string): bool $want
     * @return array<string, string>
     */
    private static function readWithZipArchive(string $bytes, callable $want): array
    {
        if (! class_exists(ZipArchive::class)) {
            return [];
        }
        // ZipArchive 는 파일 경로가 필요하므로 임시 파일로 받는다.
        $tmp = tempnam(sys_get_temp_dir(), 'ofc');
        if ($tmp === false) {
            return [];
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                return [];
            }
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if ($want($entry)) {
                    $content = $zip->getFromIndex($i);
                    if ($content !== false && $content !== '') {
                        $out[$entry] = $content;
                    }
                }
            }
            $zip->close();

            return $out;
        } catch (Throwable) {
            return [];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * 순수 PHP zip 리더 — 중앙 디렉터리를 직접 걷고 deflate 는 gzinflate 로 푼다.
     * ext-zip·임시파일 없이 메모리에서만 동작한다. OOXML 처럼 표준적인 zip 만 감당하면 된다.
     *
     * @param callable(string): bool $want
     * @return array<string, string>
     */
    private static function readWithPurePhp(string $bytes, callable $want): array
    {
        try {
            $eocd = strrpos($bytes, "PK\x05\x06");
            if ($eocd === false) {
                return [];
            }
            $count = unpack('v', substr($bytes, $eocd + 10, 2))[1] ?? 0;
            $cd = unpack('V', substr($bytes, $eocd + 16, 4))[1] ?? 0;

            $out = [];
            $p = $cd;
            for ($i = 0; $i < $count; $i++) {
                if (substr($bytes, $p, 4) !== "PK\x01\x02") {
                    break;
                }
                $method = unpack('v', substr($bytes, $p + 10, 2))[1];
                $csize = unpack('V', substr($bytes, $p + 20, 4))[1];
                $nlen = unpack('v', substr($bytes, $p + 28, 2))[1];
                $elen = unpack('v', substr($bytes, $p + 30, 2))[1];
                $clen = unpack('v', substr($bytes, $p + 32, 2))[1];
                $lho = unpack('V', substr($bytes, $p + 42, 4))[1];
                $name = substr($bytes, $p + 46, $nlen);

                if ($want($name)) {
                    $lnlen = unpack('v', substr($bytes, $lho + 26, 2))[1];
                    $lelen = unpack('v', substr($bytes, $lho + 28, 2))[1];
                    $data = substr($bytes, $lho + 30 + $lnlen + $lelen, $csize);
                    $content = $method === 8 ? @gzinflate($data) : ($method === 0 ? $data : false);
                    if ($content !== false && $content !== '') {
                        $out[$name] = $content;
                    }
                }
                $p += 46 + $nlen + $elen + $clen;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    private static function cleanup(string $text): ?string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        // 너무 짧으면(빈 문서/추출 실패) 실패로 처리.
        return mb_strlen($text) >= 10 ? $text : null;
    }
}
