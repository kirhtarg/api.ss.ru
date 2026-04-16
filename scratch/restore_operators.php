<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);

// Regex targeting the space-surrounded ' с ' which is likely an operator ??
// We match common preceding and following tokens in code (variable access, brackets, literals, etc.)
$pattern = '/(?<=[\]\)\'\"\$a-zA-Z0-9]) с (?=[\[\(\'\"\$a-zA-Z0-9nullTRUEFALSE])/u';

$restored = preg_replace($pattern, ' ?? ', $content);

// Final safety cleanup for common patterns that might remain
$manualRestores = [
    ' с null' => ' ?? null',
    ' с []' => ' ?? []',
    ' с \'\'' => ' ?? \'\'',
    ' с 0' => ' ?? 0',
    ' с false' => ' ?? false',
];
$restored = strtr($restored, $manualRestores);

file_put_contents($filePath, $restored);
echo "PHP operators restoration attempt 2 complete.\n";
