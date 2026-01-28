<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// ЛОГИРУЕМ ВСЕ входящие запросы
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'no-origin',
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'no-ua', 0, 50),
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
];

file_put_contents(__DIR__.'/../storage/logs/debug.log', json_encode($logData) . PHP_EOL, FILE_APPEND);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
