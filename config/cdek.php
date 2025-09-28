<?php

return [
    'api_url' => env('CDEK_API_URL', 'https://api.cdek.ru/v2'),
    'client_id' => env('CDEK_CLIENT_ID'),
    'client_secret' => env('CDEK_CLIENT_SECRET'),
    'sender_city_id' => env('CDEK_SENDER_CITY_ID', 44), // Москва
    'sender_address' => env('CDEK_SENDER_ADDRESS', 'г. Москва, ул. Примерная, д. 1'),
    'sender_phone' => env('CDEK_SENDER_PHONE', '+7 (999) 123-45-67'),
    'sender_name' => env('CDEK_SENDER_NAME', 'ООО "Скейт энд Сноу"'),
];
