<?php
$priceStr = '53 010';
$result = (float)str_replace([' ', 'руб.', 'руб'], '', (string)$priceStr);
echo 'Результат: ' . $result . PHP_EOL;
echo 'Тип: ' . gettype($result) . PHP_EOL;

// Проверим parseInt
$intResult = (int)$priceStr;
echo 'parseInt результат: ' . $intResult . PHP_EOL;
?>