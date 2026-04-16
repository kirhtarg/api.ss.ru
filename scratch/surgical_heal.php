<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);

// Map CP1251 bytes to their UTF-8 string representations
$byteToUtf8 = [];
for ($i = 0; $i < 256; $i++) {
    $byteToUtf8[$i] = mb_convert_encoding(chr($i), 'UTF-8', 'Windows-1251');
}

// Build a map of 2-byte UTF-8 sequences (Cyrillic) to their mojibake representation
$fixMap = [];
// Cyrillic range: D0 80 to D1 BF
for ($b1 = 0xD0; $b1 <= 0xD1; $b1++) {
    for ($b2 = 0x80; $b2 <= 0xBF; $b2++) {
        $realUtf8 = chr($b1) . chr($b2);
        $mojibake = $byteToUtf8[$b1] . $byteToUtf8[$b2];
        if ($realUtf8 !== $mojibake) {
            $fixMap[$mojibake] = $realUtf8;
        }
    }
}
// Also single bytes from 0x80 to 0xFF if they appear as single UTF-8 chars? 
// No, the mojibake here is primarily 2-byte UTF-8 sequences treated as 2 CP1251 chars.

// Sort by length descending to replace longest patterns first
uksort($fixMap, function($a, $b) {
    return strlen($b) - strlen($a);
});

$healed = strtr($content, $fixMap);

// Special case for the "Р?" which was caused by my previous failed conversion
// Р? corresponds to Р (D0) and ? (which was a failed mapping of some byte)
// We might not be able to fully recover "Р?", let's see.

file_put_contents($filePath, $healed);
echo "Surgical byte-level healing complete.\n";
