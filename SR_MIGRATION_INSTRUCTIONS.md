# Инструкция по выполнению миграций для модуля SR

## Проблема
Ошибка 500: `Table 'ss_db.sr_cards' doesn't exist`

Это означает, что миграции для таблиц SR не были выполнены.

## Решение

### На локальном сервере выполните:

```bash
cd api.ss.ru
php artisan migrate
```

Эта команда выполнит все новые миграции, включая:
- `2026_01_27_000001_create_sr_categories_table.php`
- `2026_01_27_000002_create_sr_cards_table.php`

### Проверка выполнения миграций

После выполнения миграций проверьте:

```bash
php artisan migrate:status
```

Должны быть видны миграции:
- `2026_01_27_000001_create_sr_categories_table` - Ran
- `2026_01_27_000002_create_sr_cards_table` - Ran

### Проверка таблиц в базе данных

Можно проверить через MySQL:

```sql
SHOW TABLES LIKE 'sr_%';
```

Должны быть видны:
- `sr_categories`
- `sr_cards`

Или через Laravel Tinker:

```bash
php artisan tinker
>>> Schema::hasTable('sr_categories')
=> true
>>> Schema::hasTable('sr_cards')
=> true
```

## Если миграции не выполняются

1. **Проверьте подключение к БД:**
   ```bash
   php artisan db:show
   ```

2. **Проверьте, что файлы миграций существуют:**
   ```bash
   ls -la database/migrations/2026_01_27_*
   ```

3. **Если есть ошибки, проверьте логи:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

## После выполнения миграций

После успешного выполнения миграций модуль SR должен работать. Проверьте:

1. Откройте `/cms/sr` в админке
2. Попробуйте загрузить категории и карты
3. Проверьте, что нет ошибок в консоли браузера
