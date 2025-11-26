<?php

// Получаем токен авторизации
$loginUrl = 'http://localhost:8000/api/login';
$loginData = [
    'email' => 'admin@example.com',
    'password' => 'password'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $loginUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$loginResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login Response (HTTP $httpCode): $loginResponse\n";

$loginData = json_decode($loginResponse, true);
if (!$loginData || !isset($loginData['token'])) {
    echo "Failed to get token\n";
    exit;
}

$token = $loginData['token'];
echo "Token: $token\n\n";

// Теперь тестируем загрузку изображения с токеном
$url = 'http://localhost:8000/api/admin/shop/goods/download-image';
$data = [
    'imageUrl' => 'https://via.placeholder.com/300x200.jpg',
    'storagePath' => '/shop/goods',
    'optimize' => true,
    'naming' => 'hash',
    'resize' => 'no_change',
    'width' => null,
    'height' => null
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Image Download Response (HTTP $httpCode): $response\n";
