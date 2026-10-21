<?php

return [
    'enabled' => (bool) env('CAD_AI_ENABLED', true),
    // Canonical origin comes from deployment configuration, never a request Host.
    'origin' => rtrim((string) env('CAD_AI_ORIGIN', env('APP_URL', '')), '/'),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CAD_AI_ALLOWED_ORIGINS', ''))))),
];
