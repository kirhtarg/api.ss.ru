<?php

// Тестовый скрипт для проверки исправления SSL проблемы
require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;

$baseUrl = 'https://ss75-api.kirhtarg.ru';
$testImageUrl = 'https://skateandsnow.ru/images/stories/virtuemart/product/evoc-cc-10-l-rucksack-1.jpg';

echo "🧪 Тестируем исправление SSL проблемы\n";
echo "📡 URL: {$baseUrl}/api/admin/shop/goods/download-image\n";
echo "🖼️ Тестовое изображение: {$testImageUrl}\n\n";

$response = Http::timeout(30)->post($baseUrl . '/api/admin/shop/goods/download-image', [
    'imageUrl' => $testImageUrl,
    'storagePath' => '/shop/goods',
    'optimize' => true,
    'naming' => 'hash',
    'resize' => 'no_change',
    'width' => null,
    'height' => null,
], [
    'Authorization' => 'Bearer ' . ($argv[1] ?? 'test-token'),
    'Content-Type' => 'application/json',
]);

echo "📊 Статус ответа: " . $response->status() . "\n";
echo "📄 Тело ответа:\n";
echo json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if ($response->successful()) {
    $data = $response->json();
    if (isset($data['data']['path'])) {
        $filePath = storage_path('app/public' . $data['data']['path']);
        echo "\n✅ Файл должен быть сохранен по пути: {$filePath}\n";
        echo "📁 Существует ли файл: " . (file_exists($filePath) ? 'ДА' : 'НЕТ') . "\n";
        if (file_exists($filePath)) {
            echo "📏 Размер файла: " . filesize($filePath) . " байт\n";
        }
    }
} else {
    echo "\n❌ Ошибка загрузки изображения\n";
}

