# 🚨 СРОЧНОЕ ИСПРАВЛЕНИЕ - 413 ошибка все еще есть

## ⚠️ Проблема

**413 (Request Entity Too Large)** продолжается - значит изменения в php.ini НЕ применены или применены к неправильному файлу.

## ✅ Выполните ЭТИ команды на сервере

### Шаг 1: Найдите правильный php.ini для FPM

```bash
# Узнайте версию PHP
php -v

# Найдите ВСЕ php.ini файлы
sudo find /etc/php -name "php.ini" -type f

# Скорее всего увидите:
# /etc/php/8.2/fpm/php.ini
# /etc/php/8.2/cli/php.ini
```

**ВАЖНО**: Изменять нужно файл для **FPM**, а НЕ для CLI!

### Шаг 2: Откройте ПРАВИЛЬНЫЙ файл

```bash
# Замените 8.2 на вашу версию PHP
sudo nano /etc/php/8.2/fpm/php.ini
```

**Найдите эти строки** (используйте Ctrl+W для поиска):
- `post_max_size`
- `upload_max_filesize`
- `max_input_vars`

**Измените на**:
```ini
post_max_size = 256M
upload_max_filesize = 256M
max_input_vars = 5000
memory_limit = 512M
max_execution_time = 600
```

**Сохраните**: Ctrl+O, Enter, Ctrl+X

### Шаг 3: Перезапустите PHP-FPM

```bash
# Найдите активную версию
sudo systemctl list-units | grep php-fpm

# Перезапустите (замените версию)
sudo systemctl restart php8.2-fpm
sudo systemctl restart php8.1-fpm  # На всякий случай
sudo systemctl restart php8.0-fpm  # На всякий случай

# Или все разом
sudo systemctl restart php*-fpm
```

### Шаг 4: Проверьте что применилось

```bash
# Создайте тестовый файл
sudo nano /var/www/api.ss.ru/public/test-php.php
```

Содержимое:
```php
<?php
echo "PHP Version: " . phpversion() . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "max_input_vars: " . ini_get('max_input_vars') . "\n";
phpinfo();
?>
```

Откройте в браузере:
```
https://api.skateandsnow-test.ru/test-php.php
```

**Должно показать**:
- `post_max_size: 256M`
- `upload_max_filesize: 256M`
- `max_input_vars: 5000`

### Шаг 5: Если показывает старые значения

```bash
# Перезагрузите веб-сервер полностью
sudo systemctl restart nginx
sudo systemctl restart apache2

# Или если используете другой сервер
sudo systemctl restart php*-fpm
sudo service nginx restart
sudo service apache2 restart
```

### Шаг 6: Проверьте Nginx конфиг

```bash
# Найдите конфиг сайта
sudo nano /etc/nginx/sites-available/api.skateandsnow-test.ru
# Или ваш конфиг

# Добавьте ВНУТРИ блока server {}:
client_max_body_size 256M;

# Проверьте конфигурацию
sudo nginx -t

# Перезагрузите
sudo systemctl reload nginx
```

## 🔥 Альтернативное решение (если ничего не помогает)

### Вариант 1: Уменьшите размер батча

На фронтенде уменьшите количество товаров за раз:

```javascript
// В GoodsImport.vue
const BATCH_SIZE = 20; // Попробуйте уменьшить с 50-100 до 20-30
```

### Вариант 2: Используйте .htaccess

```bash
cd /var/www/api.ss.ru/public
sudo nano .htaccess
```

Добавьте:
```apache
php_value post_max_size 256M
php_value upload_max_filesize 256M
php_value max_input_vars 5000
php_value memory_limit 512M
```

Это переопределит настройки php.ini для этого сайта.

## 📊 Диагностика

### Команды для проверки:

```bash
# 1. Какие PHP-FPM сервисы запущены?
sudo systemctl list-units | grep php

# 2. Какие php.ini файлы существуют?
find /etc/php -name php.ini -type f

# 3. Какой php.ini использует CLI?
php --ini

# 4. Какие настройки активны в CLI?
php -i | grep post_max_size

# 5. Проверьте логи PHP-FPM
sudo tail -f /var/log/php8.2-fpm.log
# или
sudo journalctl -u php8.2-fpm -f
```

## ⚡ Быстрое решение (TEMPORARY)

Если срочно нужно импортировать, добавьте в `.htaccess`:

```bash
cd /var/www/api.ss.ru/public
cat >> .htaccess << 'EOF'

# Temporary CORS and size fix
<IfModule mod_headers.c>
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, PATCH, OPTIONS"
    Header set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With, Accept, Origin"
</IfModule>

php_value post_max_size 256M
php_value upload_max_filesize 256M
php_value max_input_vars 5000
php_value memory_limit 512M
EOF

# Перезагрузите Apache (если используется)
sudo systemctl restart apache2
```

## ✅ Финальная проверка

```bash
# 1. Проверьте test-php.php в браузере - должны быть новые значения

# 2. Попробуйте импорт

# 3. Смотрите логи:
tail -f /var/www/api.ss.ru/storage/logs/laravel.log

# Должно появиться:
# local.INFO: CustomCors middleware
```

Если записи от CustomCors middleware появляются - значит запрос доходит до Laravel!

