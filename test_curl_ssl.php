<?php

// Простой тест cURL с различными настройками SSL
$testUrl = 'https://skateandsnow.ru/images/stories/virtuemart/product/evoc-cc-10-l-rucksack-1.jpg';

echo "🧪 Тестируем различные настройки cURL для обхода SSL проблем\n";
echo "🖼️ URL: {$testUrl}\n\n";

// Тест 1: Базовые настройки
echo "📋 Тест 1: Базовые настройки\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "Результат: " . ($result ? "УСПЕХ" : "ОШИБКА") . "\n";
echo "HTTP код: {$httpCode}\n";
echo "Ошибка: " . ($error ?: "Нет") . "\n\n";

// Тест 2: С пониженным уровнем безопасности
echo "📋 Тест 2: С пониженным уровнем безопасности\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_ALLOW_BEAST | CURLSSLOPT_NO_REVOKE);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "Результат: " . ($result ? "УСПЕХ" : "ОШИБКА") . "\n";
echo "HTTP код: {$httpCode}\n";
echo "Ошибка: " . ($error ?: "Нет") . "\n\n";

// Тест 3: Попробуем wget
echo "📋 Тест 3: Попытка через wget\n";
$tempFile = tempnam(sys_get_temp_dir(), 'test_image');
$wgetCommand = "wget --no-check-certificate --timeout=10 -O '{$tempFile}' '{$testUrl}' 2>&1";
$wgetOutput = shell_exec($wgetCommand);

if (file_exists($tempFile) && filesize($tempFile) > 0) {
    echo "Результат: УСПЕХ\n";
    echo "Размер файла: " . filesize($tempFile) . " байт\n";
    unlink($tempFile);
} else {
    echo "Результат: ОШИБКА\n";
    echo "Вывод wget: {$wgetOutput}\n";
}

echo "\n✅ Тестирование завершено\n";

