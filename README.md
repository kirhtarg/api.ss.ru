# API SS RU - Laravel Backend

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

## Описание проекта

API SS RU - это Laravel API бэкенд для проекта skateandsnow.ru. Предоставляет REST API для управления настройками сайта, загрузки и обработки изображений, а также аутентификации пользователей.

## Основные возможности

- **Аутентификация**: Laravel Sanctum для API аутентификации
- **Управление настройками**: CRUD операции для системных настроек
- **Загрузка изображений**: Поддержка загрузки, удаления и изменения размеров изображений
- **API эндпоинты**: Публичные и защищенные маршруты
- **База данных**: MySQL с миграциями и сидерами

## Технические требования

- PHP 8.1+
- Laravel 10.x
- MySQL 8.0+
- Composer
- PHP GD Extension (для обработки изображений)

## Установка и настройка

### 1. Клонирование репозитория

```bash
git clone https://github.com/YOUR_USERNAME/api.ss.ru.git
cd api.ss.ru
```

### 2. Установка зависимостей

```bash
composer install
```

### 3. Настройка окружения

```bash
# Скопируйте файл окружения
cp .env.example .env

# Сгенерируйте ключ приложения
php artisan key:generate

# Настройте подключение к базе данных в .env
```

### 4. Настройка базы данных

```bash
# Создайте базу данных
# Настройте параметры в .env файле

# Запустите миграции
php artisan migrate

# Заполните базу начальными данными
php artisan db:seed
```

### 5. Настройка хранилища

```bash
# Создайте символическую ссылку для публичного доступа к файлам
php artisan storage:link
```

### 6. Запуск сервера

```bash
php artisan serve
```

API будет доступен по адресу: `http://localhost:8000/api`

## Структура API

### Публичные маршруты

- `GET /api/public/site-info` - Информация о сайте
- `GET /api/public/settings` - Публичные настройки

### Защищенные маршруты (требуют аутентификации)

- `POST /api/login` - Аутентификация пользователя
- `GET /api/admin/settings` - Получение всех настроек
- `POST /api/admin/settings` - Создание новой настройки
- `PUT /api/admin/settings/{id}` - Обновление настройки
- `DELETE /api/admin/settings/{id}` - Удаление настройки
- `POST /api/admin/settings/{id}/image` - Загрузка изображения
- `DELETE /api/admin/settings/{id}/image` - Удаление изображения
- `POST /api/admin/settings/{id}/resize` - Изменение размеров изображения

## Модели

### Setting

Основная модель для хранения настроек сайта:

- `key` - Уникальный ключ настройки
- `value` - Значение настройки
- `type` - Тип значения (string, text, number, boolean, image)
- `group` - Группа настроек
- `image_width` - Ширина изображения (для типа image)
- `image_height` - Высота изображения (для типа image)

## Обработка изображений

Проект поддерживает:

- Загрузку изображений (JPEG, PNG, GIF, WebP)
- Автоматическое изменение размеров при загрузке
- Ручное изменение размеров через API
- Удаление изображений
- Сохранение метаданных (ширина, высота)

## Разработка

### Запуск тестов

```bash
php artisan test
```

### Очистка кэша

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
```

### Создание миграции

```bash
php artisan make:migration create_table_name
```

### Создание сидера

```bash
php artisan make:seeder SeederName
```

## Развертывание

### Продакшн

1. Установите зависимости: `composer install --optimize-autoloader --no-dev`
2. Настройте `.env` для продакшна
3. Очистите кэш: `php artisan config:cache`
4. Настройте веб-сервер (Apache/Nginx)

### Docker (опционально)

```bash
# Создайте docker-compose.yml для локальной разработки
# Настройте volumes для базы данных и файлов
```

## Безопасность

- Все API маршруты защищены CORS
- Аутентификация через Laravel Sanctum
- Валидация всех входящих данных
- Защита от SQL инъекций через Eloquent ORM

## Лицензия

Этот проект использует [MIT license](https://opensource.org/licenses/MIT).

## Поддержка

Для вопросов и предложений создавайте Issues в GitHub репозитории.

## Авторы

Разработано для проекта skateandsnow.ru
