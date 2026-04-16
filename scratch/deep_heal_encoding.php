<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);
$lines = explode("\n", $content);
$newLines = [];

foreach ($lines as $line) {
    if (mb_check_encoding($line, 'UTF-8')) {
        // Line is valid UTF-8 (could be correct or double-encoded mojibake)
        $unpacked = @mb_convert_encoding($line, 'Windows-1251', 'UTF-8');
        if (mb_check_encoding($unpacked, 'UTF-8') && $unpacked !== '') {
            // Case: double-encoded mojibake became correct UTF-8
            $newLines[] = $unpacked;
        } else {
            // Case: correct UTF-8 was "broken" by conversion to CP1251, so we revert it
            // Actually, we can just keep the original line
            $newLines[] = $line;
        }
    } else {
        // Line is pure CP1251 - convert to UTF-8
        $newLines[] = mb_convert_encoding($line, 'UTF-8', 'Windows-1251');
    }
}

file_put_contents($filePath, implode("\n", $newLines));
echo "Deep healing complete.\n";
