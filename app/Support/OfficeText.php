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

        if ($isWord) {
            return self::docx($bytes);
        }
        if ($isExcel) {
            return self::xlsx($bytes);
        }

        return null;
    }

    public static function isSupported(string $mime = '', string $filename = ''): bool
    {
        $name = strtolower($filename);
        $mime = strtolower($mime);

        return str_contains($mime, 'wordprocessingml') || str_ends_with($name, '.docx')
            || str_contains($mime, 'spreadsheetml') || str_ends_with($name, '.xlsx');
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

    private static function readZipEntry(string $bytes, string $entry): ?string
    {
        if ($bytes === '') {
            return null;
        }

        // ZipArchive 는 파일 경로가 필요하므로 임시 파일로 받는다.
        $tmp = tempnam(sys_get_temp_dir(), 'ofc');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }
            $xml = $zip->getFromName($entry);
            $zip->close();

            return $xml === false || $xml === '' ? null : $xml;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    private static function cleanup(string $text): ?string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        // 너무 짧으면(빈 문서/추출 실패) 실패로 처리.
        return mb_strlen($text) >= 20 ? $text : null;
    }
}
