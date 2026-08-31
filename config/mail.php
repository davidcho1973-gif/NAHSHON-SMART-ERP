<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        /*
         * Microsoft 365 — SMTP 가 아니라 Graph API 로 보낸다.
         *
         * 마이크로소프트가 SMTP 클라이언트 제출의 기본 인증을 폐지하는 중이라(2026-04-30 부터
         * 일부 거절, 2026년 12월 말 기본 비활성화) 아이디·비밀번호 방식은 토대가 못 된다.
         * Graph 는 OAuth 라 만료되는 비밀번호가 없고, 고정 IP 도 필요 없다.
         *
         * 회사 도메인의 SPF 가 `-all` 하드 페일이라 마이크로소프트 외의 경로로 보내면
         * 받는 쪽이 거절한다 — 이 방식은 마이크로소프트가 보내는 것이므로 SPF·DKIM 이
         * 그대로 통과하고, <b>DNS 를 하나도 안 건드린다.</b>
         */
        'graph' => [
            'transport' => 'graph',
            'tenant_id' => env('GRAPH_MAIL_TENANT_ID'),
            'client_id' => env('GRAPH_MAIL_CLIENT_ID'),
            'client_secret' => env('GRAPH_MAIL_CLIENT_SECRET'),
            // 이 사서함의 이름으로 나가고, 이 사서함의 «보낸 편지함» 에도 남는다.
            'sender' => env('GRAPH_MAIL_SENDER', env('MAIL_FROM_ADDRESS')),
            'save_to_sent_items' => (bool) env('GRAPH_MAIL_SAVE_SENT', true),
        ],

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
