<?php

return [
    'api_url' => env('TESTBANK_API_URL', 'https://test-bank.example.com/api'),
    'api_key' => env('TESTBANK_API_KEY'),
    'merchant_id' => env('TESTBANK_MERCHANT_ID'),
    'webhook_secret' => env('TESTBANK_WEBHOOK_SECRET'),
    'test_mode' => env('TESTBANK_TEST_MODE', true),
];
