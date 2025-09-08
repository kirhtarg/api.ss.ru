<?php

// Тест загрузки изображения
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
    'Authorization: Bearer test-token'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
