<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);
$lines = explode("\n", $content);
$newLines = [];

foreach ($lines as $line) {
    // Check if line is valid UTF-8
    if (!mb_check_encoding($line, 'UTF-8')) {
        // If not, it's likely CP1251. Convert it.
        $converted = mb_convert_encoding($line, 'UTF-8', 'Windows-1251');
        $newLines[] = $converted;
    } else {
        // If it is valid UTF-8, check if it's "mojibake saved as UTF-8"
        // Like "Р˜РјРїРѕСЂС‚"
        // If it contains typical patterns, we might need a special fix, 
        // but for now let's assume it's okay or already fixed by us.
        $newLines[] = $line;
    }
}

file_put_contents($filePath, implode("\n", $newLines));
echo "Conversion complete.\n";
