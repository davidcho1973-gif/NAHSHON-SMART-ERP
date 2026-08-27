<?php

namespace App\Support;

use Smalot\PdfParser\Parser;

/**
 * PDF 본문 텍스트를 서버에서 직접 추출한다.
 *
 * 왜: AI 에게 "렌더된 표"를 보여주고 추출시키면(vision) 조밀한 공정표에서 행을 자주 빠뜨리고
 * 열을 뒤섞는다(예: 공종 GC + 투입조 PM/PE 를 "GC-PM/PE" 로 병합, 80행 중 1행만 반환).
 * 표의 실제 텍스트를 뽑아 프롬프트에 정본으로 넣어주면 AI 는 모든 행을 텍스트로 파싱하므로
 * 훨씬 완전하고 정확하다. 스캔(이미지) PDF 는 텍스트가 없으니 null 을 돌려 vision 경로로 폴백한다.
 */
final class PdfText
{
    /**
     * @param  string  $bytes  PDF 원본 바이트
     * @return string|null  추출된 텍스트(의미 있는 분량일 때). 실패/스캔본이면 null.
     */
    public static function extract(string $bytes): ?string
    {
        if ($bytes === '' || ! str_starts_with($bytes, '%PDF')) {
            return null;
        }

        // 대형 도면 PDF 는 압축 스트림이 수백 MB 로 전개되며 파서가 어떤 한도를 줘도 결국
        // 메모리를 뚫는다(실측: 22MB 토목 시트가 512M 소진). 텍스트를 포기하고 vision/요약
        // 경로로 넘기는 것이 맞다 — 어차피 이런 파일은 추출해도 좌표 쓰레기뿐이다.
        if (strlen($bytes) > 8 * 1024 * 1024) {
            return null;
        }

        // CAD 출력 PDF 는 페이지 하나가 수십만 오퍼레이션짜리 콘텐츠 스트림이라 파서가
        // 기본 memory_limit(128M)을 뚫는다 — Fatal 은 catch 로 못 잡으니 한도를 먼저 올린다.
        // (703K 컷시트북 09of15 Halton 시트에서 실사고. 실패해도 vision 폴백이 있으니 과감히.)
        $prevLimit = (string) ini_get('memory_limit');
        $bumped = false;
        if (self::iniBytes($prevLimit) >= 0 && self::iniBytes($prevLimit) < 512 * 1048576) {
            $bumped = @ini_set('memory_limit', '512M') !== false;
        }

        try {
            // 압축 스트림 해제 상한 — 도면 PDF 는 스트림 하나가 수백 MB 로 풀려 gzuncompress 가
            // Fatal(잡을 수 없음)로 죽는다. 상한을 걸면 catch 가능한 예외가 되어 vision 폴백으로 넘어간다.
            $config = new \Smalot\PdfParser\Config();
            $config->setDecodeMemoryLimit(64 * 1024 * 1024);
            $parser = new Parser([], $config);
            $text = (string) $parser->parseContent($bytes)->getText();
        } catch (\Throwable $e) {
            return null;
        } finally {
            if ($bumped) {
                @ini_set('memory_limit', $prevLimit);
            }
        }

        // 서브셋 폰트(ToUnicode 누락) PDF — CAD 출력물이 흔히 그렇다 — 는 깨진 바이트를 뱉는다.
        // 그대로 두면 AI 요청 json_encode('Malformed UTF-8')와 Postgres 저장이 모두 죽으므로
        // 유효한 UTF-8 만 남기고 제어문자를 걷어낸다. (703K 컷시트북 Halton 시트에서 실사고)
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;

        // 공백 정리(과도한 개행/스페이스 축약) — 토큰 절약 + 파싱 안정.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        // 텍스트가 너무 적으면(스캔본/추출 실패) vision 으로 폴백.
        return mb_strlen($text) >= 500 ? $text : null;
    }

    /**
     * php.ini 축약 표기('128M', '1G', '-1')를 바이트로. -1(무제한)은 -1 그대로.
     */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtoupper(substr($value, -1));
        $num = (int) $value;

        return match ($unit) {
            'G' => $num * 1073741824,
            'M' => $num * 1048576,
            'K' => $num * 1024,
            default => $num,
        };
    }

    /**
     * 분석기가 넘기는 base64 PDF 페이로드에서 텍스트를 뽑는다. PDF 가 아니거나 실패하면 null.
     *
     * @param  array{data?: string, media_type?: string}|null  $pdf
     */
    public static function fromPayload(?array $pdf): ?string
    {
        if (! is_array($pdf) || (string) ($pdf['data'] ?? '') === '') {
            return null;
        }
        if (! str_contains(strtolower((string) ($pdf['media_type'] ?? '')), 'pdf')) {
            return null;
        }

        $bytes = base64_decode((string) $pdf['data'], true);

        return $bytes === false ? null : self::extract($bytes);
    }
}
