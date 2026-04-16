<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);
preg_match_all('/[a-zA-Zа-яА-Я0-9_]*\?\?[a-zA-Zа-яА-Я0-9_?]*/u', $content, $m);
$words = array_unique($m[0]);
sort($words);
echo implode("\n", $words) . "\n";
