<?php

require_once 'vendor/autoload.php';

// Загружаем конфигурацию Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Log;

echo "=== Yandex Avatar Debug ===\n";

// Проверяем, что приходит от Yandex API
// Для тестирования можно использовать curl или создать тестовый запрос

echo "1. Проверяем конфигурацию Yandex:\n";
echo "Client ID: " . config('services.yandex.client_id') . "\n";
echo "Redirect URI: " . config('services.yandex.redirect') . "\n";

echo "\n2. Проверяем структуру таблицы users:\n";
$columns = \DB::select("DESCRIBE users");
foreach ($columns as $column) {
    if (strpos($column->Field, 'avatar') !== false) {
        echo "- {$column->Field}: {$column->Type} (Null: {$column->Null}, Default: {$column->Default})\n";
    }
}

echo "\n3. Проверяем последних пользователей с Yandex:\n";
$yandexUsers = \App\Models\User::whereNotNull('yandex_id')->orderBy('updated_at', 'desc')->limit(5)->get();
foreach ($yandexUsers as $user) {
    echo "- ID: {$user->id}, Name: {$user->name}, Email: {$user->email}\n";
    echo "  Yandex ID: {$user->yandex_id}\n";
    echo "  Avatar URL: " . ($user->avatar_url ?? 'NULL') . "\n";
    echo "  Avatar (local): " . ($user->avatar ?? 'NULL') . "\n";
    echo "  Updated: {$user->updated_at}\n\n";
}

echo "\n4. Проверяем логи Yandex:\n";
$logFile = storage_path('logs/laravel.log');
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $yandexLogs = array_filter(explode("\n", $logs), function($line) {
        return strpos($line, 'Yandex') !== false;
    });
    
    $recentLogs = array_slice($yandexLogs, -10); // Последние 10 записей
    foreach ($recentLogs as $log) {
        echo $log . "\n";
    }
} else {
    echo "Лог файл не найден: $logFile\n";
}

echo "\n=== End Debug ===\n";
