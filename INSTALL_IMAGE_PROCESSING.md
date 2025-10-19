# Установка пакета для обработки изображений

Для работы с загрузкой и обработкой изображений категорий необходимо установить пакет Intervention Image.

## Установка

Выполните следующие команды на сервере:

```bash
# Установка пакета Intervention Image
composer require intervention/image

# Публикация конфигурации (опционально)
php artisan vendor:publish --provider="Intervention\Image\ImageServiceProviderLaravelRecent"
```

## Настройка

После установки пакет автоматически зарегистрируется в Laravel. Дополнительная настройка не требуется.

## Проверка установки

Для проверки работы пакета можно выполнить:

```bash
php artisan tinker
```

И в консоли выполнить:

```php
use Intervention\Image\Facades\Image;
Image::make('test.jpg')->resize(100, 100)->save('test_resized.jpg');
```

## Структура папок

Убедитесь, что папка для хранения изображений существует и доступна для записи:

```bash
# Создание папки для изображений категорий
mkdir -p storage/app/public/shop/categories

# Создание символической ссылки (если еще не создана)
php artisan storage:link
```

## Права доступа

Убедитесь, что папка storage имеет правильные права доступа:

```bash
chmod -R 775 storage
chown -R www-data:www-data storage
```

## Настройки изображений

В файле `.env` можно добавить настройки для изображений категорий:

```env
SHOP_CATEGORY_IMG_WIDTH=300
SHOP_CATEGORY_IMG_HEIGHT=200
```

Эти настройки будут использоваться по умолчанию при загрузке изображений.
