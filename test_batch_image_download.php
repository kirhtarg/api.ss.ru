<?php

/**
 * Тестовый скрипт для проверки пакетной загрузки изображений
 * Запуск: php test_batch_image_download.php
 */

// Настройки
$baseUrl = 'https://admin.skateandsnow.ru'; // Замените на ваш домен
$token = 'YOUR_API_TOKEN'; // Замените на ваш токен

// Тестовые URL изображений
$testUrls = [
    'https://via.placeholder.com/800x600.jpg',
    'https://via.placeholder.com/600x400.png',
    'https://via.placeholder.com/400x300.gif',
    'https://via.placeholder.com/500x500.webp'
];

// Данные для запроса
$data = [
    'imageUrls' => $testUrls,
    'storagePath' => '/images/shop/goods',
    'optimize' => true,
    'naming' => 'hash',
    'resize' => 'no_change'
];

// Настройки cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/api/admin/shop/goods/download-images-batch');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 секунд таймаут

echo "🚀 Тестирование пакетной загрузки изображений...\n";
echo "📡 URL: {$baseUrl}/api/admin/shop/goods/download-images-batch\n";
echo "🖼️  Изображений для загрузки: " . count($testUrls) . "\n";
echo "📋 URL изображений:\n";
foreach ($testUrls as $i => $url) {
    echo "  " . ($i + 1) . ". {$url}\n";
}
echo "\n";

// Выполняем запрос
$startTime = microtime(true);
$response = curl_exec($ch);
$endTime = microtime(true);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

$executionTime = round(($endTime - $startTime) * 1000, 2);

echo "⏱️  Время выполнения: {$executionTime}ms\n";
echo "📊 HTTP код: {$httpCode}\n\n";

if ($error) {
    echo "❌ Ошибка cURL: {$error}\n";
    exit(1);
}

if ($httpCode !== 200) {
    echo "❌ HTTP ошибка: {$httpCode}\n";
    echo "📄 Ответ сервера:\n{$response}\n";
    exit(1);
}

// Парсим ответ
$result = json_decode($response, true);

if (!$result) {
    echo "❌ Ошибка парсинга JSON\n";
    echo "📄 Ответ сервера:\n{$response}\n";
    exit(1);
}

// Выводим результат
if ($result['success']) {
    echo "✅ Пакетная загрузка успешна!\n\n";
    
    $data = $result['data'];
    echo "📊 Статистика:\n";
    echo "  Всего изображений: {$data['total']}\n";
    echo "  Успешно загружено: {$data['successful']}\n";
    echo "  Ошибок: {$data['failed']}\n\n";
    
    if (!empty($data['paths'])) {
        echo "📁 Загруженные файлы:\n";
        foreach ($data['paths'] as $url => $path) {
            echo "  ✅ {$url}\n";
            echo "     → {$path}\n";
        }
        echo "\n";
    }
    
    if (!empty($data['errors'])) {
        echo "⚠️  Ошибки:\n";
        foreach ($data['errors'] as $error) {
            echo "  ❌ {$error['url']}\n";
            echo "     → {$error['error']}\n";
        }
        echo "\n";
    }
    
    // Проверяем доступность файлов
    echo "🔍 Проверка доступности файлов:\n";
    foreach ($data['paths'] as $url => $path) {
        $fileUrl = $baseUrl . '/storage' . $path;
        $fileCh = curl_init();
        curl_setopt($fileCh, CURLOPT_URL, $fileUrl);
        curl_setopt($fileCh, CURLOPT_NOBODY, true);
        curl_setopt($fileCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($fileCh, CURLOPT_TIMEOUT, 10);
        curl_exec($fileCh);
        $fileHttpCode = curl_getinfo($fileCh, CURLINFO_HTTP_CODE);
        curl_close($fileCh);
        
        if ($fileHttpCode === 200) {
            echo "  ✅ {$fileUrl} - доступен\n";
        } else {
            echo "  ❌ {$fileUrl} - недоступен (HTTP {$fileHttpCode})\n";
        }
    }
    
} else {
    echo "❌ Ошибка: {$result['message']}\n";
    if (isset($result['errors'])) {
        echo "📋 Детали ошибок:\n";
        foreach ($result['errors'] as $field => $errors) {
            echo "  {$field}: " . implode(', ', $errors) . "\n";
        }
    }
    exit(1);
}

echo "\n🎉 Тест завершен успешно!\n";
