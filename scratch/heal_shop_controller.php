<?php
function heal($filePath) {
    if (!file_exists($filePath)) return "File not found: $filePath";
    $content = file_get_contents($filePath);
    
    // First, try to detect if it's already UTF-8 but double encoded
    // Windows-1251 characters in double-encoded UTF-8 look like:
    // Р (0xD0) followed by another byte (e.g. Р° = 0xD0 0xB0)
    
    // Attempt multi-stage healing
    // 1. If it's valid UTF-8, it might be double encoded
    // Try to convert UTF-8 -> ISO-8859-1 (raw bytes) -> Windows-1251 -> UTF-8
    
    $healed = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $content);
    if ($healed !== false) {
        $healed = @iconv('Windows-1251', 'UTF-8//IGNORE', $healed);
    } else {
        $healed = $content;
    }
    
    // 2. Second pass for nested double-encoding if needed (common in some tools)
    // $check = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $healed);
    // if ($check !== false) {
    //    $secondHeal = @iconv('Windows-1251', 'UTF-8//IGNORE', $check);
    //    if (mb_check_encoding($secondHeal, 'UTF-8')) $healed = $secondHeal;
    // }

    file_put_contents($filePath, $healed);
    return "Healed $filePath";
}

echo heal('f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\ShopGoodsController.php') . "\n";
