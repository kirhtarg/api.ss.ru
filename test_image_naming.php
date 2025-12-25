<?php

require_once 'vendor/autoload.php';

// Тестируем генерацию имен файлов
function testImageNaming($url, $naming, $index = 0) {
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
    $urlPath = parse_url($url, PHP_URL_PATH);
    $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

    if (!in_array($extension, $imageExtensions)) {
        return 'Unsupported format';
    }

    if ($naming === 'original') {
        $originalName = pathinfo($urlPath, PATHINFO_FILENAME);
        $originalName = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $originalName);
        $fileName = $originalName . '.' . $extension;
        $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
    } else {
        $hash = hash('sha256', $url . $index);
        $fileName = $hash . '.' . $extension;
    }

    return $fileName;
}

// Тестовые данные
$testUrls = [
    'https://example.com/images/product1.jpg',
    'https://example.com/images/product1.jpg', // Тот же URL
    'https://example.com/images/product2.jpg',
    'https://example.com/images/товар_с_пробелами.jpg'
];

echo "Тестирование генерации имен файлов изображений:\n\n";

foreach ($testUrls as $index => $url) {
    echo "URL: $url\n";
    echo "Original naming: " . testImageNaming($url, 'original', $index) . "\n";
    echo "Hash naming: " . testImageNaming($url, 'hash', $index) . "\n";
    echo "---\n";
}

echo "Тест завершен.\n";
