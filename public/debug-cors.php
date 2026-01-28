<?php
// Простой отладочный скрипт для проверки CORS
header('Content-Type: application/json');

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'no-origin',
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'no-ua', 0, 100),
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'query_string' => $_SERVER['QUERY_STRING'] ?? '',
    'all_headers' => getallheaders(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'php_version' => phpversion(),
    'cors_headers' => [
        'access_control_allow_origin' => 'https://skateandsnow.ru',
        'access_control_allow_methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
        'access_control_allow_headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID',
        'access_control_allow_credentials' => 'true',
    ]
];

// Устанавливаем CORS заголовки
header('Access-Control-Allow-Origin: https://skateandsnow.ru');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID');
header('Access-Control-Allow-Credentials: true');

echo json_encode($debug, JSON_PRETTY_PRINT);
?>