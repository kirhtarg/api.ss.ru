<?php

use Illuminate\Support\Facades\Route;

// Тестовый маршрут для проверки Yandex API
Route::get('/test/yandex-debug', function () {
    try {
        // Получаем токен (нужно будет заменить на реальный)
        $token = request('token');
        
        if (!$token) {
            return response()->json([
                'error' => 'Токен не предоставлен. Добавьте ?token=YOUR_TOKEN к URL'
            ]);
        }
        
        // Получаем данные пользователя
        $userResponse = \Http::withHeaders([
            'Authorization' => 'OAuth ' . $token
        ])->get('https://login.yandex.ru/info');
        
        $yandexUser = $userResponse->json();
        
        // Проверяем все возможные поля для аватара
        $avatarFields = [
            'default_avatar_id',
            'avatar_id', 
            'avatar',
            'picture',
            'photo',
            'image'
        ];
        
        $avatarInfo = [];
        foreach ($avatarFields as $field) {
            if (isset($yandexUser[$field])) {
                $avatarInfo[$field] = $yandexUser[$field];
            }
        }
        
        return response()->json([
            'success' => true,
            'all_data' => $yandexUser,
            'avatar_fields' => $avatarInfo,
            'available_fields' => array_keys($yandexUser)
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
});
