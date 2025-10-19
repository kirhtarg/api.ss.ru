# Настройка скачивания изображений для импорта товаров

## Что было добавлено

### 1. API Endpoint
- **URL**: `POST /api/admin/shop/goods/download-image`
- **Контроллер**: `App\Http\Controllers\Admin\ShopGoodsController@downloadImage`
- **Middleware**: `auth:sanctum`, `role:admin,manager`, `shop.access`

### 2. Функционал
- Скачивание изображений по URL
- Валидация форматов (jpg, jpeg, png, gif, webp, bmp, svg, tiff, ico)
- Генерация хеша для имени файла
- Оптимизация изображений (уменьшение размера, сжатие)
- Сохранение в `storage/app/public/images/shop/goods/`

## Настройка

### 1. Убедитесь что storage link создан
```bash
php artisan storage:link
```

### 2. Проверьте права доступа к папке storage
```bash
chmod -R 755 storage/
chown -R www-data:www-data storage/
```

### 3. Создайте папку для изображений товаров
```bash
mkdir -p storage/app/public/images/shop/goods
chmod 755 storage/app/public/images/shop/goods
```

## Тестирование

### 1. Проверка endpoint'а
```bash
curl -X POST "https://yourdomain.com/api/admin/shop/goods/download-image" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "imageUrl": "https://example.com/image.jpg",
    "storagePath": "/images/shop/goods",
    "optimize": true
  }'
```

### 2. Ожидаемый ответ
```json
{
  "success": true,
  "data": {
    "path": "/images/shop/goods/abc123def456.jpg",
    "originalUrl": "https://example.com/image.jpg",
    "size": 1024000,
    "optimized": true
  }
}
```

## Структура файлов

```
storage/
  app/
    public/
      images/
        shop/
          goods/
            abc123def456.jpg
            def456ghi789.png
            ...
```

## Доступ к файлам

Файлы будут доступны по URL:
`https://yourdomain.com/storage/images/shop/goods/filename.jpg`

## Обработка ошибок

### Возможные ошибки:
- `422` - Ошибка валидации (неверный URL, неподдерживаемый формат)
- `400` - Не удалось скачать изображение
- `400` - Файл слишком большой (максимум 10MB)
- `500` - Внутренняя ошибка сервера

### Логирование
Ошибки оптимизации изображений записываются в `storage/logs/laravel.log`

## Безопасность

- Проверка формата файла по расширению
- Ограничение размера файла (10MB)
- Валидация URL
- Авторизация через Sanctum
- Проверка ролей (admin/manager)
- Проверка доступа к shop

## Производительность

- Оптимизация изображений (уменьшение до 2000px, сжатие)
- Генерация уникальных имен файлов (хеш от URL)
- Создание директорий при необходимости
- Обработка ошибок без прерывания процесса

## Мониторинг

Для мониторинга работы можно добавить логирование в контроллер:

```php
\Log::info('Image download started', [
    'url' => $imageUrl,
    'path' => $fullPath,
    'user_id' => auth()->id()
]);
```
