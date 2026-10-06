<?php

return [
    // Existing Ask remains available only when its provider key is already configured.
    // These flags never create credentials or activate another external service.
    'enabled' => (bool) env('AI_ASSISTANT_ENABLED', true),
    'mutations_enabled' => (bool) env('AI_ASSISTANT_MUTATIONS_ENABLED', false),
    'checks_enabled' => (bool) env('AI_ASSISTANT_CHECKS_ENABLED', false),

    // Bounded calls, not a dollar estimate: one reservation permits one provider request.
    // UTC calendar periods; failed/uncertain requests still consume a reservation.
    'company_daily_requests' => (int) env('AI_ASSISTANT_COMPANY_DAILY_REQUESTS', 200),
    'company_monthly_requests' => (int) env('AI_ASSISTANT_COMPANY_MONTHLY_REQUESTS', 3000),
    'user_daily_requests' => (int) env('AI_ASSISTANT_USER_DAILY_REQUESTS', 20),
    'user_monthly_requests' => (int) env('AI_ASSISTANT_USER_MONTHLY_REQUESTS', 300),
    'max_input_bytes' => (int) env('AI_ASSISTANT_MAX_INPUT_BYTES', 64000),
    'max_output_tokens' => (int) env('AI_ASSISTANT_MAX_OUTPUT_TOKENS', 1200),

    // Optional company-id keyed overrides of enabled and the four request limits.
    // Zero means disabled/exhausted, never unlimited. Omitted values use the defaults.
    'companies' => [],
];
