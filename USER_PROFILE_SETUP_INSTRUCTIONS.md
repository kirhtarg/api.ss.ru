# Инструкции по настройке профиля пользователя для api.ss.ru

## 1. Установка файлов

### 1.1. Миграция базы данных
```bash
# Перейдите в папку api.ss.ru
cd api.ss.ru

# Запустите миграцию
php artisan migrate
```

### 1.2. Создание папки для аватаров
```bash
# Создайте папку для аватаров пользователей
mkdir -p storage/app/public/users
chmod 755 storage/app/public/users
```

### 1.3. Проверка символической ссылки
```bash
# Убедитесь, что символическая ссылка создана
php artisan storage:link
```

## 2. Проверка установки

### 2.1. Проверка миграции
```bash
# Проверьте, что поля добавлены в таблицу users
php artisan tinker
>>> Schema::hasColumn('users', 'first_name')
>>> Schema::hasColumn('users', 'last_name')
>>> Schema::hasColumn('users', 'birthday')
>>> Schema::hasColumn('users', 'avatar_url')
```

### 2.2. Проверка маршрутов
```bash
# Проверьте доступность новых маршрутов
php artisan route:list --path=api/user
php artisan route:list --path=api/upload
```

### 2.3. Проверка API
```bash
# Проверьте работу API (требует авторизации)
curl -X GET http://localhost:8000/api/user/profile \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## 3. Тестирование

### 3.1. Тестирование загрузки аватара
```bash
# Сначала получите токен авторизации
TOKEN="your_auth_token_here"

# Тестируйте загрузку аватара
curl -X POST http://localhost:8000/api/upload/avatar \
  -H "Authorization: Bearer $TOKEN" \
  -F "avatar=@/path/to/test-image.jpg"
```

### 3.2. Тестирование обновления профиля
```bash
curl -X PUT http://localhost:8000/api/user/profile \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Иван",
    "last_name": "Петров",
    "phone": "+7 (999) 123-45-67",
    "birthday": "1990-01-01"
  }'
```

## 4. Структура API

### 4.1. Маршруты профиля пользователя
- `GET /api/user/profile` - получение профиля
- `PUT /api/user/profile` - обновление профиля
- `POST /api/user/change-password` - смена пароля
- `DELETE /api/user/avatar` - удаление аватара
- `GET /api/user/statistics` - статистика пользователя

### 4.2. Маршруты загрузки файлов
- `POST /api/upload/avatar` - загрузка аватара
- `DELETE /api/upload/avatar` - удаление файла аватара
- `GET /api/upload/file-info` - информация о файле

## 5. Формат данных

### 5.1. Получение профиля
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Иван Петров",
    "first_name": "Иван",
    "last_name": "Петров",
    "full_name": "Иван Петров",
    "email": "ivan@example.com",
    "phone": "+7 (999) 123-45-67",
    "birthday": "1990-01-01",
    "avatar_url": "/storage/users/avatar_1234567890_abc123.jpg",
    "is_active": true,
    "role": "user",
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z"
  }
}
```

### 5.2. Обновление профиля
```json
{
  "first_name": "Иван",
  "last_name": "Петров",
  "phone": "+7 (999) 123-45-67",
  "birthday": "1990-01-01",
  "avatar_url": "/storage/users/avatar_1234567890_abc123.jpg"
}
```

### 5.3. Загрузка аватара
```json
{
  "success": true,
  "message": "Аватар успешно загружен",
  "data": {
    "path": "/storage/users/avatar_1234567890_abc123.jpg",
    "url": "/storage/users/avatar_1234567890_abc123.jpg",
    "filename": "avatar_1234567890_abc123.jpg",
    "size": 1024000,
    "mime_type": "image/jpeg"
  }
}
```

## 6. Валидация

### 6.1. Поля профиля
- `first_name` - обязательное, строка, максимум 255 символов
- `last_name` - опциональное, строка, максимум 255 символов
- `phone` - опциональное, строка, максимум 20 символов
- `birthday` - опциональное, дата, не может быть в будущем
- `avatar_url` - опциональное, строка, максимум 500 символов

### 6.2. Загрузка аватара
- `avatar` - обязательное, изображение
- Поддерживаемые форматы: jpeg, png, jpg, gif, webp
- Максимальный размер: 5MB

## 7. Безопасность

### 7.1. Авторизация
- Все маршруты требуют токен авторизации
- Используется Laravel Sanctum

### 7.2. Валидация файлов
- Проверка типа файла
- Проверка размера файла
- Генерация уникальных имен файлов

### 7.3. Очистка старых файлов
- При обновлении аватара старый файл удаляется
- При удалении профиля аватар также удаляется

## 8. Логирование

### 8.1. Логи ошибок
- Все ошибки записываются в `storage/logs/laravel.log`
- Включает детали ошибок в режиме отладки

### 8.2. Мониторинг
```bash
# Просмотр логов
tail -f storage/logs/laravel.log

# Фильтрация по профилю
grep "profile" storage/logs/laravel.log
```

## 9. Производительность

### 9.1. Оптимизация изображений
Рекомендуется добавить оптимизацию изображений:
```bash
composer require intervention/image
```

### 9.2. Кэширование
Добавьте кэширование для часто запрашиваемых данных:
```php
// В контроллере
Cache::remember("user_profile_{$user->id}", 3600, function() use ($user) {
    return $user->toArray();
});
```

## 10. Устранение неполадок

### 10.1. Ошибка "Storage link already exists"
```bash
rm public/storage
php artisan storage:link
```

### 10.2. Ошибка прав доступа
```bash
sudo chown -R www-data:www-data storage/
sudo chmod -R 755 storage/
```

### 10.3. Ошибка "Class not found"
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

## 11. Очистка

### 11.1. Удаление тестовых файлов
```bash
# Очистите папку с аватарами (осторожно!)
rm -rf storage/app/public/users/*
```

### 11.2. Откат миграции (если нужно)
```bash
php artisan migrate:rollback --step=1
```

После выполнения всех шагов API будет готов к работе с фронтендом!
