<?php

return [
    // David requested owner-only access first; a role alone must never grant access.
    'allowed_emails' => array_filter(array_map('trim', explode(',', (string) env('DOTS_ALLOWED_EMAILS', 'davidcho1973@gmail.com')))),
    'url' => env('DOTS_URL', 'https://chatgpt.com/dots/01a1097d-4f09-71e0-b479-542efd9860ea'),
];
