<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIO;
use PhpOffice\PhpSpreadsheet\Writer\Html as SpreadsheetHtmlWriter;
use PhpOffice\PhpWord\IOFactory as WordIO;
use Throwable;
use ZipArchive;

/**
 * 오피스 파일을 브라우저가 그릴 수 있는 HTML 로 바꾼다.
 *
 * "바로 보기" 가 PDF·이미지·텍스트만 지원해서, 엑셀·워드·파워포인트를 올린 사람은
 * 원본 대신 <b>추출한 글자 덩어리</b>를 봤다. 급여명세서 서식(노란 입력칸, 표)을
 * 올렸는데 줄글이 나오면 서식을 올린 의미가 없다.
 *
 * 원인은 브라우저가 오피스 형식을 원래 렌더하지 못한다는 것이다. 그래서 서버가
 * 형식별로 HTML 로 변환한다:
 *   xlsx/xls/csv → PhpSpreadsheet HTML Writer (표·병합·배경색·글꼴 유지)
 *   docx         → PhpWord HTML Writer (문단·표·서식 유지)
 *   pptx         → 슬라이드별 텍스트를 슬라이드 틀에 담아 순서대로 (완전 재현은 아님)
 *
 * 변환 결과는 사용자 업로드 내용이므로 신뢰하지 않는다 — 호출자는 반드시
 * safeHeaders() 의 CSP 와 함께 응답해야 스크립트가 실행될 수 없다.
 */
final class OfficePreview
{
    /** 미리보기 변환을 지원하는 확장자. */
    public const EXTENSIONS = ['xlsx', 'xls', 'csv', 'docx', 'pptx'];

    public static function supports(?string $extension): bool
    {
        return in_array(strtolower((string) $extension), self::EXTENSIONS, true);
    }

    /**
     * 파일 내용을 HTML 문서로 변환한다. 변환 불가/실패면 null — 호출자는 기존
     * 다운로드 안내로 후퇴한다(미리보기가 업로드 자체를 막으면 안 된다).
     */
    public static function html(string $bytes, string $extension, string $title = ''): ?string
    {
        $extension = strtolower($extension);

        try {
            $body = match ($extension) {
                'xlsx', 'xls', 'csv' => self::spreadsheet($bytes, $extension),
                'docx' => self::word($bytes),
                'pptx' => self::slides($bytes),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return $body === null ? null : self::page($title, $body);
    }

    /** 응답에 반드시 함께 보낼 머리글 — 업로드된 내용이 스크립트로 살아나지 못하게. */
    public static function safeHeaders(): array
    {
        return [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'self'; sandbox",
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];
    }

    // ── 형식별 변환 ────────────────────────────────────────────────────

    private static function spreadsheet(string $bytes, string $extension): ?string
    {
        $tmp = self::tempFile($bytes, $extension);
        if ($tmp === null) {
            return null;
        }

        try {
            // 자동 감지를 쓰지 않는다 — 깨진 xlsx 를 CSV 리더가 "아무 글자나 표" 로
            // 잘못 읽어낸다. 확장자대로 읽고, 깨졌으면 깨졌다고 실패해야 후퇴가 된다.
            $reader = SpreadsheetIO::createReader(match ($extension) {
                'xlsx' => 'Xlsx',
                'xls' => 'Xls',
                default => 'Csv',
            });
            $reader->setReadDataOnly(false);       // 배경색·서식을 살린다 — 노란 입력칸이 노랗게 보여야 한다.
            $spreadsheet = $reader->load($tmp);

            $writer = new SpreadsheetHtmlWriter($spreadsheet);
            $writer->writeAllSheets();             // 시트가 여러 장이면 전부, 시트 이름과 함께.
            $writer->setEmbedImages(true);         // 서식에 박힌 로고 등은 data: 로 안에 넣는다(외부 요청 없음).

            $html = $writer->generateHtmlAll();
            $spreadsheet->disconnectWorksheets();

            // 본문만 추려 우리 껍데기에 담는다(스타일 <style> 은 head 에 있으므로 같이 가져온다).
            return self::extractHeadStyles($html).self::extractBody($html);
        } finally {
            @unlink($tmp);
        }
    }

    private static function word(string $bytes): ?string
    {
        $tmp = self::tempFile($bytes, 'docx');
        if ($tmp === null) {
            return null;
        }

        try {
            $document = WordIO::load($tmp, 'Word2007');
            $writer = WordIO::createWriter($document, 'HTML');

            ob_start();
            $writer->save('php://output');
            $html = (string) ob_get_clean();

            return self::extractHeadStyles($html)
                .'<div style="max-width:900px;margin:0 auto;background:#fff;padding:40px 48px;'
                .'box-shadow:0 1px 8px rgba(0,0,0,.12)">'.self::extractBody($html).'</div>';
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * pptx — 순수 PHP 로 슬라이드를 그림처럼 재현할 방법이 없어, 슬라이드별 텍스트를
     * 슬라이드 틀(16:9 흰 카드)에 순서대로 담는다. 배치·그림은 빠진다고 위에 밝힌다.
     */
    private static function slides(string $bytes): ?string
    {
        $slides = self::zipEntries($bytes, '#^ppt/slides/slide\d+\.xml$#');
        if ($slides === []) {
            return null;
        }

        uksort($slides, static function (string $a, string $b): int {
            preg_match('/(\d+)/', $a, $ma);
            preg_match('/(\d+)/', $b, $mb);

            return ((int) ($ma[1] ?? 0)) <=> ((int) ($mb[1] ?? 0));
        });

        $esc = static fn (string $t): string => htmlspecialchars(html_entity_decode($t), ENT_QUOTES, 'UTF-8');
        $cards = [];
        $no = 0;
        foreach ($slides as $xml) {
            $no++;
            // 문단(<a:p>) 단위로 줄을 나누고, 문단 안의 텍스트런(<a:t>)을 이어 붙인다.
            $lines = [];
            if (preg_match_all('/<a:p\b.*?<\/a:p>/s', $xml, $paragraphs)) {
                foreach ($paragraphs[0] as $p) {
                    if (preg_match_all('/<a:t[^>]*>(.*?)<\/a:t>/s', $p, $runs)) {
                        $line = trim(implode('', array_map($esc, $runs[1])));
                        if ($line !== '') {
                            $lines[] = $line;
                        }
                    }
                }
            }

            $first = array_shift($lines);
            $cards[] = '<div style="background:#fff;aspect-ratio:16/9;max-width:960px;margin:0 auto 26px;'
                .'box-shadow:0 1px 10px rgba(0,0,0,.15);border-radius:6px;padding:44px 56px;box-sizing:border-box;overflow:auto">'
                .'<div style="font-size:11px;color:#94a3b8;margin-bottom:14px">슬라이드 '.$no.'</div>'
                .($first !== null ? '<div style="font-size:24px;font-weight:800;margin-bottom:16px">'.$first.'</div>' : '')
                .($lines !== [] ? '<div style="font-size:15px;line-height:1.9">'.implode('<br>', $lines).'</div>' : '')
                .'</div>';
        }

        return '<p style="max-width:960px;margin:0 auto 18px;font-size:12px;color:#64748b">'
            .'슬라이드의 글 내용만 표시합니다(그림·배치 제외). 원본 그대로 보려면 다운로드하세요.</p>'
            .implode('', $cards);
    }

    // ── 공통 ───────────────────────────────────────────────────────────

    private static function page(string $title, string $body): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            ."<title>{$safeTitle}</title>"
            .'<style>body{margin:0;padding:28px 20px;background:#eef1f5;'
            .'font-family:-apple-system,"Malgun Gothic","Apple SD Gothic Neo",sans-serif}'
            .'table{background:#fff}</style>'
            .'</head><body>'.$body.'</body></html>';
    }

    private static function extractBody(string $html): string
    {
        return preg_match('/<body[^>]*>(.*)<\/body>/s', $html, $m) ? $m[1] : $html;
    }

    private static function extractHeadStyles(string $html): string
    {
        return preg_match_all('/<style[^>]*>.*?<\/style>/s', $html, $m) ? implode('', $m[0]) : '';
    }

    private static function tempFile(string $bytes, string $extension): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'opv');
        if ($tmp === false) {
            return null;
        }
        // 확장자가 있어야 리더 자동 감지가 맞게 돈다.
        $path = $tmp.'.'.$extension;
        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return null;
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    /** @return array<string, string> */
    private static function zipEntries(string $bytes, string $regex): array
    {
        $tmp = self::tempFile($bytes, 'zip');
        if ($tmp === null) {
            return [];
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                return [];
            }
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (preg_match($regex, $entry)) {
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
}
