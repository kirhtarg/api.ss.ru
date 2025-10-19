#!/bin/bash

# Скрипт для ручного исправления проблем с миграциями
# Выполните этот скрипт на сервере

echo "=== Ручное исправление миграций ==="

# Переходим в директорию проекта
cd /var/www/api.ss.ru

echo "1. Очистка кэша Laravel..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "2. Проверка статуса миграций..."
php artisan migrate:status

echo "3. Пропуск проблемной миграции warehouse_id..."
# Помечаем проблемную миграцию как выполненную без фактического выполнения
php artisan tinker --execute="
DB::table('migrations')->insert([
    'migration' => '2025_09_08_223509_add_warehouse_id_to_shop_stocks_table',
    'batch' => DB::table('migrations')->max('batch') + 1
]);
"

echo "4. Запуск остальных миграций..."
php artisan migrate --force

echo "5. Ручное добавление warehouse_id если нужно..."
php artisan tinker --execute="
if (!Schema::hasColumn('shop_stocks', 'warehouse_id')) {
    Schema::table('shop_stocks', function (\$table) {
        \$table->foreignId('warehouse_id')->nullable()->after('variation_id')->constrained('shop_warehouses')->onDelete('cascade');
    });
    echo 'warehouse_id добавлен успешно';
} else {
    echo 'warehouse_id уже существует';
}
"

echo "6. Запуск сидеров..."
php artisan db:seed --force

echo "7. Очистка кэша после миграций..."
php artisan config:clear
php artisan cache:clear

echo "8. Проверка финального статуса..."
php artisan migrate:status

echo "=== Исправление завершено! ==="
