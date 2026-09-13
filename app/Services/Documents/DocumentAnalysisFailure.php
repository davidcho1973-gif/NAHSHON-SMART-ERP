<?php

namespace App\Services\Documents;

/** Stable, safe operator errors. Never return raw provider responses or SQL. */
final class DocumentAnalysisFailure
{
    public static function preflightMessage(\Throwable $e): ?string
    {
        $m = mb_strtolower($e->getMessage());
        foreach ([
            'invalid_workbook' => 'Excel 구조 또는 셀 참조가 손상되었습니다. Excel에서 다시 저장해 주세요.',
            'extraction_limit' => '압축 해제 본문이 안전 한도를 넘습니다. 문서를 나눠 주세요.',
            'input_encoding' => 'AI 요청 문자 인코딩 검사에 실패했습니다. 서버의 본문 추출 처리를 확인해 주세요.',
            'empty_analysis' => 'AI가 사용할 수 있는 분석 결과를 반환하지 않았습니다. 원본과 응답 로그를 확인해 주세요.',
            'source_mismatch' => '원본과 등록 당시 파일 정보가 다릅니다. 저장소의 원본을 확인해 주세요.',
        ] as $code => $message) {
            if (str_contains($m, '['.$code.']')) {
                return '['.strtoupper($code).'] '.$message;
            }
        }
        if ($e instanceof \JsonException || str_contains($m, 'malformed utf-8')) {
            return '[INPUT_ENCODING] AI 요청 문자 인코딩 검사에 실패했습니다. 서버의 본문 추출 처리를 확인해 주세요.';
        }
        if (str_contains($m, 'is not configured') || preg_match('/\b(401|403)\b/', $m) || str_contains($m, 'api key not valid')) {
            return '[AI_AUTH] AI 키 또는 접근 권한을 확인해 주세요. 관리자 설정 및 공급사 인증 기록 확인이 필요합니다.';
        }
        if (str_contains($m, 'not valid json') || str_contains($m, 'no analysis text')) {
            return '[AI_RESPONSE] AI 응답 형식이 올바르지 않습니다. 응답 로그를 확인해 주세요.';
        }

        return null;
    }

    public static function retryWithText(\Throwable $e): bool
    {
        $m = mb_strtolower($e->getMessage());

        return self::preflightMessage($e) === null
            && ! str_contains($m, '429') && ! str_contains($m, 'quota') && ! str_contains($m, 'rate limit');
    }
}
