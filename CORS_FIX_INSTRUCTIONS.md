# Инструкция по исправлению CORS для тестового домена

## Проблема
CORS блокирует запросы с `https://skateandsnow-test.ru` на API `https://api.skateandsnow-test.ru/api/admin/shop/goods/bulk-import`

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

### 1. Обновите файл .env на сервере
Добавьте или обновите переменные:
```bash
CORS_ALLOWED_ORIGINS=https://ss75.kirhtarg.ru,http://ss75.kirhtarg.ru,https://admin.skateandsnow.ru,http://admin.skateandsnow.ru,https://skateandsnow-test.ru,https://admin.skateandsnow-test.ru,https://api.skateandsnow-test.ru,https://skateandsnow.ru,https://admin.skateandsnow.ru,https://api.skateandsnow.ru
CORS_ALLOWED_PATTERNS=*\.kirhtarg\.ru$,*\.skateandsnow\.ru$,*\.skateandsnow-test\.ru$,localhost:\d+,127\.0\.0\.1:\d+
```

### 2. Выполните команды на сервере в директории api.ss.ru:

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

### 3. Проверка
После применения изменений проверьте:
1. Откройте DevTools в браузере (F12)
2. Перейдите на вкладку Network
3. Попробуйте сделать запрос
4. Проверьте, что в Headers ответа есть:
   - `Access-Control-Allow-Origin: https://skateandsnow-test.ru`
   - `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS`
   - `Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID`

### 4. Если проблема сохраняется:
Проверьте логи на сервере:
```bash
tail -f storage/logs/laravel.log
```

Также убедитесь, что в конфигурации веб-сервера (nginx/apache) не блокируются OPTIONS запросы.

## Важные моменты

1. **Изменения в коде уже внесены** - нужно только применить их на сервере
2. **Очистка кэша обязательна** - иначе изменения не применятся
3. **Проверьте .env на сервере** - убедитесь, что там добавлены тестовые домены
4. **Паттерн `*\.skateandsnow-test\.ru$` уже работает** - он покрывает subdomains

## Дополнительно

Если используете nginx, убедитесь что в конфигурации есть:
```nginx
# Обработка CORS preflight запросов
location / {
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' $http_origin always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, PATCH, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
        add_header 'Access-Control-Max-Age' '86400' always;
        return 204;
    }
}
```

Однако Laravel должен обрабатывать это сам, так что обычно это не требуется.

