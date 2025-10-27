# ✅ ИСПРАВЛЕНИЕ: max_input_vars слишком мало

## Проблема

Из проверки видно:
```json
"max_input_vars": "1000"
```

**1000 переменных - этого мало** для импорта большого количества товаров с множеством полей!

## ✅ Исправление

### На сервере выполните:

```bash
# 1. Откройте php.ini
sudo nano /etc/php/8.4/fpm/php.ini

# 2. Найдите строку (Ctrl+W для поиска):
# max_input_vars = 1000

# 3. Измените на:
# max_input_vars = 5000

# 4. Сохраните (Ctrl+O, Enter, Ctrl+X)

# 5. Перезапустите PHP-FPM
sudo systemctl restart php8.4-fpm

# 6. Проверьте
curl https://api.skateandsnow-test.ru/api/debug/php-info
```

Должно стать:
```json
"max_input_vars": "5000"
```

## 🔍 Быстрая команда

```bash
# Измените одной командой
sudo sed -i 's/^;\?max_input_vars = .*/max_input_vars = 5000/' /etc/php/8.4/fpm/php.ini

# Перезапустите
sudo systemctl restart php8.4-fpm

# Проверьте
curl https://api.skateandsnow-test.ru/api/debug/php-info | grep max_input_vars
```

## ✅ После исправления

1. **Проверьте** что `max_input_vars` = 5000
2. **Попробуйте импорт** снова
3. **Проверьте логи**: `tail -f storage/logs/laravel.log`
   - Должно появиться: `CustomCors middleware`

## 🎯 Если все равно 413

Уменьшите размер батча на фронтенде:
```javascript
// В GoodsImport.vue
const BATCH_SIZE = 20; // Вместо 50-100
```

Это временное решение, но позволит импортировать товары.

