<?php

return [
    'available_scopes' => ['catalog:read', 'checkout:read', 'orders:read', 'orders:write', 'payments:write'],
    'signature_ttl_seconds' => (int) env('PARTNER_API_SIGNATURE_TTL', 300),
    'rate_limit_per_minute' => (int) env('PARTNER_API_RATE_LIMIT', 120),
    'reservation_ttl_minutes' => (int) env('PARTNER_RESERVATION_TTL_MINUTES', 30),
    'webhook_timeout_seconds' => (int) env('PARTNER_WEBHOOK_TIMEOUT', 10),
    'webhook_max_attempts' => (int) env('PARTNER_WEBHOOK_MAX_ATTEMPTS', 8),
    'webhook_allow_http' => (bool) env('PARTNER_WEBHOOK_ALLOW_HTTP', false),
    'webhook_allowed_ports' => array_values(array_map(
        'intval',
        array_filter(explode(',', (string) env('PARTNER_WEBHOOK_ALLOWED_PORTS', '443')))
    )),
];
