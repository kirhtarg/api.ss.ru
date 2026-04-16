<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$lines = file($filePath);
if (isset($lines[36])) {
    $line = $lines[36];
    echo "Line 37 content: " . $line . "\n";
    echo "Bytes: ";
    for ($i = 0; $i < strlen($line); $i++) {
        printf("%02X ", ord($line[$i]));
    }
    echo "\n";
} else {
    echo "Line 1864 not found.\n";
}
