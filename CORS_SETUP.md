# Настройка CORS для API

## Обзор

CORS (Cross-Origin Resource Sharing) настроен для работы с разными доменами. Конфигурация поддерживает как точные домены, так и паттерны для поддоменов через переменные окружения.

## Настройка через переменные окружения

### В файле .env добавьте:

```env
# CORS настройки (через запятую, без пробелов)
CORS_ALLOWED_ORIGINS=https://yourdomain.com,http://yourdomain.com,https://api.yourdomain.com
CORS_ALLOWED_PATTERNS=*\.yourdomain\.ru$,*\.yourdomain\.com$,localhost:\d+,127\.0\.0\.1:\d+
```

### Примеры конфигурации:

#### Для одного домена:
```env
CORS_ALLOWED_ORIGINS=https://mysite.com,http://mysite.com
CORS_ALLOWED_PATTERNS=
```

#### Для поддоменов:
```env
CORS_ALLOWED_ORIGINS=
CORS_ALLOWED_PATTERNS=*\.mysite\.com$,*\.mysite\.ru$
```

#### Для локальной разработки:
```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
CORS_ALLOWED_PATTERNS=localhost:\d+,127\.0\.0\.1:\d+
```

## Паттерны

Поддерживаемые паттерны регулярных выражений:

- `*\.domain\.com$` - все поддомены domain.com
- `localhost:\d+` - localhost с любым портом
- `127\.0\.0\.1:\d+` - 127.0.0.1 с любым портом
- `.*\.example\.ru$` - все поддомены example.ru

## Применение изменений

После изменения настроек CORS:

1. Обновите файл .env с новыми доменами
2. Очистите кэш конфигурации:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

## Тестирование

Для проверки работы CORS используйте:

- `GET /api/test-cors` - тестовый endpoint
- `GET /api/public/site-info` - основной API

## Безопасность

⚠️ **Важно**: Не используйте `*` в allowed_origins для продакшена. Всегда указывайте конкретные домены.

## Поддержка

Если нужно добавить новый домен:

1. Добавьте домен в `CORS_ALLOWED_ORIGINS` (для точного совпадения)
2. Или добавьте паттерн в `CORS_ALLOWED_PATTERNS` (для поддоменов)
3. Очистите кэш конфигурации

## Примеры для разных сценариев

### API сервер на другом домене

Если API будет на `api.example.com`, а фронтенд на `app.example.com`:

```env
CORS_ALLOWED_ORIGINS=https://app.example.com,http://app.example.com
CORS_ALLOWED_PATTERNS=*\.example\.com$
```

### Несколько доменов

```env
CORS_ALLOWED_ORIGINS=https://site1.com,https://site2.com,https://admin.site1.com
CORS_ALLOWED_PATTERNS=*\.site1\.com$,*\.site2\.com$
```

### Только поддомены

```env
CORS_ALLOWED_ORIGINS=
CORS_ALLOWED_PATTERNS=*\.yourdomain\.com$,*\.yourdomain\.ru$
```
