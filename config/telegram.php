<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки для интеграции с Telegram ботом для уведомлений о заказах
    |
    */

    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
    'customer_chat_id' => env('TELEGRAM_CUSTOMER_CHAT_ID'), // Для тестирования
    
    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    |
    | Настройки уведомлений
    |
    */
    
    'notifications' => [
        'enabled' => env('TELEGRAM_NOTIFICATIONS_ENABLED', true),
        'max_attempts' => env('TELEGRAM_MAX_ATTEMPTS', 3),
        'retry_delay' => env('TELEGRAM_RETRY_DELAY', 300), // 5 минут
    ],
    
    /*
    |--------------------------------------------------------------------------
    | SSL Verification
    |--------------------------------------------------------------------------
    |
    | Проверка SSL сертификатов при подключении к Telegram API
    | ВНИМАНИЕ: Отключение проверки SSL небезопасно! Используйте только для тестирования
    | или если на сервере проблемы с SSL сертификатами
    |
    */
    
    'verify_ssl' => env('TELEGRAM_VERIFY_SSL', true),
    
    /*
    |--------------------------------------------------------------------------
    | Message Templates
    |--------------------------------------------------------------------------
    |
    | Шаблоны сообщений для разных типов уведомлений
    |
    */
    
    'templates' => [
        'order_created' => [
            'admin' => '🆕 <b>Новый заказ #{order_number}</b>',
            'customer' => '✅ <b>Заказ #{order_number} принят</b>'
        ],
        'order_updated' => [
            'admin' => '🔄 <b>Заказ #{order_number} обновлен</b>',
            'customer' => '📋 <b>Заказ #{order_number} обновлен</b>'
        ],
        'order_cancelled' => [
            'admin' => '❌ <b>Заказ #{order_number} отменен</b>',
            'customer' => '❌ <b>Заказ #{order_number} отменен</b>'
        ],
        'payment_success' => [
            'admin' => '✅ <b>Оплата заказа #{order_number} прошла успешно</b>',
            'customer' => '✅ <b>Оплата заказа #{order_number} прошла успешно</b>'
        ],
        'payment_failed' => [
            'admin' => '❌ <b>Ошибка оплаты заказа #{order_number}</b>',
            'customer' => '❌ <b>Ошибка оплаты заказа #{order_number}</b>'
        ]
    ]
];
