# Команды для настройки скачивания изображений

## 1. Создание storage link
```bash
php artisan storage:link
```

## 2. Запуск миграции для создания папки
```bash
php artisan migrate
```

## 3. Установка прав доступа
```bash
chmod -R 755 storage/
chown -R www-data:www-data storage/
```

## 4. Тестирование функционала
```bash
# Тест с реальным изображением
php artisan test:image-download "https://via.placeholder.com/800x600.jpg" --path="/images/shop/goods" --optimize=true

# Тест с PNG изображением
php artisan test:image-download "https://via.placeholder.com/400x300.png" --path="/images/shop/goods" --optimize=true

# Тест без оптимизации
php artisan test:image-download "https://via.placeholder.com/600x400.jpg" --path="/images/shop/goods" --optimize=false
```

## 5. Проверка созданных файлов
```bash
ls -la storage/app/public/images/shop/goods/
```

## 6. Проверка доступности файлов через веб
Откройте в браузере:
```
https://yourdomain.com/storage/images/shop/goods/filename.jpg
```

## 7. Очистка тестовых файлов (опционально)
```bash
rm -rf storage/app/public/images/shop/goods/*
```

## 8. Проверка логов
```bash
tail -f storage/logs/laravel.log
```

## Возможные проблемы и решения

### Проблема: "Storage link already exists"
**Решение**: Удалите существующую ссылку и создайте новую
```bash
rm public/storage
php artisan storage:link
```

### Проблема: "Permission denied"
**Решение**: Установите правильные права доступа
```bash
sudo chown -R www-data:www-data storage/
sudo chmod -R 755 storage/
```

### Проблема: "Directory not found"
**Решение**: Создайте папку вручную
```bash
mkdir -p storage/app/public/shop/goods
chmod 755 storage/app/public/shop/goods
```

### Проблема: "Image download failed"
**Решение**: Проверьте доступность URL и настройки PHP
```bash
# Проверьте allow_url_fopen в php.ini
php -i | grep allow_url_fopen
```

## Мониторинг

### Проверка размера папки с изображениями
```bash
du -sh storage/app/public/shop/goods/
```

### Подсчет количества файлов
```bash
find storage/app/public/shop/goods/ -type f | wc -l
```

### Очистка старых файлов (старше 30 дней)
```bash
find storage/app/public/shop/goods/ -type f -mtime +30 -delete
```
