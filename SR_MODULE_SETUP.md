# Инструкция по настройке модуля SELF-REASON (SR)

## Что было создано

### Миграции
- `2026_01_27_000001_create_sr_categories_table.php` - таблица категорий
- `2026_01_27_000002_create_sr_cards_table.php` - таблица карт

### Модели
- `app/Models/SrCategory.php` - модель категории
- `app/Models/SrCard.php` - модель карты

### Контроллеры
- `app/Http/Controllers/Admin/SrCategoriesController.php` - CRUD категорий
- `app/Http/Controllers/Admin/SrCardsController.php` - CRUD карт
- `app/Http/Controllers/Admin/SrUploadController.php` - загрузка изображений

### Роуты
Добавлены в `routes/api.php`:
- `GET /api/admin/sr/categories` - список категорий
- `POST /api/admin/sr/categories` - создание категории
- `PATCH /api/admin/sr/categories/{id}` - обновление категории
- `DELETE /api/admin/sr/categories/{id}` - удаление категории
- `GET /api/admin/sr/cards` - список карт
- `POST /api/admin/sr/cards` - создание карты
- `PATCH /api/admin/sr/cards/{id}` - обновление карты
- `DELETE /api/admin/sr/cards/{id}` - удаление карты
- `POST /api/admin/sr/upload` - загрузка изображения

## Шаги для развертывания на удаленном сервере

### 1. Обновить код
```bash
cd /var/www/api.ss.ru
git pull origin main
```

### 2. Выполнить миграции
```bash
php artisan migrate
```

### 3. Создать настройки SR (опционально, через админку или вручную)

Настройки можно создать через админку в разделе "Настройки", или выполнить SQL:

```sql
INSERT INTO settings (`key`, `name`, `type`, `group`, `value`, `description`) VALUES
('sr_card_width', 'Ширина карты', 'number', 'sr', '500', 'Ширина изображения карты в пикселях'),
('sr_card_height', 'Высота карты', 'number', 'sr', '500', 'Высота изображения карты в пикселях'),
('sr_category_width', 'Ширина категории', 'number', 'sr', '300', 'Ширина изображения категории в пикселях'),
('sr_category_height', 'Высота категории', 'number', 'sr', '300', 'Высота изображения категории в пикселях');
```

### 4. Очистить кэш
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### 5. Проверить права доступа к папке для изображений
```bash
# Убедитесь, что папка images/sr существует и доступна для записи
mkdir -p /var/www/admin.skateandsnow.ru/public/images/sr
chmod -R 755 /var/www/admin.skateandsnow.ru/public/images/sr
```

## Структура таблиц

### sr_categories
- `id` - ID категории
- `name` - Название категории
- `description` - Описание категории
- `icon` - Иконка (имя из nuxt-icon)
- `image` - Путь к изображению
- `created_at` - Дата создания
- `updated_at` - Дата обновления

### sr_cards
- `id` - ID карты
- `name` - Название карты
- `description` - HTML описание карты
- `image` - Путь к изображению
- `category_id` - ID категории (nullable, внешний ключ)
- `created_at` - Дата создания
- `updated_at` - Дата обновления

## Проверка работы

После развертывания проверьте:

1. **API эндпоинты:**
   ```bash
   curl -X GET "https://api.ss.ru/api/admin/sr/categories" \
     -H "Authorization: Bearer YOUR_TOKEN"
   ```

2. **Фронтенд:**
   - Откройте `/cms/sr` в админке
   - Проверьте работу всех табов (Карты, Категории, Настройки)

3. **Загрузка изображений:**
   - Попробуйте загрузить изображение через интерфейс
   - Проверьте, что файл сохраняется в `public/images/sr/`

## Возможные проблемы

### Ошибка "Table doesn't exist"
- Убедитесь, что миграции выполнены: `php artisan migrate:status`

### Ошибка загрузки изображений
- Проверьте права доступа к папке `public/images/sr/`
- Проверьте, что `FRONTEND_PATH` правильно настроен в `.env`

### Ошибка "Foreign key constraint fails"
- Убедитесь, что миграции выполнены в правильном порядке
- Если нужно, выполните `php artisan migrate:fresh` (ВНИМАНИЕ: удалит все данные!)

## Дополнительная информация

- Документация модуля: `admin.skateandsnow.ru/components/modules/sr/README.md`
- Инструкции по разработке: `admin.skateandsnow.ru/components/modules/sr/_SR_DEV_INSTRUCTIONS.md`
