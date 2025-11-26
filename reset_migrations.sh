#!/bin/bash

# Скрипт для полного сброса и запуска миграций Laravel
# Выполните этот скрипт на сервере

echo "=== Сброс и запуск миграций Laravel ==="

# Переходим в директорию проекта
cd /var/www/api.ss.ru

echo "1. Очистка кэша Laravel..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "2. Проверка статуса миграций..."
php artisan migrate:status

echo "3. Полный сброс миграций (ВНИМАНИЕ: это удалит все данные!)..."
read -p "Вы уверены, что хотите удалить все данные? (yes/no): " confirm
if [ "$confirm" = "yes" ]; then
    php artisan migrate:reset --force
    echo "Миграции сброшены."
else
    echo "Отменено пользователем."
    exit 1
fi

echo "4. Запуск миграций с нуля..."
php artisan migrate --force

echo "5. Запуск сидеров..."
php artisan db:seed --force

echo "6. Очистка кэша после миграций..."
php artisan config:clear
php artisan cache:clear

echo "7. Проверка финального статуса..."
php artisan migrate:status

echo "=== Миграции завершены успешно! ==="
