<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CDEK API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for CDEK API integration
    |
    */

    'api_url' => env('CDEK_API_URL', 'https://api.cdek.ru/v2'),

    'ssl_verify' => env('CDEK_SSL_VERIFY', false),

    'timeout' => env('CDEK_TIMEOUT', 300),

    'test_mode' => env('CDEK_TEST_MODE', false),
];
