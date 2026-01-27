# Исправление роутов SR для локальной разработки

## Проблема
Ошибка 405 (Method Not Allowed) при обращении к `/api/admin/sr/cards` на локальном сервере.

## Решение

### 1. Очистить кэш роутов локально

Выполните на локальном сервере Laravel:

```bash
cd api.ss.ru
php artisan route:clear
php artisan config:clear
php artisan cache:clear
```

### 2. Проверить, что роуты зарегистрированы

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

### 3. Если роуты не видны

Проверьте:
1. Что файл `routes/api.php` сохранен
2. Что нет синтаксических ошибок:
   ```bash
   php artisan route:list
   ```
   Если есть ошибки, они будут показаны.

3. Проверьте логи:
   ```bash
   tail -f storage/logs/laravel.log
   ```

### 4. Перезапустить сервер разработки

Если используете `php artisan serve`:
```bash
# Остановите сервер (Ctrl+C)
# Запустите снова
php artisan serve
```

### 5. Проверить структуру роутов

Роуты должны находиться внутри группы:
```php
Route::middleware(['auth:sanctum', 'role:admin,manager'])->prefix('admin')->group(function () {
    // ...
    Route::middleware('role:admin')->prefix('sr')->group(function () {
        Route::get('/cards', ...);
    });
});
```

## Быстрая проверка

Выполните в терминале Laravel:
```bash
php artisan route:list --path=admin/sr
```

Если роуты не отображаются, значит они не зарегистрированы. Проверьте синтаксис файла `routes/api.php`.
