<?php

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
    'disk' => env('DOCUMENT_STORAGE_DISK', env('AWS_BUCKET') ? 's3' : env('FILESYSTEM_DISK', 'local')),

    'max_upload_kb' => (int) env('DOCUMENT_MAX_UPLOAD_KB', 51200),

    'allowed_extensions' => [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'rtf',
        'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'eml',
    ],

    'analysis_timeout' => (int) env('DOCUMENT_AI_TIMEOUT', 180),

    // AI 요청의 base64 팽창과 Cloud worker 메모리 사용량을 제한한다.
    'native_max_bytes' => (int) env('DOCUMENT_AI_NATIVE_MAX_BYTES', 15728640),

    'reminder_windows' => [30, 14, 7, 3, 1],
];
