<?php

use App\Support\DurableDisk;

return [
    /*
     * Laravel Cloud에서는 FILESYSTEM_DISK를 비공개 Object Storage 버킷으로 지정한다.
     * 로컬·테스트 환경은 private local disk를 사용한다.
     */
    /*
     * Laravel Cloud 의 로컬 디스크는 배포마다 초기화된다 — local 로 두면 배포할 때마다
     * 문서 원본이 사라지고, 재분석·바로 보기·다운로드가 전부 "파일 없음"이 된다.
     * 버킷(AWS_BUCKET)이 붙어 있으면 s3 를 기본으로 쓴다 (documents_disk 와 같은 규칙).
     */
    'disk' => DurableDisk::resolve(env('DOCUMENT_STORAGE_DISK'), env('FILESYSTEM_DISK', 'local')),

    'max_upload_kb' => (int) env('DOCUMENT_MAX_UPLOAD_KB', 51200),

    'allowed_extensions' => [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'rtf',
        'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'eml',
    ],

    'analysis_timeout' => (int) env('DOCUMENT_AI_TIMEOUT', 180),

    // AI 요청의 base64 팽창과 Cloud worker 메모리 사용량을 제한한다.
    'native_max_bytes' => (int) env('DOCUMENT_AI_NATIVE_MAX_BYTES', 15728640),

    'reminder_windows' => [30, 14, 7, 3, 1],

    /*
     * 교차검증 — 1차(Gemini)가 읽은 것을 회사가 다른 두 번째 눈(Claude)이 원본에서
     * 독립적으로 다시 읽는다. 틀리면 손해가 큰 문서만 소집한다: 모든 문서를 두 번
     * 읽으면 요금과 시간만 두 배가 된다. ANTHROPIC_API_KEY 가 없으면 자동으로 꺼진다.
     */
    'cross_check' => [
        'enabled' => (bool) env('DOCUMENT_CROSS_CHECK', true),
        // 이 금액 이상이면 두 번 읽는다(USD).
        'min_amount' => (float) env('DOCUMENT_CROSS_CHECK_MIN_AMOUNT', 1000),
        // 금액과 무관하게 항상 두 번 읽는 문서 종류 — 장부·계약의 뿌리가 되는 것들.
        'always_types' => ['contract', 'change_order', 'lien_waiver', 'payroll_record'],
    ],
];
