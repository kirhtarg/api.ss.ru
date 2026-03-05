<?php

$file = __DIR__.'/storage/logs/modex_debug.log';
$result = file_put_contents($file, "TEST WRITE\n", FILE_APPEND);
echo 'Result: '.($result === false ? 'FALSE' : $result)."\n";
echo 'File path: '.$file."\n";
if (file_exists($file)) {
    echo 'Content: '.file_get_contents($file);
} else {
    echo "File not found after write.\n";
}
