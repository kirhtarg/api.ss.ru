<?php

/**
 * Скрипт для очистки таблицы shop_goods и всех связанных таблиц
 *
 * ВНИМАНИЕ: Этот скрипт удалит ВСЕ данные из указанных таблиц!
 *
 * Использование:
 * php clear_shop_goods.php
 *
 * Для подтверждения добавьте параметр --confirm
 * php clear_shop_goods.php --confirm
 */

// Загружаем автозагрузчик Composer
require_once __DIR__.'/vendor/autoload.php';

// Загружаем конфигурацию Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Используем Laravel DB facade
use Illuminate\Support\Facades\DB;

// Проверяем подтверждение
$confirm = in_array('--confirm', $argv ?? []);

if (! $confirm) {
    echo "ВНИМАНИЕ: Этот скрипт удалит ВСЕ данные из таблицы shop_goods и всех связанных таблиц!\n";
    echo "Для подтверждения запустите скрипт с параметром --confirm\n";
    echo "Пример: php clear_shop_goods.php --confirm\n";
    exit(1);
}

// Интерактивные запросы для дополнительных таблиц
echo "\n=== Дополнительные опции очистки ===\n";

// Запрос об очистке брендов
echo "Хотите ли также очистить таблицу shop_brands (бренды)?\n";
echo 'Это удалит ВСЕ бренды из системы! (y/N): ';
$withBrands = false;
$handle = fopen('php://stdin', 'r');
$line = fgets($handle);
$withBrands = (trim($line) === 'y' || trim($line) === 'Y');
fclose($handle);

// Запрос об очистке категорий
echo "Хотите ли также очистить таблицу shop_categories (категории)?\n";
echo 'Это удалит ВСЕ категории из системы! (y/N): ';
$withCategories = false;
$handle = fopen('php://stdin', 'r');
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
$handle = fopen('php://stdin', 'r');
$line = fgets($handle);
$finalConfirm = (trim($line) === 'y' || trim($line) === 'Y');
fclose($handle);

if (! $finalConfirm) {
    echo "Операция отменена пользователем.\n";
    exit(0);
}

echo "Начинаем очистку таблицы shop_goods и связанных таблиц...\n";

try {
    // Начинаем транзакцию
    DB::beginTransaction();

    // Отключаем проверку внешних ключей
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

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
        'shop_goods' => 'товары',
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
        $count = DB::table($table)->count();
        if ($count > 0) {
            echo "Удаляем {$description} ({$count} записей)...\n";
            DB::table($table)->truncate();
            $totalDeleted += $count;
        } else {
            echo "Таблица {$table} уже пуста.\n";
        }
    }

    // Включаем обратно проверку внешних ключей
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');

    // Подтверждаем транзакцию
    DB::commit();

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
        'shop_low_stock_notifications',
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
            // Проверяем существование таблицы перед сбросом автоинкремента
            $exists = DB::select("SHOW TABLES LIKE '{$table}'");
            if (! empty($exists)) {
                DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = 1");
            }
        } catch (Exception $e) {
            echo "Предупреждение: Не удалось сбросить автоинкремент для таблицы {$table}: ".$e->getMessage()."\n";
        }
    }

    echo "\n✅ Очистка завершена успешно!\n";
    echo "Всего удалено записей: {$totalDeleted}\n";
    echo "Автоинкремент сброшен для всех таблиц.\n";

} catch (Exception $e) {
    // Откатываем транзакцию в случае ошибки
    DB::rollBack();

    echo "\n❌ Ошибка при очистке: ".$e->getMessage()."\n";
    echo "Все изменения отменены.\n";
    exit(1);
}
