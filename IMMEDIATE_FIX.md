# 🚨 НЕМЕДЛЕННОЕ ИСПРАВЛЕНИЕ CORS и 413

## 📊 Диагноз

Из логов видно что:
1. ❌ **Нет записей от `CustomCors middleware`** - запрос не доходит до Laravel
2. ❌ **Запрос блокируется на уровне PHP/Nginx** (413 ошибка)
3. ❌ Middleware не вызывается, потому что PHP отклоняет запрос ДО Laravel

## ✅ Немедленные действия

### Шаг 1: Проверьте PHP настройки

На сервере выполните:
```bash
php -i | grep -E "(post_max_size|upload_max_filesize|max_input_vars)"
```

**Проблема**: Скорее всего `post_max_size` меньше размера вашего запроса (например, 8M вместо 256M)

### Шаг 2: Обновите php.ini

```bash
# Найдите активный php.ini
php --ini

# Например, это может быть:
sudo nano /etc/php/8.2/fpm/php.ini
# или
sudo nano /etc/php/8.1/fpm/php.ini
```

Измените:
```ini
post_max_size = 256M
upload_max_filesize = 256M
max_input_vars = 5000
memory_limit = 512M
max_execution_time = 600
```

### Шаг 3: Перезапустите PHP-FPM

```bash
# Найдите правильное имя сервиса
sudo systemctl list-units | grep php

# Перезапустите (замените 8.2 на вашу версию)
sudo systemctl restart php8.2-fpm

# Или если Apache
sudo systemctl restart apache2
```

### Шаг 4: Обновите Nginx (если используется)

```bash
sudo nano /etc/nginx/sites-available/api.skateandsnow-test.ru
# или ваш конфиг

# Добавьте:
client_max_body_size 256M;

# Перезапустите
sudo systemctl restart nginx
```

### Шаг 5: Очистите кэш Laravel

```bash
cd /path/to/api.ss.ru
php artisan optimize:clear
```

### Шаг 6: Проверьте тест CORS

Откройте в браузере:
```
https://api.skateandsnow-test.ru/api/test-cors.php
```

**Должно вернуть JSON** с настройками PHP. Если возвращает - значит файл работает.

### Шаг 7: Попробуйте снова import

Теперь попробуйте импорт товаров. В логах должно появиться:
```bash
tail -f storage/logs/laravel.log
```

Ожидаемое:
```
local.INFO: CustomCors middleware {"method":"POST","path":"api/admin/shop/goods/bulk-import",...
```

## 🔍 Диагностика

Если `test-cors.php` не работает, проверьте:

```bash
# 1. Проверьте размер контента запроса
# В браузере DevTools -> Network -> смотрите Size столбец

# 2. Проверьте текущие лимиты PHP
php -i | grep post_max_size

# 3. Проверьте что изменения применены
php --ini
# Смотрите на "Loaded Configuration File"

# 4. Проверьте логи Nginx (если используется)
sudo tail -f /var/log/nginx/error.log
```

## 🎯 Почему это происходит

1. **Bulk import отправляет МНОГО данных** (массив товаров с множеством полей)
2. **Размер запроса превышает `post_max_size`** в php.ini
3. **PHP отклоняет запрос** ДО того как он попадает в Laravel
4. **Браузер получает 413** без CORS заголовков
5. **Middleware НЕ вызывается** потому что Laravel не получает запрос

## ⚡ Быстрое решение (временное)

Если не можете изменить php.ini прямо сейчас, **уменьшите размер батча** на фронтенде:

```javascript
// В GoodsImport.vue измените:
const BATCH_SIZE = 50; // было может быть 100 или больше
```

Это временное решение, но позволит импортировать товары меньшими порциями.

## ✅ Постоянное решение

После применения изменений в php.ini запрос будет проходить и middleware CustomCors будет добавлять нужные заголовки.

## 🧪 Проверка что все работает

```bash
# 1. Проверьте PHP настройки
php -i | grep post_max_size
# Должно быть: post_max_size => 256M => 256M

# 2. Проверьте логи Laravel
tail -f storage/logs/laravel.log
# Попробуйте импорт и смотрите логи

# 3. Проверьте в браузере
# Откройте DevTools -> Network -> смотрите что запрос не возвращает 413
```

## 📝 Итог

**Проблема НЕ в CORS**, проблема в том что запрос блокируется ДО Laravel.
**Решение**: Увеличить `post_max_size` в php.ini и перезапустить PHP-FPM.

После этого CORS middleware начнет работать и добавятся нужные заголовки.

