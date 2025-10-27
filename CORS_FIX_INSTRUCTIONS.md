# Инструкция по исправлению CORS и 413 ошибки для тестового домена

## Проблемы
1. **CORS** блокирует запросы с `https://skateandsnow-test.ru` на API `https://api.skateandsnow-test.ru/api/admin/shop/goods/bulk-import`
2. **413 (Request Entity Too Large)** - размер POST запроса превышает лимит сервера

## Внесенные изменения

### 1. Обновлены файлы:
- `api.ss.ru/routes/api.php` - обработка OPTIONS запросов для CORS
- `api.ss.ru/app/Http/Middleware/CustomCors.php` - middleware для CORS заголовков
- `api.ss.ru/bootstrap/app.php` - упрощена конфигурация middleware (убрано дублирование)
- `api.ss.ru/env.example` - добавлены тестовые домены в CORS_ALLOWED_ORIGINS

### 2. Добавленные домены:
- `https://skateandsnow-test.ru`
- `https://admin.skateandsnow-test.ru`
- `https://api.skateandsnow-test.ru`
- Production домены (skateandsnow.ru)

## Шаги для применения на сервере

### 1. Исправьте настройки PHP (CRITICAL для 413 ошибки)

Нужно увеличить лимиты размера POST запросов в `php.ini`:

```ini
; Увеличьте для больших bulk import запросов
upload_max_filesize = 256M
post_max_size = 256M

; Увеличьте лимит входных переменных (для больших массивов данных)
max_input_vars = 5000

; Увеличьте лимит памяти
memory_limit = 512M

; Увеличьте время выполнения для больших запросов
max_execution_time = 600
max_input_time = 600
```

**Важно**: После изменения `php.ini` перезапустите PHP-FPM:
```bash
# Для PHP-FPM
sudo systemctl restart php8.x-fpm

# Или для Apache
sudo systemctl restart apache2
```

**Проверка** текущих настроек:
```bash
php -i | grep -E "(upload_max_filesize|post_max_size|max_input_vars|max_execution_time|memory_limit)"
```

### 2. Обновите файл .env на сервере
Добавьте или обновите переменные:
```bash
CORS_ALLOWED_ORIGINS=https://ss75.kirhtarg.ru,http://ss75.kirhtarg.ru,https://admin.skateandsnow.ru,http://admin.skateandsnow.ru,https://skateandsnow-test.ru,https://admin.skateandsnow-test.ru,https://api.skateandsnow-test.ru,https://skateandsnow.ru,https://admin.skateandsnow.ru,https://api.skateandsnow.ru
CORS_ALLOWED_PATTERNS=*\.kirhtarg\.ru$,*\.skateandsnow\.ru$,*\.skateandsnow-test\.ru$,localhost:\d+,127\.0\.0\.1:\d+
```

### 3. Выполните команды на сервере в директории api.ss.ru:

```bash
# Перейдите в директорию проекта
cd /path/to/api.ss.ru

# Очистите все кэши
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

# Очистите кэш опcache (если используется)
php artisan optimize:clear

# Пересоберите кэш (опционально, для production)
# php artisan config:cache
# php artisan route:cache
```

### 4. Проверка
После применения изменений проверьте:
1. Откройте DevTools в браузере (F12)
2. Перейдите на вкладку Network
3. Попробуйте сделать запрос
4. Проверьте, что в Headers ответа есть:
   - `Access-Control-Allow-Origin: https://skateandsnow-test.ru`
   - `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS`
   - `Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID`

### 5. Дополнительно: настройка Nginx (если используется)

Если у вас Nginx, добавьте в конфигурацию:

```nginx
# Увеличить размер буфера для больших запросов
client_max_body_size 256M;
client_body_buffer_size 128K;

# Увеличить таймауты
proxy_connect_timeout 600;
proxy_send_timeout 600;
proxy_read_timeout 600;
fastcgi_send_timeout 600;
fastcgi_read_timeout 600;

# Обработка CORS preflight запросов
location /api {
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' $http_origin always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, PATCH, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
        add_header 'Access-Control-Max-Age' '86400' always;
        return 204;
    }
    
    # Проксируем запросы к Laravel
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

### 6. Если проблема сохраняется:
Проверьте логи на сервере:
```bash
tail -f storage/logs/laravel.log
```

Также убедитесь, что в конфигурации веб-сервера (nginx/apache) не блокируются OPTIONS запросы.

## Важные моменты

1. **Изменения в коде уже внесены** - нужно только применить их на сервере
2. **КРИТИЧНО для 413**: Обязательно увеличьте `post_max_size` и `max_input_vars` в php.ini
3. **Очистка кэша обязательна** - иначе изменения не применятся
4. **Проверьте .env на сервере** - убедитесь, что там добавлены тестовые домены
5. **Паттерн `*\.skateandsnow-test\.ru$` уже работает** - он покрывает subdomains
6. **После изменения php.ini ОБЯЗАТЕЛЬНО перезапустите PHP-FPM или Apache**

## Чеклист для исправления

- [ ] Обновить `php.ini` (увеличить post_max_size до 256M)
- [ ] Обновить `php.ini` (увеличить max_input_vars до 5000)
- [ ] Обновить `php.ini` (увеличить memory_limit до 512M)
- [ ] Обновить `php.ini` (увеличить max_execution_time до 600)
- [ ] Перезапустить PHP-FPM/Apache
- [ ] Обновить `.env` на сервере (добавить CORS_ALLOWED_ORIGINS)
- [ ] Выполнить `php artisan config:clear`
- [ ] Выполнить `php artisan route:clear`
- [ ] Выполнить `php artisan cache:clear`
- [ ] Проверить в браузере - ошибки должны исчезнуть

## Команды для быстрой проверки

```bash
# Проверить текущие настройки PHP
php -i | grep -E "(upload_max_filesize|post_max_size|max_input_vars|max_execution_time|memory_limit)"

# Проверить логи Laravel
tail -f storage/logs/laravel.log
```

