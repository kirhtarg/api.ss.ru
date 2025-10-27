# ✅ Проверка что изменения применены

## Важно: Нужно проверить именно FPM версию PHP!

Есть ДВА разных php.ini:
- CLI (командная строка): `php --ini`
- FPM (веб-сервер): `/etc/php/8.x/fpm/php.ini`

**Изменить нужно FPM версию!**

## 🔍 Проверка

### 1. Проверьте какой php.ini используется веб-сервером:

```bash
# Создайте файл test-phpinfo.php в public/
echo '<?php phpinfo(); ?>' > public/test-phpinfo.php
```

Откройте в браузере:
```
https://api.skateandsnow-test.ru/test-phpinfo.php
```

Найдите:
- **"post_max_size"** - должно быть 256M
- **"upload_max_filesize"** - должно быть 256M
- **"max_input_vars"** - должно быть 5000

### 2. Командная строка для проверки:

```bash
# Проверьте FPM php.ini (ПРАВИЛЬНО)
cat /etc/php/8.2/fpm/php.ini | grep post_max_size

# Или если другая версия:
cat /etc/php/8.1/fpm/php.ini | grep post_max_size
cat /etc/php/8.0/fpm/php.ini | grep post_max_size
```

### 3. Проверьте что изменено в php.ini:

```bash
# Найдите строку
grep "post_max_size" /etc/php/8.2/fpm/php.ini

# Должно быть:
# post_max_size = 256M

# НЕ должно быть:
# post_max_size = 8M
# post_max_size = 128M
```

### 4. После изменения ОБЯЗАТЕЛЬНО перезапустите:

```bash
# Найдите версию PHP
php -v

# Перезапустите правильную версию
sudo systemctl restart php8.2-fpm
sudo systemctl restart php8.1-fpm
sudo systemctl restart php8.0-fpm

# Или все версии подряд на всякий случай:
sudo systemctl restart php*-fpm
```

### 5. Проверьте что изменения применились:

```bash
# Проверьте через CLI (не показывает FPM настройки точно)
php -i | grep post_max_size

# Проверьте через веб-сервер (ПРАВИЛЬНО)
curl -s http://localhost/test-phpinfo.php | grep post_max_size
```

## 🚨 Если изменения НЕ применились

### Вариант 1: Изменили не тот файл

```bash
# Найдите ВСЕ php.ini файлы
find /etc/php -name php.ini

# Скорее всего их несколько
# Измените ТОТ который для FPM
nano /etc/php/8.2/fpm/php.ini
```

### Вариант 2: PHP-FPM не перезапущен

```bash
# Проверьте статус
sudo systemctl status php8.2-fpm

# Перезапустите
sudo systemctl restart php8.2-fpm

# Проверьте что перезапустился
sudo systemctl status php8.2-fpm
```

### Вариант 3: Используете другой PHP-FPM pool

```bash
# Проверьте какие pools активны
sudo systemctl list-units | grep php-fpm

# Перезапустите все
sudo systemctl restart php*-fpm
```

## 📊 Быстрая проверка всего сразу

```bash
# Скрипт для полной проверки
cat > /tmp/check-php.sh << 'EOF'
#!/bin/bash
echo "=== PHP CLI Settings ==="
php -i | grep -E "(post_max_size|upload_max_filesize|max_input_vars)"

echo ""
echo "=== PHP-FPM Status ==="
sudo systemctl status php*-fpm | grep -E "(Active|running)"

echo ""
echo "=== PHP.ini Location ==="
php --ini

echo ""
echo "=== Current Settings in php.ini ==="
FPM_INI=$(php --ini | grep "Loaded Configuration File" | awk '{print $5}' | head -1)
if [ -n "$FPM_INI" ]; then
    grep -E "(post_max_size|upload_max_filesize|max_input_vars|memory_limit)" $FPM_INI
fi
EOF

chmod +x /tmp/check-php.sh
bash /tmp/check-php.sh
```

## ✅ Правильная последовательность

1. **Изменить** `/etc/php/8.x/fpm/php.ini`
2. **Сохранить** изменения
3. **Перезапустить** `sudo systemctl restart php8.x-fpm`
4. **Проверить** через test-phpinfo.php в браузере
5. **Попробовать** импорт снова

## 🔥 Если ничего не помогает - используйте альтернативу

Уменьшите размер батча на фронтенде - это временное решение пока не исправите php.ini.

