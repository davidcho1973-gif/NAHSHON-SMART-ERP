<?php

return [
    // Shipping code must not create an externally reachable OAuth/MCP surface.
    'enabled' => (bool) env('ERP_MCP_ENABLED', false),

    // One canonical HTTPS origin and one resource. No Host-header-derived metadata.
    'issuer' => rtrim((string) env('ERP_MCP_ISSUER', ''), '/'),
    'resource' => (string) env('ERP_MCP_RESOURCE', ''),
    'user_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERP_MCP_USER_IDS', ''))))),
    'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERP_MCP_CLIENT_IDS', ''))))),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERP_MCP_ALLOWED_ORIGINS', ''))))),
    'access_token_minutes' => 15,
    'refresh_token_days' => 7,
];
