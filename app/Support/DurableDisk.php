<?php

namespace App\Support;

/**
 * "배포를 견디는" 저장 디스크를 고른다.
 *
 * Laravel Cloud 의 로컬 디스크는 배포마다 초기화된다. 그래서 영구 보관이 필요한
 * 업로드(문서 원본·현장 사진)를 local 에 두면, 배포할 때마다 파일이 조용히 사라지고
 * DB 레코드만 남는다 — 화면에는 멀쩡히 보이다가 다운로드에서 404 가 난다.
 *
 * 그런데 .env 에 DOCUMENT_STORAGE_DISK=local 같은 값이 남아 있으면(대개 .env.example
 * 에서 복사돼 온 기본값이지 의도한 선택이 아니다) 버킷이 붙어 있어도 계속 local 을
 * 쓴다. 실제로 이 조합 때문에 문서 원본이 배포마다 유실됐다.
 *
 * 그래서 규칙을 이렇게 둔다:
 *   - 버킷(AWS_BUCKET)이 없으면 → 명시값 또는 폴백 그대로
 *   - 버킷이 있는데 명시값이 'local' 이면 → s3 (local 은 "고르지 않았다"로 본다)
 *   - 버킷이 있고 명시값이 다른 것이면 → 그 값을 존중 (public·custom 등 의도한 선택)
 */
final class DurableDisk
{
    public static function resolve(?string $explicit, string $fallback = 'local'): string
    {
        $bucket = env('AWS_BUCKET');
        $explicit = is_string($explicit) ? trim($explicit) : '';

        if (blank($bucket)) {
            return $explicit !== '' ? $explicit : $fallback;
        }

        if ($explicit === '' || $explicit === 'local') {
            return 's3';
        }

        return $explicit;
    }
}
