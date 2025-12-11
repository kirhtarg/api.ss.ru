# Отладка проблемы с созданием записей AbsentPromocodeUsage

## Проблема
Запись для незарегистрированного пользователя не добавляется в таблицу `absent_promocode_usages`.

## Шаги для отладки

### 1. Проверка структуры таблицы

Выполните на сервере SQL запрос из файла `check_absent_promocode_usages_table.sql`:

```bash
mysql -u your_username -p your_database < check_absent_promocode_usages_table.sql
```

Или выполните вручную через phpMyAdmin или MySQL клиент:

```sql
DESCRIBE absent_promocode_usages;
```

**Ожидаемый результат:**
- Поле `user_id` должно быть `BIGINT UNSIGNED NULL`
- Поле `ip_address` должно существовать и быть `VARCHAR(45) NULL`

### 2. Проверка логов Laravel

Проверьте логи на сервере:

```bash
tail -f storage/logs/laravel.log
```

При попытке создать промокод должны появиться записи:
- `Создание записи AbsentPromocodeUsage` - с данными `usage_data`
- `Запись AbsentPromocodeUsage успешно создана` - с ID созданной записи
- Или `Ошибка при создании записи AbsentPromocodeUsage` - с описанием ошибки

### 3. Проверка миграции

Проверьте, что миграция была применена:

```bash
php artisan migrate:status | grep absent_promocode
```

### 4. Если миграция не применилась или применилась некорректно

Выполните SQL скрипт из файла `fix_absent_promocode_usages_table.sql`:

```bash
mysql -u your_username -p your_database < fix_absent_promocode_usages_table.sql
```

**ВНИМАНИЕ:** Сначала сделайте резервную копию базы данных!

### 5. Проверка через tinker

Попробуйте создать запись вручную через tinker:

```bash
php artisan tinker
```

```php
$usage = \App\Models\AbsentPromocodeUsage::create([
    'user_id' => null,
    'ip_address' => '127.0.0.1',
    'good_id' => 1,
    'promocode_id' => 1,
]);
```

Если это не работает, вы увидите ошибку, которая поможет понять проблему.

### 6. Возможные проблемы и решения

#### Проблема: Foreign key блокирует создание записей с null

**Решение:** Foreign key на nullable колонку в MySQL должен работать корректно. Если нет, выполните:

```sql
ALTER TABLE absent_promocode_usages DROP FOREIGN KEY absent_promocode_usages_user_id_foreign;
ALTER TABLE absent_promocode_usages 
ADD CONSTRAINT absent_promocode_usages_user_id_foreign 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
```

#### Проблема: Поле ip_address не существует

**Решение:** Выполните:

```sql
ALTER TABLE absent_promocode_usages 
ADD COLUMN ip_address VARCHAR(45) NULL AFTER user_id;
```

#### Проблема: user_id не может быть NULL

**Решение:** Выполните:

```sql
ALTER TABLE absent_promocode_usages MODIFY COLUMN user_id BIGINT UNSIGNED NULL;
```

### 7. Проверка после исправления

После исправления структуры таблицы:

1. Очистите кэш Laravel:
```bash
php artisan config:clear
php artisan cache:clear
```

2. Попробуйте создать промокод снова
3. Проверьте логи
4. Проверьте, что запись появилась в таблице:

```sql
SELECT * FROM absent_promocode_usages ORDER BY id DESC LIMIT 5;
```














