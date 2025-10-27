# ✅ ИНСТРУКЦИЯ: Исправление CORS и 413 ошибки

## 🔴 Проблема
Запрос блокируется из-за 413 ошибки (Request Entity Too Large) до того как запрос доходит до Laravel.

## 📋 Пошаговая инструкция для сервера

### Шаг 1: Обновите файлы на сервере

Убедитесь что файлы обновлены (через Git или FTP):
- `app/Http/Middleware/CustomCors.php` 
- `routes/api.php`
- `bootstrap/app.php`

Проверьте:
```bash
cd /path/to/api.ss.ru
git pull
# или проверьте дату последней модификации
stat app/Http/Middleware/CustomCors.php
```

### Шаг 2: Обновите php.ini (КРИТИЧНО для 413)

```bash
# Найдите php.ini
php --ini

# Откройте для редактирования
sudo nano /etc/php/8.2/fpm/php.ini
# (или другая версия PHP - 8.1, 8.0 и т.д.)

# Найдите и измените эти строки:
post_max_size = 256M
upload_max_filesize = 256M
max_input_vars = 5000
memory_limit = 512M
max_execution_time = 600
max_input_time = 600

# Сохраните файл (Ctrl+X, Y, Enter)

# Перезапустите PHP-FPM
sudo systemctl restart php8.2-fpm
# или для Apache:
sudo systemctl restart apache2
```

**Проверьте что изменения применены:**
```bash
php -i | grep post_max_size
php -i | grep max_input_vars
```

### Шаг 3: Обновите Nginx конфигурацию (если используется Nginx)

```bash
# Найдите конфиг вашего сайта
sudo nano /etc/nginx/sites-available/api.skateandsnow-test.ru
# или ваш конфиг

# Добавьте/измените внутри server {}:
client_max_body_size 256M;

# Сохраните и перезапустите
sudo systemctl restart nginx
```

### Шаг 4: Очистите кэш Laravel

```bash
cd /path/to/api.ss.ru

php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
```

### Шаг 5: Обновите .env

```bash
nano .env
```

Добавьте или обновите:
```bash
CORS_ALLOWED_ORIGINS=https://skateandsnow-test.ru,https://admin.skateandsnow-test.ru,https://api.skateandsnow-test.ru,https://skateandsnow.ru,https://admin.skateandsnow.ru,https://api.skateandsnow.ru
CORS_ALLOWED_PATTERNS=*\.skateandsnow\.ru$,*\.skateandsnow-test\.ru$,localhost:\d+,127\.0\.0\.1:\d+
```

### Шаг 6: Проверьте что все работает

```bash
# Смотрите логи в реальном времени
tail -f storage/logs/laravel.log
```

Теперь попробуйте отправить запрос из браузера. В логах должно появиться:
```
local.INFO: CustomCors middleware {"method":"POST","path":"api/admin/shop/goods/bulk-import","origin":"https://skateandsnow-test.ru"}
```

## 🧪 Тест через curl

Проверьте CORS заголовки вручную:

```bash
curl -H "Origin: https://skateandsnow-test.ru" \
     -H "Access-Control-Request-Method: POST" \
     -H "Access-Control-Request-Headers: Content-Type" \
     -X OPTIONS \
     https://api.skateandsnow-test.ru/api/admin/shop/goods/bulk-import \
     -v 2>&1 | grep -i "access-control"
```

Должно вернуть:
```
< Access-Control-Allow-Origin: https://skateandsnow-test.ru
< Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS
```

## 🔍 Если ничего не помогает

### Вариант 1: Проверьте что изменения применены

```bash
# Проверьте версию файла
md5sum app/Http/Middleware/CustomCors.php

# Должен содержать логирование:
grep -n "CustomCors middleware" app/Http/Middleware/CustomCors.php
```

### Вариант 2: Временно разрешите все origins

В `app/Http/Middleware/CustomCors.php` замените строку 32:
```php
$allowOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';
```

На:
```php
$allowOrigin = '*'; // Temporary fix
```

### Вариант 3: Создайте middleware-специфичный для bulk-import

Создайте новую группу middleware специально для этого роута.

## 📊 Диагностика

Выполните на сервере:

```bash
# 1. Проверьте PHP настройки
php -i | grep -E "(upload_max_filesize|post_max_size|max_input_vars)"

# 2. Проверьте Nginx
nginx -T 2>/dev/null | grep client_max_body_size

# 3. Проверьте последние логи
tail -n 100 storage/logs/laravel.log | grep -i cors

# 4. Проверьте что middleware активен
php artisan route:list | grep bulk-import

# 5. Проверьте размер запроса на клиенте
# В браузере DevTools -> Network -> смотрите Size столбец
```

## ⚠️ Важно

1. **413 ошибка** означает что запрос блокируется ДО того как доходит до Laravel
2. После изменения php.ini **ОБЯЗАТЕЛЬНО** перезапустите PHP-FPM/Apache
3. Изменения в коде должны быть **загружены на сервер** (Git pull или FTP)
4. **Все кэши должны быть очищены**

## ✅ Чеклист

- [ ] Файлы обновлены на сервере
- [ ] php.ini изменен (post_max_size = 256M)
- [ ] PHP-FPM перезапущен
- [ ] Nginx конфиг обновлен (client_max_body_size 256M)
- [ ] Nginx перезапущен
- [ ] .env обновлен (CORS_ALLOWED_ORIGINS)
- [ ] php artisan optimize:clear выполнен
- [ ] Логи показывают CustomCors middleware
- [ ] curl тест возвращает CORS заголовки

## 🆘 Если все еще не работает

Создайте файл `api/test-cors.php` на сервере:

```php
<?php
// Временный тест CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

echo json_encode([
    'message' => 'CORS работает',
    'time' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'post_max_size' => ini_get('post_max_size'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
]);
?>
```

Проверьте: `https://api.skateandsnow-test.ru/api/test-cors.php`

Если возвращает JSON - PHP работает. Если нет - проблема в Nginx/Apache конфиге.

