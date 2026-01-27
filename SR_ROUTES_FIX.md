# Исправление роутов для модуля SR

## Проблема
Ошибка 405 (Method Not Allowed) при обращении к `/api/admin/sr/cards`

## Решение

Роуты для SR модуля были добавлены, но необходимо:

1. **Очистить кэш роутов на сервере:**
   ```bash
   cd /var/www/api.ss.ru
   php artisan route:clear
   php artisan config:clear
   php artisan cache:clear
   ```

2. **Проверить, что роуты зарегистрированы:**
   ```bash
   php artisan route:list | grep sr
   ```

   Должны быть видны роуты:
   - GET /api/admin/sr/categories
   - POST /api/admin/sr/categories
   - PATCH /api/admin/sr/categories/{id}
   - DELETE /api/admin/sr/categories/{id}
   - GET /api/admin/sr/cards
   - POST /api/admin/sr/cards
   - PATCH /api/admin/sr/cards/{id}
   - DELETE /api/admin/sr/cards/{id}
   - POST /api/admin/sr/upload

3. **Если роуты не видны, проверьте:**
   - Что файл `routes/api.php` обновлен на сервере
   - Что нет синтаксических ошибок в файле
   - Что контроллеры существуют и правильно названы

## Структура роутов

Роуты находятся внутри группы:
```php
Route::middleware(['auth:sanctum', 'role:admin,manager'])->prefix('admin')->group(function () {
    // ... другие роуты ...
    
    // SELF-REASON (SR) Module management (только для админов)
    Route::middleware('role:admin')->prefix('sr')->group(function () {
        // Роуты SR
    });
});
```

Это означает, что полный путь к роутам будет:
- `/api/admin/sr/categories`
- `/api/admin/sr/cards`
- `/api/admin/sr/upload`

## Проверка работы

После очистки кэша проверьте:

```bash
# Проверка через curl
curl -X GET "http://localhost:8000/api/admin/sr/cards" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

Если все еще получаете 405, проверьте:
1. Что миграции выполнены (таблицы существуют)
2. Что контроллеры существуют и не имеют синтаксических ошибок
3. Логи Laravel: `tail -f storage/logs/laravel.log`
