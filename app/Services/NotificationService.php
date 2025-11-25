<?php

namespace App\Services;

use App\Models\ShopNotificationChannel;
use App\Models\ShopOrder;
use App\Models\ShopPreorder;
use App\Models\SiteMessage;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected TelegramService $telegramService;
    protected EmailNotificationService $emailService;

    public function __construct(
        TelegramService $telegramService,
        EmailNotificationService $emailService
    ) {
        $this->telegramService = $telegramService;
        $this->emailService = $emailService;
    }

    /**
     * Форматировать телефон для Telegram с кликабельностью
     * Добавляет + если нужно - Telegram автоматически делает номера с + кликабельными
     */
    protected function formatPhoneForTelegram(?string $phone): string
    {
        if (!$phone) {
            return 'не указан';
        }

        // Убираем все нецифровые символы
        $cleanPhone = preg_replace('/\D/', '', $phone);
        
        // Если номер начинается с 8, заменяем на 7
        if (str_starts_with($cleanPhone, '8')) {
            $cleanPhone = '7' . substr($cleanPhone, 1);
        }
        
        // Если номер не начинается с +, добавляем +
        if (!str_starts_with($cleanPhone, '+')) {
            // Если номер начинается с 7, добавляем +
            if (str_starts_with($cleanPhone, '7')) {
                $cleanPhone = '+' . $cleanPhone;
            } else {
                // Если номер не начинается с 7, добавляем +7
                $cleanPhone = '+7' . $cleanPhone;
            }
        }
        
        // Telegram автоматически делает номера с + кликабельными, просто возвращаем номер с +
        return $cleanPhone;
    }

    /**
     * Форматировать телефон для Email
     * Добавляет + если нужно
     */
    protected function formatPhoneForEmail(?string $phone): string
    {
        if (!$phone) {
            return 'не указан';
        }

        // Убираем все нецифровые символы
        $cleanPhone = preg_replace('/\D/', '', $phone);
        
        // Если номер начинается с 8, заменяем на 7
        if (str_starts_with($cleanPhone, '8')) {
            $cleanPhone = '7' . substr($cleanPhone, 1);
        }
        
        // Если номер не начинается с +, добавляем +
        if (!str_starts_with($cleanPhone, '+')) {
            // Если номер начинается с 7, добавляем +
            if (str_starts_with($cleanPhone, '7')) {
                $cleanPhone = '+' . $cleanPhone;
            } else {
                // Если номер не начинается с 7, добавляем +7
                $cleanPhone = '+7' . $cleanPhone;
            }
        }
        
        return $cleanPhone;
    }

    /**
     * Форматировать телефон для Email HTML (с гиперссылкой)
     * Добавляет + если нужно и создает tel: ссылку
     */
    protected function formatPhoneForEmailHtml(?string $phone): string
    {
        if (!$phone) {
            return 'не указан';
        }

        // Убираем все нецифровые символы
        $cleanPhone = preg_replace('/\D/', '', $phone);
        
        // Если номер начинается с 8, заменяем на 7
        if (str_starts_with($cleanPhone, '8')) {
            $cleanPhone = '7' . substr($cleanPhone, 1);
        }
        
        // Если номер не начинается с +, добавляем +
        if (!str_starts_with($cleanPhone, '+')) {
            // Если номер начинается с 7, добавляем +
            if (str_starts_with($cleanPhone, '7')) {
                $cleanPhone = '+' . $cleanPhone;
            } else {
                // Если номер не начинается с 7, добавляем +7
                $cleanPhone = '+7' . $cleanPhone;
            }
        }
        
        // Создаем HTML-ссылку
        return '<a href="tel:' . htmlspecialchars($cleanPhone, ENT_QUOTES, 'UTF-8') . '" style="color: #667eea; text-decoration: none;">' . htmlspecialchars($cleanPhone, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    /**
     * Отправить уведомление о создании заказа
     */
    public function notifyOrderCreated(ShopOrder $order): void
    {
        $this->sendNotification('order_created', [
            'order' => $order
        ]);
    }

    /**
     * Отправить уведомление о заявке на отмену оплаченного заказа
     */
    public function notifyCancellationRequest(ShopOrder $order): void
    {
        $this->sendNotification('cancellation_request', [
            'order' => $order
        ]);
    }

    /**
     * Отправить уведомление об отмене заказа пользователем
     */
    public function notifyOrderCancelled(ShopOrder $order): void
    {
        $this->sendNotification('order_cancelled', [
            'order' => $order
        ]);
    }

    /**
     * Отправить уведомление о предзаказе
     */
    public function notifyPreorderCreated(ShopPreorder $preorder): void
    {
        $this->sendNotification('preorder_created', [
            'preorder' => $preorder
        ]);
    }

    /**
     * Отправить уведомление о сообщении на сайте
     */
    public function notifySiteMessage(SiteMessage $message): void
    {
        $this->sendNotification('site_message', [
            'message' => $message
        ]);
    }

    /**
     * Отправить уведомление через все активные каналы для события
     */
    protected function sendNotification(string $eventType, array $data): void
    {
        $channels = ShopNotificationChannel::getChannelsForEvent($eventType);

        foreach ($channels as $channel) {
            try {
                if ($channel->type === 'email') {
                    $this->sendEmailNotification($channel, $eventType, $data);
                } elseif ($channel->type === 'telegram') {
                    $this->sendTelegramNotification($channel, $eventType, $data);
                }
            } catch (\Exception $e) {
                Log::error("Failed to send notification via channel {$channel->id}: " . $e->getMessage(), [
                    'channel_id' => $channel->id,
                    'channel_type' => $channel->type,
                    'event_type' => $eventType,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    /**
     * Отправить email уведомление
     */
    protected function sendEmailNotification(ShopNotificationChannel $channel, string $eventType, array $data): void
    {
        try {
            $subject = $this->getEmailSubject($eventType, $data);
            
            // Для уведомлений о заказе, предзаказе, заявке на отмену, отмене заказа и сообщениях на сайте используем HTML шаблоны
            if (in_array($eventType, ['order_created', 'cancellation_request', 'order_cancelled', 'preorder_created', 'site_message'])) {
                $result = $this->emailService->sendHtmlViaChannel($channel, $subject, $eventType, $data);
                if (!$result['success']) {
                    Log::error("Failed to send HTML email via channel {$channel->id}", [
                        'channel_id' => $channel->id,
                        'event_type' => $eventType,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                }
            } else {
                $message = $this->getEmailMessage($eventType, $data);
                $result = $this->emailService->sendViaChannel($channel, $subject, $message, $data);
                if (!$result['success']) {
                    Log::error("Failed to send email via channel {$channel->id}", [
                        'channel_id' => $channel->id,
                        'event_type' => $eventType,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Exception in sendEmailNotification: " . $e->getMessage(), [
                'channel_id' => $channel->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Отправить Telegram уведомление
     */
    protected function sendTelegramNotification(ShopNotificationChannel $channel, string $eventType, array $data): void
    {
        try {
            $message = $this->getTelegramMessage($eventType, $data);

            // Используем bot_token из канала, если указан, иначе из конфига
            $botToken = $channel->telegram_bot_token ?? config('telegram.bot_token');
            $chatId = $channel->telegram_chat_id;

            if (!$botToken || !$chatId) {
                return;
            }

            // Отправляем сообщение с указанным токеном
            $result = $this->telegramService->sendMessageWithToken($botToken, $chatId, $message);
            
            if (!$result['success']) {
                Log::error("Failed to send Telegram message", [
                    'channel_id' => $channel->id,
                    'event_type' => $eventType,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Exception in sendTelegramNotification: " . $e->getMessage(), [
                'channel_id' => $channel->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Получить тему email
     */
    protected function getEmailSubject(string $eventType, array $data): string
    {
        return match($eventType) {
            'order_created' => "Новый заказ #{$data['order']->order_number}",
            'cancellation_request' => "Заявка на отмену заказа #{$data['order']->order_number}",
            'order_cancelled' => "Заказ #{$data['order']->order_number} отменен",
            'preorder_created' => "Новый предзаказ товара",
            'site_message' => $data['message']->type === 'callback' 
                ? "Запрос обратного звонка от {$data['message']->name}"
                : "Новое сообщение на сайте от {$data['message']->name}",
            default => "Уведомление магазина"
        };
    }

    /**
     * Получить текст email сообщения
     */
    protected function getEmailMessage(string $eventType, array $data): string
    {
        return match($eventType) {
            'order_created' => $this->formatOrderCreatedEmail($data['order']),
            'cancellation_request' => $this->formatCancellationRequestEmail($data['order']),
            'order_cancelled' => $this->formatOrderCancelledEmail($data['order']),
            'preorder_created' => $this->formatPreorderCreatedEmail($data['preorder']),
            'site_message' => $this->formatSiteMessageEmail($data['message']),
            default => "Уведомление о событии: {$eventType}"
        };
    }

    /**
     * Получить текст Telegram сообщения
     */
    protected function getTelegramMessage(string $eventType, array $data): string
    {
        return match($eventType) {
            'order_created' => $this->formatOrderCreatedTelegram($data['order']),
            'cancellation_request' => $this->formatCancellationRequestTelegram($data['order']),
            'order_cancelled' => $this->formatOrderCancelledTelegram($data['order']),
            'preorder_created' => $this->formatPreorderCreatedTelegram($data['preorder']),
            'site_message' => $this->formatSiteMessageTelegram($data['message']),
            default => "Уведомление о событии: {$eventType}"
        };
    }

    /**
     * Форматировать сообщение о создании заказа для Email
     */
    protected function formatOrderCreatedEmail(ShopOrder $order): string
    {
        $message = "Новый заказ создан\n\n";
        $message .= "Номер заказа: #{$order->order_number}\n";
        $message .= "Клиент: {$order->customer_name}\n";
        $message .= "Email: {$order->customer_email}\n";
        $message .= "Телефон: " . $this->formatPhoneForEmail($order->customer_phone) . "\n\n";
        $message .= "Сумма: " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
        $message .= "Товаров: {$order->total_quantity} шт.\n\n";
        
        if ($order->shipping_method) {
            $message .= "Доставка: {$order->shipping_method}\n";
        }
        if ($order->payment_method) {
            $message .= "Оплата: {$order->payment_method}\n";
        }
        if ($order->shipping_address) {
            $message .= "Адрес: {$order->shipping_address}\n";
        }
        if ($order->notes) {
            $message .= "Комментарий: {$order->notes}\n";
        }
        
        $message .= "\nВремя создания: " . $order->created_at->format('d.m.Y H:i');
        
        return $message;
    }

    /**
     * Форматировать сообщение о создании заказа для Telegram
     */
    protected function formatOrderCreatedTelegram(ShopOrder $order): string
    {
        $message = "🆕 <b>Новый заказ</b>\n\n";
        $message .= "📋 <b>Заказ #{$order->order_number}</b>\n";
        $message .= "📅 <b>Дата:</b> " . $order->created_at->format('d.m.Y H:i') . "\n";
        $message .= "👤 <b>Клиент:</b> {$order->customer_name}\n";
        $message .= "📧 <b>Email:</b> {$order->customer_email}\n";
        $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($order->customer_phone) . "\n\n";
        $message .= "💰 <b>Сумма:</b> " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
        $message .= "📦 <b>Товаров:</b> {$order->total_quantity} шт.\n\n";
        
        if ($order->shipping_method) {
            $message .= "🚚 <b>Способ доставки:</b> {$order->shipping_method}\n";
        }
        if ($order->payment_method) {
            $message .= "💳 <b>Способ оплаты:</b> {$order->payment_method}\n";
        }
        $message .= "✅ <b>Статус оплаты:</b> " . ($order->payed ? 'Оплачен' : 'Не оплачен') . "\n";
        
        if ($order->shipping_address) {
            $message .= "📍 <b>Адрес:</b> {$order->shipping_address}\n";
        }
        if ($order->notes) {
            $message .= "📝 <b>Комментарий:</b> {$order->notes}\n";
        }
        
        return $message;
    }

    /**
     * Форматировать сообщение о заявке на отмену для Email
     */
    protected function formatCancellationRequestEmail(ShopOrder $order): string
    {
        $message = "Заявка на отмену оплаченного заказа\n\n";
        $message .= "Номер заказа: #{$order->order_number}\n";
        $message .= "Клиент: {$order->customer_name}\n";
        $message .= "Email: {$order->customer_email}\n";
        $message .= "Телефон: " . $this->formatPhoneForEmail($order->customer_phone) . "\n\n";
        $message .= "Сумма заказа: " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
        $message .= "\nТребуется обработка заявки на отмену.";
        
        return $message;
    }

    /**
     * Форматировать сообщение о заявке на отмену для Telegram
     */
    protected function formatCancellationRequestTelegram(ShopOrder $order): string
    {
        $message = "⚠️ <b>Заявка на отмену оплаченного заказа</b>\n\n";
        $message .= "📋 <b>Заказ #{$order->order_number}</b>\n";
        $message .= "📅 <b>Дата заказа:</b> " . $order->created_at->format('d.m.Y H:i') . "\n";
        $message .= "📅 <b>Дата заявки:</b> " . now()->format('d.m.Y H:i') . "\n\n";
        
        $message .= "👤 <b>Клиент:</b> {$order->customer_name}\n";
        $message .= "📧 <b>Email:</b> {$order->customer_email}\n";
        $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($order->customer_phone) . "\n\n";
        
        // Информация о доставке
        if ($order->deliveryMethod || $order->shipping_method) {
            $message .= "🚚 <b>Способ доставки:</b> " . ($order->deliveryMethod->name ?? $order->shipping_method ?? 'Не указано') . "\n";
        }
        if ($order->shipping_address) {
            $message .= "📍 <b>Адрес:</b> {$order->shipping_address}\n";
        }
        $message .= "\n";
        
        // Товары в заказе
        $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;
        if (is_array($items) && !empty($items)) {
            $message .= "🛍️ <b>Товары в заказе:</b>\n";
            foreach ($items as $index => $item) {
                $itemName = $item['good_name'] ?? 'Товар';
                $variationName = $item['variation_name'] ?? '';
                $quantity = $item['quantity'] ?? 1;
                $price = $item['price'] ?? 0;
                $total = $item['total'] ?? ($price * $quantity);
                
                $message .= ($index + 1) . ". {$itemName}";
                if ($variationName) {
                    $message .= " ({$variationName})";
                }
                $message .= " - {$quantity} шт. × " . number_format($price, 0, ',', ' ') . " ₽ = " . number_format($total, 0, ',', ' ') . " ₽\n";
            }
            $message .= "\n";
        }
        
        $message .= "💰 <b>Сумма заказа:</b> " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
        $message .= "\n⚠️ <b>Требуется обработка заявки на отмену!</b>";
        
        return $message;
    }

    /**
     * Форматировать сообщение об отмене заказа для Email
     */
    protected function formatOrderCancelledEmail(ShopOrder $order): string
    {
        $message = "Заказ отменен пользователем\n\n";
        $message .= "Номер заказа: #{$order->order_number}\n";
        $message .= "Клиент: {$order->customer_name}\n";
        $message .= "Email: {$order->customer_email}\n";
        $message .= "Телефон: " . $this->formatPhoneForEmail($order->customer_phone) . "\n\n";
        $message .= "Сумма заказа: " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
        $message .= "\nЗаказ был отменен пользователем.";
        
        return $message;
    }

    /**
     * Форматировать сообщение об отмене заказа для Telegram
     */
    protected function formatOrderCancelledTelegram(ShopOrder $order): string
    {
        $message = "❌ <b>Заказ отменен пользователем</b>\n\n";
        $message .= "📋 <b>Заказ #{$order->order_number}</b>\n";
        $message .= "📅 <b>Дата заказа:</b> " . $order->created_at->format('d.m.Y H:i') . "\n";
        $message .= "📅 <b>Дата отмены:</b> " . now()->format('d.m.Y H:i') . "\n\n";
        
        $message .= "👤 <b>Клиент:</b> {$order->customer_name}\n";
        $message .= "📧 <b>Email:</b> {$order->customer_email}\n";
        $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($order->customer_phone) . "\n\n";
        
        // Информация о доставке
        if ($order->deliveryMethod || $order->shipping_method) {
            $message .= "🚚 <b>Способ доставки:</b> " . ($order->deliveryMethod->name ?? $order->shipping_method ?? 'Не указано') . "\n";
        }
        if ($order->shipping_address) {
            $message .= "📍 <b>Адрес:</b> {$order->shipping_address}\n";
        }
        $message .= "\n";
        
        // Товары в заказе
        $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;
        if (is_array($items) && !empty($items)) {
            $message .= "🛍️ <b>Товары в заказе:</b>\n";
            foreach ($items as $index => $item) {
                $itemName = $item['good_name'] ?? 'Товар';
                $variationName = $item['variation_name'] ?? '';
                $quantity = $item['quantity'] ?? 1;
                $price = $item['price'] ?? 0;
                $total = $item['total'] ?? ($price * $quantity);
                
                $message .= ($index + 1) . ". {$itemName}";
                if ($variationName) {
                    $message .= " ({$variationName})";
                }
                $message .= " - {$quantity} шт. × " . number_format($price, 0, ',', ' ') . " ₽ = " . number_format($total, 0, ',', ' ') . " ₽\n";
            }
            $message .= "\n";
        }
        
        $message .= "💰 <b>Сумма заказа:</b> " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
        $message .= "\n❌ <b>Заказ был отменен пользователем. Товары возвращены на склад.</b>";
        
        return $message;
    }

    /**
     * Форматировать сообщение о предзаказе для Email
     */
    protected function formatPreorderCreatedEmail(ShopPreorder $preorder): string
    {
        $message = "Новый предзаказ товара\n\n";
        $message .= "Товар: {$preorder->good_name}\n";
        if ($preorder->variation_name) {
            $message .= "Вариация: {$preorder->variation_name}\n";
        }
        $message .= "Количество: {$preorder->quantity} шт.\n";
        $message .= "Цена: " . number_format($preorder->price, 0, ',', ' ') . " ₽\n";
        $message .= "Сумма: " . number_format($preorder->total, 0, ',', ' ') . " ₽\n\n";
        
        if ($preorder->customer_name) {
            $message .= "Клиент: {$preorder->customer_name}\n";
        }
        if ($preorder->customer_email) {
            $message .= "Email: {$preorder->customer_email}\n";
        }
        if ($preorder->customer_phone) {
            $message .= "Телефон: " . $this->formatPhoneForEmail($preorder->customer_phone) . "\n";
        }
        if ($preorder->notes) {
            $message .= "Комментарий: {$preorder->notes}\n";
        }
        
        $message .= "\nВремя создания: " . $preorder->created_at->format('d.m.Y H:i');
        
        return $message;
    }

    /**
     * Форматировать сообщение о предзаказе для Telegram
     */
    protected function formatPreorderCreatedTelegram(ShopPreorder $preorder): string
    {
        $message = "📦 <b>Новый предзаказ товара</b>\n\n";
        $message .= "🛍️ <b>Товар:</b> {$preorder->good_name}\n";
        if ($preorder->variation_name) {
            $message .= "🔧 <b>Вариация:</b> {$preorder->variation_name}\n";
        }
        $message .= "📊 <b>Количество:</b> {$preorder->quantity} шт.\n";
        $message .= "💰 <b>Цена:</b> " . number_format($preorder->price, 0, ',', ' ') . " ₽\n";
        $message .= "💵 <b>Сумма:</b> " . number_format($preorder->total, 0, ',', ' ') . " ₽\n\n";
        
        if ($preorder->customer_name) {
            $message .= "👤 <b>Клиент:</b> {$preorder->customer_name}\n";
        }
        if ($preorder->customer_email) {
            $message .= "📧 <b>Email:</b> {$preorder->customer_email}\n";
        }
        if ($preorder->customer_phone) {
            $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($preorder->customer_phone) . "\n";
        }
        if ($preorder->notes) {
            $message .= "📝 <b>Комментарий:</b> {$preorder->notes}\n";
        }
        
        $message .= "\n🕐 <b>Время:</b> " . $preorder->created_at->format('d.m.Y H:i');
        
        return $message;
    }

    /**
     * Форматировать сообщение о сообщении на сайте для Email
     */
    protected function formatSiteMessageEmail(SiteMessage $siteMessage): string
    {
        $message = "Новое сообщение на сайте\n\n";
        $message .= "Имя: {$siteMessage->name}\n";
        $message .= "Телефон: " . $this->formatPhoneForEmail($siteMessage->phone) . "\n";
        if ($siteMessage->message) {
            $message .= "Сообщение: {$siteMessage->message}\n";
        }
        $message .= "Тип: {$siteMessage->type}\n";
        $message .= "\nВремя отправки: " . $siteMessage->created_at->format('d.m.Y H:i');
        
        return $message;
    }

    /**
     * Форматировать сообщение о сообщении на сайте для Telegram
     */
    protected function formatSiteMessageTelegram(SiteMessage $siteMessage): string
    {
        // Разные сообщения для разных типов
        if ($siteMessage->type === 'callback') {
            $message = "📞 <b>Запрос обратного звонка</b>\n\n";
            $message .= "👤 <b>Имя:</b> {$siteMessage->name}\n";
            $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($siteMessage->phone) . "\n";
            if ($siteMessage->message) {
                $message .= "💭 <b>Комментарий:</b> {$siteMessage->message}\n";
            }
            $message .= "\n🕐 <b>Время:</b> " . $siteMessage->created_at->format('d.m.Y H:i');
            $message .= "\n\n⚠️ <b>Требуется срочный звонок клиенту!</b>";
        } else {
            $message = "💬 <b>Новое сообщение на сайте</b>\n\n";
            $message .= "👤 <b>Имя:</b> {$siteMessage->name}\n";
            $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($siteMessage->phone) . "\n";
            if ($siteMessage->message) {
                $message .= "💭 <b>Сообщение:</b> {$siteMessage->message}\n";
            }
            $message .= "\n🕐 <b>Время:</b> " . $siteMessage->created_at->format('d.m.Y H:i');
        }
        
        return $message;
    }
}

