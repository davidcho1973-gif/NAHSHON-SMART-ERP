<?php

return [
    // No messages leave a new deployment until the operator completes onboarding.
    'enabled' => env('KAKAO_REMINDERS_ENABLED', false),
    'api_key' => env('SOLAPI_API_KEY', ''),
    'api_secret' => env('SOLAPI_API_SECRET', ''),
    'channel_id' => env('SOLAPI_KAKAO_CHANNEL_ID', ''),
    'base_url' => env('KAKAO_APP_URL', ''),
    // Confirm Alimtalk support with the provider, not merely international SMS support.
    'confirmed_countries' => array_filter(explode(',', env('KAKAO_CONFIRMED_COUNTRIES', ''))),
    'templates' => [
        'clock_in' => env('KAKAO_TEMPLATE_CLOCK_IN', ''),
        'clock_out' => env('KAKAO_TEMPLATE_CLOCK_OUT', ''),
        'daily_report' => env('KAKAO_TEMPLATE_DAILY_REPORT', ''),
    ],
];
