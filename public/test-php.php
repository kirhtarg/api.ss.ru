<?php
/**
 * Тест PHP настроек
 * Откройте: https://api.skateandsnow-test.ru/api/test-php.php
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== PHP Settings Check ===\n\n";
echo 'PHP Version: '.phpversion()."\n";
echo 'Server Software: '.($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown')."\n\n";

echo "--- Size Settings ---\n";
echo 'post_max_size: '.ini_get('post_max_size')."\n";
echo 'upload_max_filesize: '.ini_get('upload_max_filesize')."\n";
echo 'max_input_vars: '.ini_get('max_input_vars')."\n";
echo 'memory_limit: '.ini_get('memory_limit')."\n";
echo 'max_execution_time: '.ini_get('max_execution_time')."\n\n";

echo "--- Request Info ---\n";
echo 'Method: '.$_SERVER['REQUEST_METHOD']."\n";
echo 'Content-Type: '.($_SERVER['CONTENT_TYPE'] ?? 'Not set')."\n";
echo 'Content-Length: '.($_SERVER['CONTENT_LENGTH'] ?? 'Not set')."\n\n";

echo "--- File System ---\n";
echo 'Current Directory: '.__DIR__."\n";
echo 'Document Root: '.($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown')."\n\n";

echo "=== Test Complete ===\n";
?>

