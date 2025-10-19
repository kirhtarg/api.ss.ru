# PowerShell скрипт для установки профиля пользователя в api.ss.ru
# Запустите: .\install_user_profile.ps1

Write-Host "🚀 Установка профиля пользователя для api.ss.ru..." -ForegroundColor Green

# Проверка наличия Laravel
if (-not (Test-Path "artisan")) {
    Write-Host "❌ Ошибка: Файл artisan не найден. Убедитесь, что вы находитесь в папке api.ss.ru." -ForegroundColor Red
    exit 1
}

Write-Host "📁 Создание папки для аватаров..." -ForegroundColor Yellow

# Создание папки для аватаров
if (-not (Test-Path "storage/app/public/users")) {
    New-Item -ItemType Directory -Path "storage/app/public/users" -Force
}
Write-Host "✅ Папка для аватаров создана" -ForegroundColor Green

Write-Host "🗄️ Настройка базы данных..." -ForegroundColor Yellow

# Запуск миграции
try {
    php artisan migrate --force
    Write-Host "✅ Миграция выполнена успешно" -ForegroundColor Green
} catch {
    Write-Host "❌ Ошибка при выполнении миграции: $_" -ForegroundColor Red
    exit 1
}

Write-Host "📁 Настройка хранилища файлов..." -ForegroundColor Yellow

# Создание символической ссылки
try {
    php artisan storage:link
    Write-Host "✅ Символическая ссылка создана" -ForegroundColor Green
} catch {
    Write-Host "⚠️ Предупреждение: Не удалось создать символическую ссылку (возможно, уже существует)" -ForegroundColor Yellow
}

Write-Host "🧹 Очистка кэша..." -ForegroundColor Yellow

# Очистка кэша
try {
    php artisan config:clear
    php artisan cache:clear
    php artisan route:clear
    php artisan view:clear
    Write-Host "✅ Кэш очищен" -ForegroundColor Green
} catch {
    Write-Host "⚠️ Предупреждение: Ошибка при очистке кэша: $_" -ForegroundColor Yellow
}

Write-Host "🧪 Тестирование..." -ForegroundColor Yellow

# Проверка маршрутов
Write-Host "Проверка маршрутов..."
$userRoutes = php artisan route:list --path=api/user | Select-String "user/profile"
if ($userRoutes) {
    Write-Host "✅ Маршруты пользователя добавлены" -ForegroundColor Green
} else {
    Write-Host "⚠️ Маршруты пользователя не найдены" -ForegroundColor Yellow
}

$uploadRoutes = php artisan route:list --path=api/upload | Select-String "upload/avatar"
if ($uploadRoutes) {
    Write-Host "✅ Маршруты загрузки добавлены" -ForegroundColor Green
} else {
    Write-Host "⚠️ Маршруты загрузки не найдены" -ForegroundColor Yellow
}

# Проверка папки с аватарами
if (Test-Path "storage/app/public/users") {
    Write-Host "✅ Папка для аватаров существует" -ForegroundColor Green
} else {
    Write-Host "❌ Папка для аватаров не создана" -ForegroundColor Red
}

Write-Host ""
Write-Host "🎉 Установка завершена!" -ForegroundColor Green
Write-Host ""
Write-Host "📋 Что было сделано:" -ForegroundColor Cyan
Write-Host "  ✅ Добавлены поля first_name, last_name, birthday, avatar_url в таблицу users" -ForegroundColor White
Write-Host "  ✅ Создан UserProfileController для управления профилем пользователя" -ForegroundColor White
Write-Host "  ✅ Создан AvatarUploadController для загрузки аватаров" -ForegroundColor White
Write-Host "  ✅ Обновлен Admin ProfileController с новыми полями" -ForegroundColor White
Write-Host "  ✅ Добавлены API маршруты для пользователей" -ForegroundColor White
Write-Host "  ✅ Создана папка для хранения аватаров" -ForegroundColor White
Write-Host ""
Write-Host "🔧 Следующие шаги:" -ForegroundColor Cyan
Write-Host "  1. Убедитесь, что веб-сервер запущен" -ForegroundColor White
Write-Host "  2. Проверьте работу API: http://localhost:8000/api/user/profile" -ForegroundColor White
Write-Host "  3. Протестируйте загрузку аватара через фронтенд" -ForegroundColor White
Write-Host ""
Write-Host "📚 Дополнительная информация:" -ForegroundColor Cyan
Write-Host "  - Документация: USER_PROFILE_SETUP_INSTRUCTIONS.md" -ForegroundColor White
Write-Host "  - Логи: storage/logs/laravel.log" -ForegroundColor White
Write-Host ""
Write-Host "✨ Готово к работе!" -ForegroundColor Green
