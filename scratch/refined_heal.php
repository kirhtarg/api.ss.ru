<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);

function heal_string($s) {
    if (!mb_check_encoding($s, 'UTF-8')) return $s;
    
    // Attempt to fix double encoding
    // Most mojibake in this file starts with Р (D0) or С (D1)
    // We target sequences that look like mojibake
    $healed = preg_replace_callback('/[РћР‘РќРѕРјРїСЃР°РµРёР№РєР»СѓРІРіРґР¶Р·РЅРѕРїСЂСЃС‚СѓС„С…С†С‡С€С‰СЉС‹Р¬СЌСЋСЏСЂСЃР‚Р„Р…Р†Р‡РЉРњРќРћРџСЂСЃС‚СѓС„С…С†С‡С€С‰СЉС‹Р¬СЌСЋСЏРђ-РџР°-СЏС‘СЃ]+/', function($matches) {
        $m = $matches[0];
        $unpacked = @mb_convert_encoding($m, 'Windows-1251', 'UTF-8');
        if (mb_check_encoding($unpacked, 'UTF-8') && strlen($unpacked) > 0) {
            return $unpacked;
        }
        return $m;
    }, $s);

    return $healed;
}

$lines = explode("\n", $content);
$newLines = [];
foreach ($lines as $i => $line) {
    $healed = heal_string($line);
    if ($healed !== $line) {
        // echo "Healed line " . ($i+1) . "\n";
    }
    $newLines[] = $healed;
}

file_put_contents($filePath, implode("\n", $newLines));
echo "Refined healing complete.\n";
