<?php

// Подключение к базе данных
require_once __DIR__.'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$database = $_ENV['DB_DATABASE'] ?? 'ss_db';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Connected to database successfully\n\n";

    // Тестируем фильтр "Меньше 3" (1 и 2)
    echo "Testing LOW filter (less than 3, excluding 0):\n";
    echo "=========================================\n\n";

    // Запрос для товаров с вариациями
    $sqlWithVariations = '
        SELECT 
            g.id as good_id,
            g.name as good_name,
            COALESCE(SUM(v.stock_quantity), 0) as total_variation_stock,
            COUNT(v.id) as variation_count
        FROM shop_goods g
        LEFT JOIN shop_good_variations v ON g.id = v.good_id
        WHERE g.id IN (
            SELECT good_id FROM shop_good_variations GROUP BY good_id HAVING COUNT(*) > 0
        )
        GROUP BY g.id, g.name
        HAVING COALESCE(SUM(v.stock_quantity), 0) < 3 AND COALESCE(SUM(v.stock_quantity), 0) > 0
        ORDER BY total_variation_stock ASC
        LIMIT 10
    ';

    $stmt = $pdo->query($sqlWithVariations);
    $resultsWithVariations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Products WITH variations (total stock 1-2):\n";
    foreach ($resultsWithVariations as $row) {
        echo "ID: {$row['good_id']}, Name: {$row['good_name']}, Total Stock: {$row['total_variation_stock']}, Variations: {$row['variation_count']}\n";
    }

    if (empty($resultsWithVariations)) {
        echo "No products with variations found with stock 1-2\n";
    }

    echo "\n";

    // Запрос для товаров без вариаций
    $sqlWithoutVariations = '
        SELECT 
            g.id as good_id,
            g.name as good_name,
            g.stock_quantity
        FROM shop_goods g
        WHERE g.id NOT IN (
            SELECT good_id FROM shop_good_variations GROUP BY good_id
        )
        AND g.stock_quantity < 3 AND g.stock_quantity > 0
        ORDER BY g.stock_quantity ASC
        LIMIT 10
    ';

    $stmt = $pdo->query($sqlWithoutVariations);
    $resultsWithoutVariations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Products WITHOUT variations (stock 1-2):\n";
    foreach ($resultsWithoutVariations as $row) {
        echo "ID: {$row['good_id']}, Name: {$row['good_name']}, Stock: {$row['stock_quantity']}\n";
    }

    if (empty($resultsWithoutVariations)) {
        echo "No products without variations found with stock 1-2\n";
    }

    echo "\n";

    // Проверяем, какие значения есть в базе
    echo "Checking actual stock values in database:\n";
    echo "============================================\n";

    // Для товаров с вариациями
    $sqlVariationStocks = '
        SELECT 
            COALESCE(SUM(v.stock_quantity), 0) as total_stock,
            COUNT(*) as product_count
        FROM shop_goods g
        LEFT JOIN shop_good_variations v ON g.id = v.good_id
        WHERE g.id IN (
            SELECT good_id FROM shop_good_variations GROUP BY good_id HAVING COUNT(*) > 0
        )
        GROUP BY g.id
        ORDER BY total_stock ASC
        LIMIT 20
    ';

    $stmt = $pdo->query($sqlVariationStocks);
    $variationStocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Stock values for products WITH variations:\n";
    foreach ($variationStocks as $row) {
        echo "Total Stock: {$row['total_stock']}, Count: {$row['product_count']}\n";
    }

    // Для товаров без вариаций
    $sqlMainStocks = '
        SELECT 
            stock_quantity,
            COUNT(*) as product_count
        FROM shop_goods
        WHERE id NOT IN (
            SELECT good_id FROM shop_good_variations GROUP BY good_id
        )
        AND stock_quantity IS NOT NULL
        GROUP BY stock_quantity
        ORDER BY stock_quantity ASC
        LIMIT 20
    ';

    $stmt = $pdo->query($sqlMainStocks);
    $mainStocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "\nStock values for products WITHOUT variations:\n";
    foreach ($mainStocks as $row) {
        echo "Stock: {$row['stock_quantity']}, Count: {$row['product_count']}\n";
    }

} catch (PDOException $e) {
    echo 'Connection failed: '.$e->getMessage()."\n";
}
