# Инструкция по миграции таблиц site_menus и site_menu_items

## Обзор

Созданы новые миграции для таблиц `site_menus` и `site_menu_items` в правильном порядке, а также сидеры для заполнения данными из дампа.

## Новые файлы

### Миграции:
- `2025_09_04_000001_create_site_menus_table.php` - создание таблицы site_menus
- `2025_09_04_000002_create_site_menu_items_table.php` - создание таблицы site_menu_items

### Сидеры:
- `SiteMenuSeeder.php` - заполнение таблицы site_menus
- `SiteMenuItemSeeder.php` - заполнение таблицы site_menu_items

## Выполнение на удаленном сервере

### 1. Обновить код
```bash
git pull origin main
```

### 2. Выполнить fresh миграции
```bash
php artisan migrate:fresh --seed
```

**Внимание**: Эта команда удалит все данные из базы и пересоздаст все таблицы с нуля!

### 3. Альтернативный вариант (если нужно сохранить данные)

Если нужно сохранить существующие данные, выполните пошагово:

```bash
# Создать резервную копию
mysqldump -u username -p database_name > backup_$(date +%Y%m%d_%H%M%S).sql

# Удалить старые таблицы (если они есть)
php artisan tinker
>>> Schema::dropIfExists('site_menu_items');
>>> Schema::dropIfExists('site_menus');
>>> exit

# Выполнить новые миграции
php artisan migrate

# Заполнить данными
php artisan db:seed --class=SiteMenuSeeder
php artisan db:seed --class=SiteMenuItemSeeder
```

## Структура таблиц

### site_menus
- `id` - первичный ключ
- `name` - название меню
- `description` - описание меню
- `is_active` - активно ли меню
- `template_name` - название файла шаблона
- `settings` - настройки в JSON
- `sort_order` - порядок сортировки
- `created_at`, `updated_at` - временные метки

### site_menu_items
- `id` - первичный ключ
- `site_menu_id` - ссылка на site_menus (nullable)
- `title` - название пункта меню
- `url` - URL пункта меню
- `parent_id` - ссылка на родительский пункт меню
- `sort_order` - порядок сортировки
- `is_active` - активен ли пункт меню
- `target` - цель ссылки (_self, _blank)
- `attributes` - дополнительные атрибуты в JSON
- `created_at`, `updated_at` - временные метки

## Внешние ключи

- `site_menu_items.site_menu_id` → `site_menus.id` (CASCADE)
- `site_menu_items.parent_id` → `site_menu_items.id` (CASCADE)

## Индексы

- `site_menus`: `['is_active', 'sort_order']`
- `site_menu_items`: `['parent_id', 'sort_order', 'is_active']`

## Проверка

После выполнения миграций проверьте:

```bash
# Проверить структуру таблиц
php artisan tinker
>>> Schema::hasTable('site_menus');
>>> Schema::hasTable('site_menu_items');

# Проверить данные
>>> DB::table('site_menus')->count();
>>> DB::table('site_menu_items')->count();
>>> exit
```

## Откат (если нужно)

```bash
php artisan migrate:rollback --step=2
```

Или удалить таблицы вручную:
```sql
DROP TABLE IF EXISTS site_menu_items;
DROP TABLE IF EXISTS site_menus;
```
