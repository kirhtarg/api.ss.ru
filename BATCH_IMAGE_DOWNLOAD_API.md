# API Endpoint для пакетной загрузки изображений

## Endpoint: POST /api/admin/shop/goods/download-images-batch

### Описание
Скачивает несколько изображений по URL, оптимизирует их и сохраняет на сервере. Позволяет загружать до 50 изображений за один запрос.

### Параметры запроса
```json
{
  "imageUrls": [
    "https://example.com/image1.jpg",
    "https://example.com/image2.jpg",
    "https://example.com/image3.jpg"
  ],
  "storagePath": "/images/shop/goods",
  "optimize": true,
  "naming": "hash",
  "resize": "no_change",
  "width": 500,
  "height": 500
}
```

### Параметры

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `imageUrls` | array | Да | Массив URL изображений (1-50 элементов) |
| `imageUrls.*` | string | Да | URL изображения (должен быть валидным URL) |
| `storagePath` | string | Да | Путь для сохранения на сервере |
| `optimize` | boolean | Нет | Оптимизировать изображения (по умолчанию: true) |
| `naming` | string | Нет | Способ именования файлов: `original` или `hash` (по умолчанию: hash) |
| `resize` | string | Нет | Тип изменения размера: `no_change`, `crop_proportional`, `fit_with_white`, `fit_system`, `custom` (по умолчанию: no_change) |
| `width` | integer | Нет | Ширина для изменения размера (только для resize: custom) |
| `height` | integer | Нет | Высота для изменения размера (только для resize: custom) |

### Ответ

#### Успешный ответ
```json
{
  "success": true,
  "data": {
    "paths": {
      "https://example.com/image1.jpg": "/images/shop/goods/abc123def456.jpg",
      "https://example.com/image2.jpg": "/images/shop/goods/def456ghi789.jpg",
      "https://example.com/image3.jpg": "/images/shop/goods/ghi789jkl012.jpg"
    },
    "errors": [
      {
        "url": "https://example.com/invalid.jpg",
        "error": "Не удалось скачать изображение"
      }
    ],
    "total": 4,
    "successful": 3,
    "failed": 1
  }
}
```

#### Ошибка валидации
```json
{
  "success": false,
  "message": "Ошибка валидации",
  "errors": {
    "imageUrls": ["Поле imageUrls обязательно для заполнения."],
    "imageUrls.0": ["Поле imageUrls.0 должно быть действительным URL."]
  }
}
```

#### Ошибка сервера
```json
{
  "success": false,
  "message": "Ошибка пакетной загрузки изображений: [детали ошибки]"
}
```

### Поддерживаемые форматы изображений
- JPEG (.jpg, .jpeg)
- PNG (.png)
- GIF (.gif)
- WebP (.webp)
- BMP (.bmp)
- SVG (.svg)
- TIFF (.tiff)
- ICO (.ico)

### Ограничения
- Максимум 50 изображений за один запрос
- Максимальный размер одного файла: 10MB
- Максимальный размер всех файлов в запросе: 500MB

### Примеры использования

#### cURL
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
    "resize": "crop_proportional",
    "width": 500,
    "height": 500
  }'
```

#### JavaScript (fetch)
```javascript
const response = await fetch('/api/admin/shop/goods/download-images-batch', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    imageUrls: [
      'https://example.com/image1.jpg',
      'https://example.com/image2.jpg'
    ],
    storagePath: '/images/shop/goods',
    optimize: true,
    naming: 'hash',
    resize: 'no_change'
  })
});

const data = await response.json();
console.log(data);
```

### Тестирование

#### Команда для тестирования
```bash
php artisan test:batch-image-download "https://via.placeholder.com/800x600.jpg" "https://via.placeholder.com/600x400.png"
```

#### Ожидаемый результат
```
Тестирование пакетной загрузки изображений...
URL изображений: https://via.placeholder.com/800x600.jpg, https://via.placeholder.com/600x400.png
✅ Пакетная загрузка успешна!
Всего изображений: 2
Успешно загружено: 2
Ошибок: 0
Загруженные файлы:
  https://via.placeholder.com/800x600.jpg -> /images/shop/goods/abc123def456.jpg
  https://via.placeholder.com/600x400.png -> /images/shop/goods/def456ghi789.png
```

### Преимущества пакетной загрузки

1. **Меньше HTTP запросов** - один запрос вместо множества
2. **Лучшая производительность** - параллельная обработка
3. **Меньше нагрузки на сервер** - группировка операций
4. **Устранение ошибки 429** - Too Many Requests
5. **Эффективное использование ресурсов** - оптимизация памяти и CPU

### Безопасность

- Все URL проверяются на валидность
- Поддерживаются только безопасные форматы изображений
- Ограничение размера файлов предотвращает DoS атаки
- Авторизация через Bearer token
- Middleware: `auth:sanctum`, `role:admin,manager`, `shop.access`
