<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | 문서통합관리 등 "영구 보관이 필요한" 업로드가 쓰는 디스크.
    | Laravel Cloud 의 로컬 디스크는 배포마다 초기화되므로(임시), 오브젝트 스토리지(s3)를
    | 붙이면 DOCUMENT_DISK=s3 로 지정한다. AWS_BUCKET 이 설정돼 있으면 자동으로 s3 를 쓴다.
    */
    'documents_disk' => env('DOCUMENT_DISK', env('AWS_BUCKET') ? 's3' : 'public'),

    /*
    | 문서통합관리 업로드 최대 용량(KB). 기본 262144KB=256MB. 시청 제출본 등 대용량 PDF 대응.
    | 실제 상한은 서버(PHP post_max_size/upload_max_filesize, nginx client_max_body_size)와
    | 이 값 중 작은 쪽이다. 더 키우려면 Laravel Cloud 의 PHP/웹서버 한도도 같이 올려야 한다.
    */
    'documents_max_kb' => (int) env('DOCUMENT_MAX_KB', 262144),

    /*
    | AI 본문분석을 시도하는 최대 용량(KB). 기본 51200KB=50MB. 이보다 크면 메모리 폭주·분석
    | 실패(무한 '분석중')를 피하려고 AI 없이 "보관 등록"만 한다(파일은 정상 저장·열람 가능).
    */
    'documents_analyze_max_kb' => (int) env('DOCUMENT_ANALYZE_MAX_KB', 51200),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
