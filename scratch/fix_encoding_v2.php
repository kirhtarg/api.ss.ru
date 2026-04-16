<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$lines = file($filePath);

// Line mapping (1-indexed to 0-indexed)
// Format: line_number => [regex, replacement]
$targets = [
    1864 => ["/'message' => '.*'/", "'message' => 'Импорт завершен'"],
    4845 => ["/'message' => '.*'/", "'message' => 'Обнуление остатков отключено'"],
    4846 => ["/'reason' => '.*'/", "'reason' => 'Функция обнуления остатков полностью отключена по требованию'"],
    4857 => ["/'message' => '.*'/", "'message' => 'Обнуление остатков отключено'"],
    4858 => ["/'reason' => '.*'/", "'reason' => 'Функция обнуления остатков полностью отключена по требованию'"]
];

foreach ($targets as $lineNum => $data) {
    if (isset($lines[$lineNum - 1])) {
        $oldLine = $lines[$lineNum - 1];
        $lines[$lineNum - 1] = preg_replace($data[0], $data[1], $oldLine);
        if ($oldLine !== $lines[$lineNum - 1]) {
            echo "Line $lineNum updated.\n";
        } else {
            echo "Line $lineNum NOT updated (pattern mismatch).\n";
        }
    }
}

file_put_contents($filePath, implode('', $lines));
echo "Done.\n";
