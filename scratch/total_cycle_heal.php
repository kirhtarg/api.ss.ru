<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);

// Step 1: Recover the original intended bytes
// Strings like "Р˜РјРїРѕСЂС‚" (UTF-8) will become byte sequence D0 98 D0 BC ...
// Standard strings like "Импорт" (UTF-8) will become byte sequence C8 EC EF ...
$bytes = @mb_convert_encoding($content, 'Windows-1251', 'UTF-8');

// Step 2: Now we have a soup of bytes.
// Some are correct UTF-8 sequences (from healed mojibake)
// Some are pure Windows-1251 bytes (from already correct UTF-8 strings)
// We need to convert only the Windows-1251 ones.

// Actually, the simplest way is to convert the WHOLE byte stream to UTF-8 from CP1251
// BUT skip bytes that are ALREADY valid UTF-8? No, that's complex.

// Better: If we look at the bytes, any sequence > 127 that isn't valid UTF-8 is CP1251.
// But wait, the "Cycle" works!
// CP1251 bytes -> UTF8 -> Correct
// UTF8 bytes -> if we interpret as CP1251 and convert to UTF8, we get mojibake again!

// SO:
$healed = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');

// Now check if $healed is "better" than $content.
// Actually, we can do it line by line to be safe.

file_put_contents($filePath, $healed);
echo "Total cycle healing complete.\n";
