# Диагностика проблем CORS и 413

## Проблема
Запросы к `/api/admin/shop/goods/bulk-import` блокируются с ошибками:
- CORS: "No 'Access-Control-Allow-Origin' header is present"
- 413: "Request Entity Too Large"

## Быстрая диагностика

### 1. Проверьте, применяется ли CustomCors middleware

Выполните на сервере в `/api.ss.ru`:
```bash
tail -f storage/logs/laravel.log | grep "CustomCors"
```

**Что должно быть видно:**
```
[2024-01-01 10:00:00] local.INFO: CustomCors middleware {"method":"POST","path":"api/admin/shop/goods/bulk-import","origin":"https://skateandsnow-test.ru"}
[2024-01-01 10:00:00] local.INFO: CORS headers set {"origin":"https://skateandsnow-test.ru","finalOrigin":"https://skateandsnow-test.ru","statusCode":200}
```

**Если логов НЕТ:**
- Middleware не применяется
- Изменения не обновлены на сервере
- Нужно выполнить: `php artisan optimize:clear`

### 2. Проверьте PHP настройки

Выполните на сервере:
```bash
php -i | grep -E "(upload_max_filesize|post_max_size|max_input_vars)"
```

**Должно быть:**
```
upload_max_filesize => 256M => 256M
post_max_size => 256M => 256M
max_input_vars => 5000 => 5000
```

**Если меньше:**
```bash
# Найдите php.ini
php --ini

# Отредактируйте php.ini
sudo nano /etc/php/8.x/fpm/php.ini

# Измените:
post_max_size = 256M
upload_max_filesize = 256M
max_input_vars = 5000
memory_limit = 512M
max_execution_time = 600

# Перезапустите PHP-FPM
sudo systemctl restart php8.x-fpm
```

### 3. Проверьте Nginx (если используется)

```bash
# Найдите конфиг
nginx -T | grep "client_max_body_size"

# Должно быть:
client_max_body_size 256M;
```

**Если нет или меньше:**
```bash
sudo nano /etc/nginx/sites-available/your-site.conf

# Добавьте внутри server {}:
client_max_body_size 256M;

# Перезапустите nginx
sudo systemctl restart nginx
```

### 4. Проверьте .env файл

```bash
cd /path/to/api.ss.ru
grep CORS_ALLOWED_ORIGINS .env
```

**Должно включать:**
```
CORS_ALLOWED_ORIGINS=...,https://skateandsnow-test.ru,...
```

### 5. Очистите все кэши Laravel

```bash
cd /path/to/api.ss.ru

# Очистка кэша
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

# Проверьте что файлы обновлены
ls -la app/Http/Middleware/CustomCors.php
ls -la routes/api.php
ls -la bootstrap/app.php
```

## Чеклист для исправления

- [ ] Проверить что изменения в коде загружены на сервер (Git pull или FTP)
- [ ] Обновить php.ini (post_max_size, upload_max_filesize, max_input_vars)
- [ ] Перезапустить PHP-FPM
- [ ] Обновить nginx конфиг (client_max_body_size)
- [ ] Перезапустить nginx
- [ ] Обновить .env (CORS_ALLOWED_ORIGINS)
- [ ] Выполнить php artisan optimize:clear
- [ ] Проверить логи Laravel

## Команды для быстрой проверки

```bash
# Найти php.ini
php --ini

# Проверить PHP настройки
php -i | grep post_max_size
php -i | grep upload_max_filesize
php -i | grep max_input_vars

# Проверить логи (последние 50 строк)
tail -n 50 storage/logs/laravel.log

# Проверить логи в реальном времени
tail -f storage/logs/laravel.log

# Проверить что файлы обновлены (последняя модификация)
stat app/Http/Middleware/CustomCors.php
stat routes/api.php
stat bootstrap/app.php

# Проверить что изменения применены
php artisan route:list | grep "bulk-import"
```

## Альтернативное решение (если ничего не помогает)

Временно добавьте в начало контроллера `BulkGoodsImportController.php`:

```php
public function bulkImport(Request $request)
{
    // Временный фикс для CORS
    $response = \Illuminate\Support\Facades\Response::json([], 200);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin');
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->headers->set('Access-Control-Max-Age', '86400');
    
    // Ваш существующий код...
    
    return $response;
}
```

**Это НЕ рекомендуется для продакшена**, но поможет понять работает ли вообще запрос.

## Тест вручную (curl)

```bash
# Проверьте есть ли CORS заголовки
curl -H "Origin: https://skateandsnow-test.ru" \
     -H "Access-Control-Request-Method: POST" \
     -H "Access-Control-Request-Headers: Content-Type" \
     -X OPTIONS \
     https://api.skateandsnow-test.ru/api/admin/shop/goods/bulk-import \
     -v
```

**В выводе должны быть заголовки:**
```
Access-Control-Allow-Origin: https://skateandsnow-test.ru
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS
```

