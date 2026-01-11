<?php

require_once __DIR__ . '/vendor/autoload.php';

// Загружаем переменные окружения
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Создаем приложение Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Mail;

try {
    echo "Отправка тестового email...\n";

    Mail::raw('Это тестовое письмо от Skate & Snow API', function ($message) {
        $message->to('test@example.com') // Замените на реальный email для тестирования
                ->subject('Тестовое письмо - Skate & Snow');
    });

    echo "Email успешно отправлен!\n";
    echo "Проверьте логи Laravel для деталей.\n";

} catch (Exception $e) {
    echo "Ошибка отправки email: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}