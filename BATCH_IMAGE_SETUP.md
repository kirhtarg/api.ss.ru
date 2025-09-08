# Настройка пакетной загрузки изображений

## Что было добавлено

### 1. API Endpoint
- **URL**: `POST /api/admin/shop/goods/download-images-batch`
- **Контроллер**: `App\Http\Controllers\Admin\ShopGoodsController@downloadImagesBatch`
- **Middleware**: `auth:sanctum`, `role:admin,manager`, `shop.access`

### 2. Функционал
- Пакетная загрузка до 50 изображений за один запрос
- Параллельная обработка изображений
- Валидация URL и форматов изображений
- Генерация хеша для имени файла
- Оптимизация изображений (уменьшение размера, сжатие)
- Сохранение в `storage/app/public/images/shop/goods/`

### 3. Тестовые инструменты
- **Команда**: `php artisan test:batch-image-download {urls*}`
- **Скрипт**: `test_batch_image_download.php`
- **Документация**: `BATCH_IMAGE_DOWNLOAD_API.md`

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

### 4. Зарегистрируйте тестовую команду (если нужно)
```bash
# Команда уже зарегистрирована автоматически в Laravel
php artisan list | grep test:batch-image-download
```

## Тестирование

### 1. Тест через Artisan команду
```bash
php artisan test:batch-image-download "https://via.placeholder.com/800x600.jpg" "https://via.placeholder.com/600x400.png"
```

### 2. Тест через PHP скрипт
```bash
# Отредактируйте test_batch_image_download.php
# Укажите правильный $baseUrl и $token
php test_batch_image_download.php
```

### 3. Тест через cURL
```bash
curl -X POST "https://yourdomain.com/api/admin/shop/goods/download-images-batch" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "imageUrls": [
      "https://via.placeholder.com/800x600.jpg",
      "https://via.placeholder.com/600x400.png"
    ],
    "storagePath": "/images/shop/goods",
    "optimize": true,
    "naming": "hash",
    "resize": "no_change"
  }'
```

### 4. Тест через Postman
- **Method**: POST
- **URL**: `https://yourdomain.com/api/admin/shop/goods/download-images-batch`
- **Headers**:
  - `Authorization: Bearer YOUR_TOKEN`
  - `Content-Type: application/json`
- **Body** (raw JSON):
```json
{
  "imageUrls": [
    "https://via.placeholder.com/800x600.jpg",
    "https://via.placeholder.com/600x400.png"
  ],
  "storagePath": "/images/shop/goods",
  "optimize": true,
  "naming": "hash",
  "resize": "no_change"
}
```

## Ожидаемый результат

### Успешный ответ
```json
{
  "success": true,
  "data": {
    "paths": {
      "https://via.placeholder.com/800x600.jpg": "/images/shop/goods/abc123def456.jpg",
      "https://via.placeholder.com/600x400.png": "/images/shop/goods/def456ghi789.png"
    },
    "errors": [],
    "total": 2,
    "successful": 2,
    "failed": 0
  }
}
```

### Консольный вывод
```
🚀 Тестирование пакетной загрузки изображений...
📡 URL: https://yourdomain.com/api/admin/shop/goods/download-images-batch
🖼️  Изображений для загрузки: 2
📋 URL изображений:
  1. https://via.placeholder.com/800x600.jpg
  2. https://via.placeholder.com/600x400.png

⏱️  Время выполнения: 1250.50ms
📊 HTTP код: 200

✅ Пакетная загрузка успешна!

📊 Статистика:
  Всего изображений: 2
  Успешно загружено: 2
  Ошибок: 0

📁 Загруженные файлы:
  ✅ https://via.placeholder.com/800x600.jpg
     → /images/shop/goods/abc123def456.jpg
  ✅ https://via.placeholder.com/600x400.png
     → /images/shop/goods/def456ghi789.png

🔍 Проверка доступности файлов:
  ✅ https://yourdomain.com/storage/images/shop/goods/abc123def456.jpg - доступен
  ✅ https://yourdomain.com/storage/images/shop/goods/def456ghi789.png - доступен

🎉 Тест завершен успешно!
```

## Структура файлов

```
api.ss.ru/
├── app/Http/Controllers/Admin/ShopGoodsController.php  # Контроллер с методами
├── app/Console/Commands/TestBatchImageDownload.php     # Тестовая команда
├── routes/api.php                                      # API маршруты
├── test_batch_image_download.php                      # Тестовый скрипт
├── BATCH_IMAGE_DOWNLOAD_API.md                        # API документация
└── BATCH_IMAGE_SETUP.md                               # Инструкции по настройке
```

## Преимущества

1. **Производительность**: Один запрос вместо множества
2. **Надежность**: Устранение ошибки 429 (Too Many Requests)
3. **Эффективность**: Параллельная обработка изображений
4. **Масштабируемость**: До 50 изображений за запрос
5. **Совместимость**: Работает с существующим функционалом

## Мониторинг

### Логи
Проверьте логи Laravel для отслеживания ошибок:
```bash
tail -f storage/logs/laravel.log
```

### Статистика
API возвращает детальную статистику:
- Общее количество изображений
- Количество успешно загруженных
- Количество ошибок
- Детали ошибок

## Устранение неполадок

### Ошибка 429 (Too Many Requests)
- ✅ Решено пакетной загрузкой
- Один запрос вместо множества

### Ошибка 422 (Validation Error)
- Проверьте формат URL изображений
- Убедитесь что массив содержит 1-50 элементов

### Ошибка 500 (Server Error)
- Проверьте права доступа к папке storage
- Убедитесь что storage link создан
- Проверьте логи Laravel

### Файлы не доступны
- Убедитесь что storage link создан: `php artisan storage:link`
- Проверьте права доступа к папке storage
- Проверьте настройки веб-сервера для статических файлов
