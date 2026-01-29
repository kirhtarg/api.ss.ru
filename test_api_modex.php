<?php

// Тестируем API endpoint для загрузки файлов модекса
$userToken = '5|2stV20vrvmVbxPMB6jVejoZlAFySFMUH011QyZZ6a9449961';

$url = "http://localhost:8000/api/admin/export-files?type=modex";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $userToken,
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: {$httpCode}\n";

if ($httpCode == 200) {
    $data = json_decode($response, true);
    if ($data['success']) {
        echo "Success! Found " . count($data['data']) . " files\n";
        foreach ($data['data'] as $file) {
            echo "- ID: {$file['id']}, Status: {$file['status']}, Name: {$file['original_filename']}\n";
        }
    } else {
        echo "API returned success=false\n";
    }
} else {
    echo "Error response:\n{$response}\n";
}

curl_close($ch);