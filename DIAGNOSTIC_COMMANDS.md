# 🔍 Диагностика проблемы

## Выполните эти команды на сервере

### 1. Проверьте что max_input_vars изменился в php.ini

```bash
# Проверьте ТЕКУЩЕЕ значение в php.ini
grep "max_input_vars" /etc/php/8.4/fpm/php.ini

# Должно показать:
# max_input_vars = 5000
# (а не max_input_vars = 1000)

# Если показывает 1000 - значит файл НЕ сохранен!
```

### 2. Если значение еще 1000, измените:

```bash
# ВАРИАНТ 1: Через sed (автоматически)
sudo sed -i 's/max_input_vars = 1000/max_input_vars = 5000/' /etc/php/8.4/fpm/php.ini

# ВАРИАНТ 2: Вручную
sudo nano /etc/php/8.4/fpm/php.ini
# Найдите: max_input_vars = 1000
# Измените на: max_input_vars = 5000
# Сохраните: Ctrl+O, Enter, Ctrl+X
```

### 3. Перезапустите PHP-FPM (ОБЯЗАТЕЛЬНО!)

```bash
# Найдите правильное имя сервиса
sudo systemctl list-units | grep php-fpm

# Перезапустите
sudo systemctl restart php8.4-fpm

# Проверьте что перезапустился
sudo systemctl status php8.4-fpm
```

### 4. Очистите кэш Laravel

```bash
cd /var/www/api.ss.ru
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

### 5. Проверьте что изменилось

```bash
# Откройте в браузере:
# https://api.skateandsnow-test.ru/api/debug/php-info

# ИЛИ через curl:
curl https://api.skateandsnow-test.ru/api/debug/php-info
```

Должно быть:
```json
"max_input_vars": "5000"
```

## 🚨 Если все еще показывает 1000

### Проверьте что правильный php.ini

```bash
# Найти ВСЕ php.ini файлы
find /etc/php -name "php.ini" -type f

# Возможно их несколько:
# /etc/php/8.4/fpm/php.ini
# /etc/php/8.4/cli/php.ini
# /etc/php/8.2/fpm/php.ini

# ИЗМЕНИТЬ нужно FPM версию!

# Проверьте какую версию использует веб-сервер
php -v
```

### Проверьте через веб-интерфейс

```bash
# Создайте тестовый файл
echo '<?php var_dump(ini_get("max_input_vars")); ?>' > /var/www/api.ss.ru/public/test-var.php
```

Откройте: `https://api.skateandsnow-test.ru/test-var.php`

Должно показать: `string(4) "5000"`

## ⚡ Принудительное решение

Если ничего не помогает, используйте .htaccess (только для Apache):

```bash
cd /var/www/api.ss.ru/public

cat >> .htaccess << 'EOF'

# Force PHP settings
php_value max_input_vars 5000
php_value post_max_size 256M
php_value upload_max_filesize 256M
php_value memory_limit 512M
EOF

# Перезагрузите Apache
sudo systemctl restart apache2
```

**ОБЯЗАТЕЛЬНО перезагрузите веб-сервер после изменений!**

## 📊 Быстрая проверка всего

```bash
#!/bin/bash
echo "=== PHP Version ==="
php -v

echo ""
echo "=== PHP-FPM Status ==="
sudo systemctl status php8.4-fpm | grep Active

echo ""
echo "=== php.ini Settings ==="
grep max_input_vars /etc/php/8.4/fpm/php.ini

echo ""
echo "=== Current Active Value ==="
php -r "echo ini_get('max_input_vars');"

echo ""
echo "=== Check via Web ==="
echo "Open: https://api.skateandsnow-test.ru/api/debug/php-info"
```

