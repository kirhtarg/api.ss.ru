<?php

/**
 * Простой скрипт для очистки таблицы shop_goods и всех связанных таблиц
 * Использует прямое подключение к MySQL без Laravel
 * 
 * ВНИМАНИЕ: Этот скрипт удалит ВСЕ данные из указанных таблиц!
 * 
 * Использование:
 * php clear_shop_goods_simple.php
 * 
 * Для подтверждения добавьте параметр --confirm
 * php clear_shop_goods_simple.php --confirm
 */

// Настройки подключения к базе данных
// Измените эти параметры на ваши настройки базы данных
$host = '127.0.0.1';
$port = '3306';
$dbname = 'ss_db'; // Замените на ваше имя базы данных (например: laravel, ss_db)
$username = 'root'; // Замените на ваше имя пользователя
$password = ''; // Замените на ваш пароль

// Если у вас есть .env файл, можно попробовать прочитать настройки из него
if (file_exists(__DIR__ . '/.env')) {
    $env = file_get_contents(__DIR__ . '/.env');
    preg_match('/DB_HOST=(.+)/', $env, $matches);
    if (isset($matches[1])) $host = trim($matches[1]);
    
    preg_match('/DB_DATABASE=(.+)/', $env, $matches);
    if (isset($matches[1])) $dbname = trim($matches[1]);
    
    preg_match('/DB_USERNAME=(.+)/', $env, $matches);
    if (isset($matches[1])) $username = trim($matches[1]);
    
    preg_match('/DB_PASSWORD=(.+)/', $env, $matches);
    if (isset($matches[1])) $password = trim($matches[1]);
    
    preg_match('/DB_PORT=(.+)/', $env, $matches);
    if (isset($matches[1])) $port = trim($matches[1]);
}

// Проверяем подтверждение
$confirm = in_array('--confirm', $argv ?? []);

if (!$confirm) {
    echo "ВНИМАНИЕ: Этот скрипт удалит ВСЕ данные из таблицы shop_goods и всех связанных таблиц!\n";
    echo "Для подтверждения запустите скрипт с параметром --confirm\n";
    echo "Пример: php clear_shop_goods_simple.php --confirm\n";
    exit(1);
}

// Интерактивные запросы для дополнительных таблиц
echo "\n=== Дополнительные опции очистки ===\n";

// Запрос об очистке брендов
echo "Хотите ли также очистить таблицу shop_brands (бренды)?\n";
echo "Это удалит ВСЕ бренды из системы! (y/N): ";
$withBrands = false;
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$withBrands = (trim($line) === 'y' || trim($line) === 'Y');
fclose($handle);

// Запрос об очистке категорий
echo "Хотите ли также очистить таблицу shop_categories (категории)?\n";
echo "Это удалит ВСЕ категории из системы! (y/N): ";
$withCategories = false;
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$withCategories = (trim($line) === 'y' || trim($line) === 'Y');
fclose($handle);

// Показываем итоговый план очистки
echo "\n=== План очистки ===\n";
echo "Будут очищены следующие таблицы:\n";
echo "- shop_goods и все связанные таблицы (товары, изображения, видео, вариации, цены, остатки и т.д.)\n";
if ($withBrands) {
    echo "- shop_brands (бренды)\n";
}
if ($withCategories) {
    echo "- shop_categories (категории)\n";
}

echo "\nПродолжить выполнение? (y/N): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$finalConfirm = (trim($line) === 'y' || trim($line) === 'Y');
fclose($handle);

if (!$finalConfirm) {
    echo "Операция отменена пользователем.\n";
    exit(0);
}

echo "Начинаем очистку таблицы shop_goods и связанных таблиц...\n";
echo "Параметры подключения: $host:$port, база: $dbname, пользователь: $username\n";

try {
    // Подключаемся к базе данных
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    // Отключаем проверку внешних ключей
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    
    $tables = [
        'shop_stock_reservations' => 'резервации товаров',
        'shop_low_stock_notifications' => 'уведомления о низких остатках',
        'shop_good_audits' => 'аудит товаров',
        'shop_good_prices' => 'цены товаров',
        'shop_stocks' => 'остатки товаров',
        'shop_good_images' => 'изображения товаров',
        'shop_good_videos' => 'видео товаров',
        'shop_good_variations' => 'вариации товаров',
        'shop_variation_attributes_values' => 'значения атрибутов вариаций',
        'shop_good_properties' => 'связи товаров со свойствами',
        'shop_good_tags' => 'связи товаров с тегами',
        'shop_good_categories' => 'связи товаров с категориями',
        'shop_good_brands' => 'связи товаров с брендами',
        'shop_goods' => 'товары'
    ];
    
    // Добавляем дополнительные таблицы по запросу
    if ($withBrands) {
        $tables['shop_brands'] = 'бренды';
    }
    if ($withCategories) {
        $tables['shop_categories'] = 'категории';
    }
    
    $totalDeleted = 0;
    
    foreach ($tables as $table => $description) {
        // Проверяем существование таблицы
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        
        if ($stmt->rowCount() > 0) {
            // Подсчитываем количество записей
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table`");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo "Удаляем {$description} ({$count} записей)...\n";
                $pdo->exec("DELETE FROM `$table`");
                $totalDeleted += $count;
            } else {
                echo "Таблица {$table} уже пуста.\n";
            }
        } else {
            echo "Таблица {$table} не существует, пропускаем.\n";
        }
    }
    
    // Включаем обратно проверку внешних ключей
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    
    // Подтверждаем транзакцию
    $pdo->commit();
    
    // Сбрасываем автоинкремент для основных таблиц (вне транзакции)
    $autoIncrementTables = [
        'shop_goods',
        'shop_good_images', 
        'shop_good_videos',
        'shop_good_variations',
        'shop_good_prices',
        'shop_stocks',
        'shop_stock_reservations',
        'shop_good_audits',
        'shop_low_stock_notifications'
    ];
    
    // Добавляем дополнительные таблицы по запросу
    if ($withBrands) {
        $autoIncrementTables[] = 'shop_brands';
    }
    if ($withCategories) {
        $autoIncrementTables[] = 'shop_categories';
    }
    
    echo "Сбрасываем автоинкремент...\n";
    foreach ($autoIncrementTables as $table) {
        try {
            // Проверяем существование таблицы
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
            }
        } catch (Exception $e) {
            echo "Предупреждение: Не удалось сбросить автоинкремент для таблицы {$table}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✅ Очистка завершена успешно!\n";
    echo "Всего удалено записей: {$totalDeleted}\n";
    echo "Автоинкремент сброшен для всех таблиц.\n";
    
} catch (Exception $e) {
    // Откатываем транзакцию в случае ошибки
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "\n❌ Ошибка при очистке: " . $e->getMessage() . "\n";
    echo "Все изменения отменены.\n";
    exit(1);
}
