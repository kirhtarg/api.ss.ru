<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'api/*',
    ],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_merge([
        'http://localhost:3000',
        'http://localhost:3001',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'https://skateandsnow.ru',
        'https://api.skateandsnow.ru',
        'https://psy.kirhtarg.ru',
        'https://api-psy.kirhtarg.ru',
        'https://self-reason.ru',
        'https://api.self-reason.ru',
    ], explode(',', env('CORS_ALLOWED_ORIGINS', '')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'X-Session-ID'
    ],

    'exposed_headers' => [
        'X-Total-Count',
        'X-Page-Count',
        'X-Current-Page'
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];
