<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);

$replacements = [
    ' ?? ' => ' с ',
    '??о ' => 'со ',
    '(?? ' => '(с ',
    ' ?? ' => ' с ',
    '??овпадающими' => 'совпадающими',
    '??овпадений' => 'совпадений',
    '??овпадению' => 'совпадению',
    '??овпадении' => 'совпадении',
    '??овпадения' => 'совпадания',
    '??лучай' => 'случай',
    '??траница' => 'страница',
    '??тату??' => 'статус',
    '??татус' => 'статус',
    '??инхронизаци' => 'синхронизаци',
];

$healed = strtr($content, $replacements);

file_put_contents($filePath, $healed);
echo "Final dictionary healing complete.\n";
