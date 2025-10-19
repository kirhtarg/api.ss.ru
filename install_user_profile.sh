#!/bin/bash

# Скрипт для установки профиля пользователя в api.ss.ru
# Запустите: chmod +x install_user_profile.sh && ./install_user_profile.sh

echo "🚀 Установка профиля пользователя для api.ss.ru..."

# Проверка наличия Laravel
if [ ! -f "artisan" ]; then
    echo "❌ Ошибка: Файл artisan не найден. Убедитесь, что вы находитесь в папке api.ss.ru."
    exit 1
fi

echo "📁 Создание папки для аватаров..."

# Создание папки для аватаров
mkdir -p storage/app/public/users
chmod 755 storage/app/public/users
echo "✅ Папка для аватаров создана"

echo "🗄️ Настройка базы данных..."

# Запуск миграции
php artisan migrate --force
if [ $? -eq 0 ]; then
    echo "✅ Миграция выполнена успешно"
else
    echo "❌ Ошибка при выполнении миграции"
    exit 1
fi

echo "📁 Настройка хранилища файлов..."

# Создание символической ссылки
php artisan storage:link
if [ $? -eq 0 ]; then
    echo "✅ Символическая ссылка создана"
else
    echo "⚠️ Предупреждение: Не удалось создать символическую ссылку (возможно, уже существует)"
fi

# Установка прав доступа
chmod -R 755 storage/
echo "✅ Права доступа установлены"

echo "🧹 Очистка кэша..."

# Очистка кэша
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo "✅ Кэш очищен"

echo "🧪 Тестирование..."

# Проверка маршрутов
echo "Проверка маршрутов..."
php artisan route:list --path=api/user | grep -q "user/profile" && echo "✅ Маршруты пользователя добавлены" || echo "⚠️ Маршруты пользователя не найдены"
php artisan route:list --path=api/upload | grep -q "upload/avatar" && echo "✅ Маршруты загрузки добавлены" || echo "⚠️ Маршруты загрузки не найдены"

# Проверка папки с аватарами
if [ -d "storage/app/public/users" ]; then
    echo "✅ Папка для аватаров существует"
else
    echo "❌ Папка для аватаров не создана"
fi

echo ""
echo "🎉 Установка завершена!"
echo ""
echo "📋 Что было сделано:"
echo "  ✅ Добавлены поля first_name, last_name, birthday, avatar_url в таблицу users"
echo "  ✅ Создан UserProfileController для управления профилем пользователя"
echo "  ✅ Создан AvatarUploadController для загрузки аватаров"
echo "  ✅ Обновлен Admin ProfileController с новыми полями"
echo "  ✅ Добавлены API маршруты для пользователей"
echo "  ✅ Создана папка для хранения аватаров"
echo "  ✅ Настроены права доступа"
echo ""
echo "🔧 Следующие шаги:"
echo "  1. Убедитесь, что веб-сервер запущен"
echo "  2. Проверьте работу API: http://localhost:8000/api/user/profile"
echo "  3. Протестируйте загрузку аватара через фронтенд"
echo ""
echo "📚 Дополнительная информация:"
echo "  - Документация: USER_PROFILE_SETUP_INSTRUCTIONS.md"
echo "  - Логи: storage/logs/laravel.log"
echo ""
echo "✨ Готово к работе!"
