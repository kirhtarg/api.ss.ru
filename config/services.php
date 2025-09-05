<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'vkontakte' => [
        'client_id' => env('VK_CLIENT_ID'),
        'client_secret' => env('VK_CLIENT_SECRET'),
        'redirect' => env('VK_REDIRECT_URI'),
    ],

    'vk' => [
        'client_id' => env('VK_CLIENT_ID'),
        'client_secret' => env('VK_CLIENT_SECRET'),
        'redirect' => env('VK_REDIRECT_URI'),
    ],

    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect' => env('YANDEX_REDIRECT_URI'),
    ],

    'call' => [
        'provider' => env('CALL_PROVIDER', 'voicepassword'), // voicepassword, loginbot, unibell, authcalls, smsprofi
    ],

    'voicepassword' => [
        'api_key' => env('VOICEPASSWORD_API_KEY'),
        'api_url' => env('VOICEPASSWORD_API_URL', 'https://api.voicepassword.ru/call'),
        'from' => env('VOICEPASSWORD_FROM', 'SkateAndSnow'),
    ],

    'loginbot' => [
        'api_key' => env('LOGINBOT_API_KEY'),
        'api_url' => env('LOGINBOT_API_URL', 'https://api.loginbot.ru/call'),
        'from' => env('LOGINBOT_FROM', 'SkateAndSnow'),
    ],

    'unibell' => [
        'api_key' => env('UNIBELL_API_KEY'),
        'api_url' => env('UNIBELL_API_URL', 'https://api.unibell.ru/call'),
        'from' => env('UNIBELL_FROM', 'SkateAndSnow'),
    ],

    'authcalls' => [
        'api_key' => env('AUTHCALLS_API_KEY'),
        'api_url' => env('AUTHCALLS_API_URL', 'https://api.authcalls.net/call'),
        'from' => env('AUTHCALLS_FROM', 'SkateAndSnow'),
    ],

    'smsprofi' => [
        'api_key' => env('SMSPROFI_API_KEY'),
        'api_url' => env('SMSPROFI_CALL_API_URL', 'https://lcab.smsprofi.ru/json/v1.0/callpassword/send'),
        'from' => env('SMSPROFI_FROM', 'SkateAndSnow'),
    ],

    // Резервные SMS провайдеры
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'smsru'), // smsru, smsprofi, notifylk
    ],

    'smsru' => [
        'api_id' => env('SMSRU_API_ID'),
        'api_url' => env('SMSRU_API_URL', 'https://sms.ru/sms/send'),
        'from' => env('SMSRU_FROM', 'SkateAndSnow'),
    ],

    'smsprofi' => [
        'login' => env('SMSPROFI_LOGIN'),
        'password' => env('SMSPROFI_PASSWORD'),
        'api_url' => env('SMSPROFI_API_URL', 'https://api.smsprofi.ru/send'),
        'from' => env('SMSPROFI_FROM', 'SkateAndSnow'),
    ],

    'notifylk' => [
        'user_id' => env('NOTIFYLK_USER_ID'),
        'api_key' => env('NOTIFYLK_API_KEY'),
        'api_url' => env('NOTIFYLK_API_URL', 'https://app.notify.lk/api/v1/send'),
        'from' => env('NOTIFYLK_FROM', 'NotifyDEMO'),
    ],

];
