# API Endpoint для пакетного создания изображений товаров

## Endpoint: POST /api/admin/shop/goods/images/import-batch

### Описание
Создает несколько записей изображений товаров в базе данных за один запрос. Позволяет создавать до 100 изображений за раз.

### Параметры запроса
```json
{
  "images": [
    {
      "good_id": 1010,
      "file_path": "/images/shop/goods/image1.jpg",
      "alt_text": "Описание изображения",
      "is_main": true,
      "sort_order": 0,
      "image_action": "add"
    },
    {
      "good_id": 1010,
      "file_path": "/images/shop/goods/image2.jpg",
      "alt_text": "Второе изображение",
      "is_main": false,
      "sort_order": 1,
      "image_action": "add"
    }
  ]
}
```

### Параметры

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `images` | array | Да | Массив изображений (1-100 элементов) |
| `images.*.good_id` | integer | Да | ID товара (должен существовать в базе) |
| `images.*.file_path` | string | Да | Путь к файлу изображения |
| `images.*.alt_text` | string | Нет | Альтернативный текст изображения |
| `images.*.is_main` | boolean | Нет | Является ли главным изображением (по умолчанию: false) |
| `images.*.sort_order` | integer | Нет | Порядок сортировки (по умолчанию: 0) |
| `images.*.image_action` | string | Нет | Действие с изображениями: `add`, `replace`, `skip`, `unique` (по умолчанию: add) |

### Действия с изображениями

- **`add`** - Добавить изображение (по умолчанию)
- **`replace`** - Заменить все существующие изображения товара
- **`skip`** - Пропустить, если у товара уже есть изображения
- **`unique`** - Пропустить, если изображение с таким путем уже существует в базе

### Ответ

#### Успешный ответ
```json
{
  "success": true,
  "data": {
    "created": [
      {
        "good_id": 1010,
        "file_path": "/images/shop/goods/image1.jpg",
        "image_id": 123,
        "status": "created",
        "message": "Изображение успешно создано"
      },
      {
        "good_id": 1010,
        "file_path": "/images/shop/goods/image2.jpg",
        "image_id": 124,
        "status": "created",
        "message": "Изображение успешно создано"
      }
    ],
    "errors": [
      {
        "index": 2,
        "good_id": 1010,
        "file_path": "/images/shop/goods/invalid.jpg",
        "error": "Файл изображения не найден: /images/shop/goods/invalid.jpg"
      }
    ],
    "total": 3,
    "successful": 2,
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
    "images": ["Поле images обязательно для заполнения."],
    "images.0.good_id": ["Поле images.0.good_id обязательно для заполнения."]
  }
}
```

#### Ошибка сервера
```json
{
  "success": false,
  "message": "Ошибка пакетного создания изображений: [детали ошибки]"
}
```

### Ограничения
- Максимум 100 изображений за один запрос
- Файлы должны существовать в storage/app/public/
- Товары должны существовать в базе данных

### Примеры использования

#### cURL
```bash
curl -X POST "https://yourdomain.com/api/admin/shop/goods/images/import-batch" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "images": [
      {
        "good_id": 1010,
        "file_path": "/images/shop/goods/image1.jpg",
        "alt_text": "Главное изображение",
        "is_main": true,
        "sort_order": 0,
        "image_action": "add"
      },
      {
        "good_id": 1010,
        "file_path": "/images/shop/goods/image2.jpg",
        "alt_text": "Дополнительное изображение",
        "is_main": false,
        "sort_order": 1,
        "image_action": "add"
      }
    ]
  }'
```

#### JavaScript (fetch)
```javascript
const response = await fetch('/api/admin/shop/goods/images/import-batch', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    images: [
      {
        good_id: 1010,
        file_path: '/images/shop/goods/image1.jpg',
        alt_text: 'Главное изображение',
        is_main: true,
        sort_order: 0,
        image_action: 'add'
      }
    ]
  })
});

const data = await response.json();
console.log(data);
```

### Тестирование

#### Команда для тестирования
```bash
php artisan test:batch-image-create 1010 "/images/shop/goods/image1.jpg" "/images/shop/goods/image2.jpg"
```

#### Ожидаемый результат
```
Тестирование пакетного создания изображений...
ID товара: 1010
Пути к файлам: /images/shop/goods/image1.jpg, /images/shop/goods/image2.jpg

✅ Пакетное создание изображений успешно!
Всего изображений: 2
Успешно создано: 2
Ошибок: 0
Созданные изображения:
  ✅ Товар 1010: /images/shop/goods/image1.jpg (ID: 123)
  ✅ Товар 1010: /images/shop/goods/image2.jpg (ID: 124)
```

### Преимущества пакетного создания

1. **Меньше HTTP запросов** - один запрос вместо множества
2. **Лучшая производительность** - группировка операций с базой данных
3. **Атомарность** - все изображения создаются в одной транзакции
4. **Эффективное использование ресурсов** - оптимизация запросов к БД
5. **Устранение ошибки 429** - Too Many Requests

### Безопасность

- Все товары проверяются на существование
- Файлы проверяются на существование в storage
- Авторизация через Bearer token
- Middleware: `auth:sanctum`, `role:admin,manager`, `shop.access`

### Логика работы

1. **Валидация** - проверка всех входных данных
2. **Группировка** - группировка изображений по товарам
3. **Обработка** - создание записей в базе данных
4. **Обновление флагов** - установка главного изображения
5. **Возврат результата** - детальная статистика и ошибки
