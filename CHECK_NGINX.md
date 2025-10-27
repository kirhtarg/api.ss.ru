# 🔍 Проверка Nginx конфигурации

## Проблема

PHP настройки правильные, но 413 ошибка продолжается - значит **Nginx блокирует запрос**.

## ✅ Выполните на сервере

### 1. Проверьте client_max_body_size в Nginx

```bash
# Найдите конфиг для вашего сайта
sudo nano /etc/nginx/sites-available/api.skateandsnow-test.ru
# или
sudo nano /etc/nginx/conf.d/api.skateandsnow-test.ru.conf

# ИЛИ все конфиги сразу
sudo nginx -T | grep "client_max_body_size"
```

Должно быть:
```nginx
client_max_body_size 256M;
```

**Если НЕТ** или меньше - добавьте в блок `server {}`:
```nginx
server {
    listen 443 ssl;
    server_name api.skateandsnow-test.ru;
    
    # ДОБАВЬТЕ ЭТУ СТРОКУ
    client_max_body_size 256M;
    
    root /var/www/api.ss.ru/public;
    ...
}
```

### 2. Сохраните и перезагрузите Nginx

```bash
# Проверьте конфиг
sudo nginx -t

# Перезагрузите (не restart, а reload!)
sudo systemctl reload nginx

# Или если не работает:
sudo systemctl restart nginx
```

### 3. Проверьте что изменения применены

```bash
# Проверьте размер через Nginx
sudo nginx -T | grep -A 5 "api.skateandsnow-test.ru" | grep client_max_body_size
```

### 4. Если все еще 413 - временно попробуйте УВЕЛИЧИТЬ

```bash
sudo nano /etc/nginx/sites-available/api.skateandsnow-test.ru
```

Измените на:
```nginx
client_max_body_size 512M;  # Попробуйте увеличить еще больше
```

```bash
sudo systemctl reload nginx
```

## 🎯 Альтернатива: Уменьшите размер батча

Если Nginx настроен неправильно или изменения не применяются, **временно уменьшите размер батча** на фронтенде.

### На фронтенде (GoodsImport.vue):

Найдите где определяется размер батча и уменьшите:
```javascript
// Было возможно:
const BATCH_SIZE = 50;
// или
const BATCH_SIZE = 100;

// Измените на:
const BATCH_SIZE = 20;  // Меньше товаров за раз
```

Это позволит импортировать товары порциями по 20 штук вместо 50-100.

## 📊 Быстрая диагностика

```bash
# 1. Проверьте Nginx конфиг
sudo nginx -T | grep client_max_body_size

# 2. Проверьте логи Nginx
sudo tail -f /var/log/nginx/error.log

# 3. Попробуйте импорт и смотрите что в логах
```

## ✅ Чеклист

- [ ] `max_input_vars` = 5000 ✅ (уже сделано)
- [ ] `post_max_size` = 256M ✅ (уже сделано)
- [ ] Nginx `client_max_body_size` = 256M или больше ⚠️
- [ ] Nginx перезагружен после изменений
- [ ] Попробован импорт - не 413 ошибка?

## 🔥 Если ничего не помогает

Попробуйте импорт с 1 товаром - работает?

```javascript
// Временно в GoodsImport.vue
const BATCH_SIZE = 1;  // Тест с 1 товаром
```

Если с 1 товаром работает, но с 20 - не работает, значит проблема в размере запроса. Уменьшите батч или увеличьте лимиты еще больше.

