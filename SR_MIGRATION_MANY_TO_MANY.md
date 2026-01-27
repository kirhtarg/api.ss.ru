# Миграция SR модуля на many-to-many связь

## Шаги для применения изменений

### 1. Запустить миграцию pivot таблицы

```bash
php artisan migrate
```

Это создаст таблицу `sr_card_category` для связи many-to-many между картами и категориями.

### 2. Миграция существующих данных (если есть)

Если в таблице `sr_cards` уже есть записи с `category_id`, нужно перенести их в pivot таблицу:

```sql
-- Перенос существующих связей
INSERT INTO sr_card_category (sr_card_id, sr_category_id, created_at, updated_at)
SELECT id, category_id, NOW(), NOW()
FROM sr_cards
WHERE category_id IS NOT NULL;
```

### 3. Очистка кэша

```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
```

## Изменения в структуре

### До:
- `sr_cards.category_id` - одна категория на карту

### После:
- `sr_card_category` - pivot таблица для many-to-many связи
- Одна карта может иметь несколько категорий
- Одна категория может быть привязана к нескольким картам

## Откат (если нужно)

Если нужно откатить изменения:

```sql
-- Перенос обратно в category_id (берем первую категорию)
UPDATE sr_cards c
SET category_id = (
    SELECT sr_category_id 
    FROM sr_card_category 
    WHERE sr_card_id = c.id 
    LIMIT 1
);
```

Затем удалить pivot таблицу:
```bash
php artisan migrate:rollback --step=1
```
