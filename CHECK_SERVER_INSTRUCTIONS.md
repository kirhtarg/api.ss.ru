# 📋 Инструкция для проверки на сервере

## Проблема

PHP файл скачивается вместо выполнения - значит либо файлы не на сервере, либо PHP не настроен.

## ✅ Выполните на сервере

### 1. Найдите корневую директорию проекта

```bash
# Обычно это
cd /var/www/api.ss.ru
# или
cd /home/user/api.ss.ru
# или как настроено

# Проверьте структуру
ls -la
# Должны быть директории: app, public, config и т.д.
```

### 2. Создайте тестовый файл ВРУЧНУЮ на сервере

```bash
cd /var/www/api.ss.ru/public

# Создайте файл
cat > test-info.php << 'EOF'
<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP Version: " . phpversion() . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "max_input_vars: " . ini_get('max_input_vars') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
phpinfo();
EOF
```

### 3. Проверьте что файл создан

```bash
ls -la test-info.php
cat test-info.php
```

### 4. Откройте в браузере

```
https://api.skateandsnow-test.ru/test-info.php
```

Если файл все еще скачивается, значит PHP не обрабатывает .php файлы - проблема в конфигурации Nginx/Apache.

### 5. Проверьте конфигурацию Nginx

```bash
# Найдите конфиг
sudo nano /etc/nginx/sites-available/api.skateandsnow-test.ru
# или
sudo nano /etc/nginx/conf.d/api.skateandsnow-test.ru.conf
```

**Должно быть**:
```nginx
server {
    listen 443 ssl;
    server_name api.skateandsnow-test.ru;
    root /var/www/api.ss.ru/public;  # ПРОВЕРЬТЕ ПУТЬ!

    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 6. Если Laravel Rewrite не работает, используйте прямой URL

```bash
# Создайте файл БЕЗ Laravel routing
cat > /var/www/api.ss.ru/public/test-direct.php << 'EOF'
<?php
phpinfo();
?>
```

Попробуйте:
```
https://api.skateandsnow-test.ru/test-direct.php
```

Если это работает, значит проблема в Laravel routing. Используйте прямой путь.

## 🔍 Альтернативный метод проверки

### Через командную строку на сервере:

```bash
# Проверьте PHP настройки через CLI (НЕ для веб-сервера!)
php -i | grep post_max_size
php -i | grep max_input_vars

# Проверьте через веб-сервер
php -r "var_export(ini_get_all());" | grep -E "(post_max_size|max_input_vars)"
```

### Через Laravel команду (если доступен):

```bash
cd /var/www/api.ss.ru
php artisan tinker

# В tinker:
ini_get('post_max_size')
ini_get('upload_max_filesize')
ini_get('max_input_vars')
```

## ⚡ Быстрое решение через .htaccess

Если файл в `public/` директории:

```bash
cd /var/www/api.ss.ru/public

# Создайте или отредактируйте .htaccess
cat >> .htaccess << 'EOF'

# Temporary PHP settings
php_value post_max_size 256M
php_value upload_max_filesize 256M
php_value max_input_vars 5000
php_value memory_limit 512M
php_value max_execution_time 600
EOF
```

**Важно**: .htaccess работает только для Apache, для Nginx нужно менять конфиг!

## 📊 Проверка что работает

После создания файла на сервере:

```bash
# 1. Проверьте что файл есть
ls -la /var/www/api.ss.ru/public/test-info.php

# 2. Проверьте права
chmod 644 /var/www/api.ss.ru/public/test-info.php

# 3. Попробуйте открыть в браузере
# https://api.skateandsnow-test.ru/test-info.php
```

Если все еще скачивается - проблема в веб-сервере конфигурации.

## 🆘 Если ничего не помогает

Проверьте через SSH что PHP-FPM запущен:

```bash
# Проверьте статус PHP-FPM
sudo systemctl status php*-fpm

# Проверьте какие pool'ы есть
ls -la /etc/php/*/fpm/pool.d/

# Проверьте логи
sudo tail -f /var/log/php*-fpm.log
```

