# Обновление VK OAuth до версии 2.1

## 🚨 Проблема
VK перешел на OAuth 2.1 в июне 2024 года, что объясняет ошибку "Selected sign-in method not available for app".

## 🔧 Решение

### 1. Создайте новое VK приложение
1. Перейдите на: https://vk.com/apps?act=manage
2. Создайте новое приложение:
   - **Тип:** Standalone приложение
   - **Платформа:** Веб-сайт
   - **Название:** SS Auth
3. Настройте OAuth 2.1:
   - **Доверенный redirect URI:** `https://ss75-api.kirhtarg.ru/api/auth/vk/callback`
   - **Разрешения:** email
   - **Open API:** Включите

### 2. Обновите .env на сервере
```env
# Новый Client ID для OAuth 2.1
VK_CLIENT_ID=ваш_новый_client_id
VK_CLIENT_SECRET=ваш_новый_client_secret
VK_REDIRECT_URI=https://ss75-api.kirhtarg.ru/api/auth/vk/callback
```

### 3. Обновите VkAuthController для OAuth 2.1
```php
// В методе redirectToVk()
$url = "https://oauth.vk.com/authorize?" . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => 'email',
    'response_type' => 'code',
    'v' => '5.199', // Обновленная версия API
    'state' => Str::random(32) // Рекомендуется для OAuth 2.1
]);
```

### 4. Используйте VK ID SDK (рекомендуется)
Вместо классического OAuth используйте VK ID SDK:

```vue
<template>
  <div>
    <!-- VK ID SDK -->
    <VKAuth />
  </div>
</template>
```

## 🎯 Преимущества VK ID SDK:

1. **Поддержка OAuth 2.1** - автоматически обновляется
2. **Упрощенная интеграция** - меньше кода
3. **Надежность** - официальный SDK
4. **Совместимость** - работает с новыми стандартами

## 🧪 Тестирование:

### 1. Создайте новое VK приложение
### 2. Обновите .env с новым Client ID
### 3. Очистите кэш: `php artisan config:clear`
### 4. Протестируйте авторизацию

## 📞 Если проблемы остаются:

1. **Обратитесь в поддержку VK:**
   - https://vk.com/support?act=home
   - Укажите, что используете OAuth 2.1

2. **Используйте VK ID SDK:**
   - Более надежное решение
   - Автоматически поддерживает новые стандарты

## 🚀 Рекомендация:

**Используйте VK ID SDK** - это самое надежное решение для новой версии VK!

**Создайте новое VK приложение и обновите код!** 🎉
