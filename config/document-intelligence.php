<?php

return [
    /*
     * Laravel Cloud에서는 FILESYSTEM_DISK를 비공개 Object Storage 버킷으로 지정한다.
     * 로컬·테스트 환경은 private local disk를 사용한다.
     */
    'disk' => env('DOCUMENT_STORAGE_DISK', env('FILESYSTEM_DISK', 'local')),

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
