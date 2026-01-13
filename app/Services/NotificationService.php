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
        // Загружаем связь с товаром, если есть good_id
        if ($message->good_id) {
            $message->load('good:id,name,slug');
        }
        
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
            'site_message' => match($data['message']->type) {
                'callback' => "Запрос обратного звонка от {$data['message']->name}",
                'found_cheaper' => "Нашел дешевле от {$data['message']->name}",
                default => "Новое сообщение на сайте от {$data['message']->name}"
            },
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
            'payment_received' => $this->formatPaymentReceivedTelegram($data['order'], $data['transaction'], $data['payment_object']),
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

        // Добавляем список товаров
        $itemsList = $this->formatOrderItems($order, false);
        if (!empty($itemsList)) {
            $message .= "Состав заказа:\n";
            $message .= $itemsList . "\n\n";
        }

        // Добавляем комментарий пользователя, если есть
        if ($order->notes) {
            $message .= "Комментарий: {$order->notes}\n\n";
        }
        
        if ($order->shipping_method) {
            $message .= "Доставка: {$order->shipping_method}\n";
        }
        if ($order->payment_method) {
            $message .= "Оплата: {$order->payment_method}\n";
        }
        if ($order->shipping_address) {
            $message .= "Адрес: {$order->shipping_address}\n";
        }
        
        $message .= "\nВремя создания: " . $order->created_at->format('d.m.Y H:i');
        
        // Проверяем, включена ли двухэтапная оплата и требуется ли одобрение
        $twoStagePay = \App\Models\Setting::where('key', 'two_stage_pay')->first();
        $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);
        
        if ($isTwoStagePay && !$order->pay_agree) {
            $message .= "\n\n⚠️ ВНИМАНИЕ: Включен режим двухэтапной оплаты!\n";
            $message .= "Необходимо проверить наличие товаров в заказе и одобрить оплату в админ-панели.\n";
            $message .= "Клиент сможет оплатить заказ только после вашего одобрения.";
        }
        
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

        // Добавляем список товаров
        $itemsList = $this->formatOrderItems($order, true);
        if (!empty($itemsList)) {
            $message .= "🛒 <b>Состав заказа:</b>\n";
            $message .= $itemsList . "\n\n";
        }

        // Добавляем комментарий пользователя, если есть
        if ($order->notes) {
            $message .= "💬 <b>Комментарий:</b> {$order->notes}\n\n";
        }
        
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
        
        // Проверяем, включена ли двухэтапная оплата и требуется ли одобрение
        $twoStagePay = \App\Models\Setting::where('key', 'two_stage_pay')->first();
        $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);
        
        if ($isTwoStagePay && !$order->pay_agree) {
            $message .= "\n\n⚠️ <b>ВНИМАНИЕ: Включен режим двухэтапной оплаты!</b>\n";
            $message .= "🔍 Необходимо проверить наличие товаров в заказе и одобрить оплату в админ-панели.\n";
            $message .= "💳 Клиент сможет оплатить заказ только после вашего одобрения.";
        }
        
        return $message;
    }

    /**
     * Форматировать сообщение об успешной оплате заказа для Telegram
     */
    protected function formatPaymentReceivedTelegram(ShopOrder $order, $transaction, array $paymentObject): string
    {
        $message = "💰 <b>Заказ оплачен!</b>\n\n";
        $message .= "📋 <b>Заказ #{$order->order_number}</b>\n";
        $message .= "📅 <b>Дата оплаты:</b> " . now()->format('d.m.Y H:i') . "\n";
        $message .= "👤 <b>Клиент:</b> {$order->customer_name}\n";
        $message .= "📧 <b>Email:</b> {$order->customer_email}\n";
        $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($order->customer_phone) . "\n\n";
        $message .= "💰 <b>Сумма оплаты:</b> " . number_format($paymentObject['amount']['value'] ?? $order->total_amount, 0, ',', ' ') . " ₽\n";
        $message .= "💳 <b>Способ оплаты:</b> " . ($paymentObject['payment_method'] ?? $order->payment_method ?? 'Неизвестен') . "\n";
        $message .= "🆔 <b>ID платежа:</b> " . ($paymentObject['id'] ?? 'Неизвестен') . "\n\n";

        $message .= "✅ <b>Заказ готов к обработке!</b>\n";
        $message .= "📦 Можно приступать к сборке и отправке товара.";

        return $message;
    }

    /**
     * Форматировать список товаров для уведомлений
     */
    protected function formatOrderItems(ShopOrder $order, bool $forTelegram = false): string
    {
        // Получаем товары напрямую из поля items заказа
        $items = $order->items;

        if (!$items) {
            return '';
        }

        // Если items - строка JSON, декодируем её
        if (is_string($items)) {
            $items = json_decode($items, true);
        }

        if (!is_array($items) || empty($items)) {
            return '';
        }

        $formattedItems = [];

        foreach ($items as $item) {
            $itemName = $item['good_name'] ?? $item['name'] ?? 'Товар';
            if (!empty($item['variation_name'])) {
                $itemName .= ' (' . $item['variation_name'] . ')';
            }

            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;
            $total = $item['total'] ?? ($price * $quantity);

            if ($forTelegram) {
                // Для Telegram используем жирный текст и эмодзи
                $formattedItems[] = "• <b>{$itemName}</b> - {$quantity} шт. × " . number_format($price, 0, ',', ' ') . " ₽ = " . number_format($total, 0, ',', ' ') . " ₽";
            } else {
                // Для Email обычный текст
                $formattedItems[] = "• {$itemName} - {$quantity} шт. × " . number_format($price, 0, ',', ' ') . " ₽ = " . number_format($total, 0, ',', ' ') . " ₽";
            }
        }

        // Добавляем доставку, если есть
        if ($order->delivery_cost > 0) {
            if ($forTelegram) {
                $formattedItems[] = "🚚 <b>Доставка</b> - " . number_format($order->delivery_cost, 0, ',', ' ') . " ₽";
            } else {
                $formattedItems[] = "• Доставка - " . number_format($order->delivery_cost, 0, ',', ' ') . " ₽";
            }
        }

        return implode("\n", $formattedItems);
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
        if ($siteMessage->type === 'found_cheaper') {
            $message = "Нашел дешевле\n\n";
            $message .= "Имя: {$siteMessage->name}\n";
            $message .= "Email: {$siteMessage->email}\n";
            if ($siteMessage->phone) {
                $message .= "Телефон: " . $this->formatPhoneForEmail($siteMessage->phone) . "\n";
            }
            
            // Информация о товаре со страницы
            if ($siteMessage->good && $siteMessage->good->name) {
                $message .= "\n--- Товар со страницы ---\n";
                $message .= "Название: {$siteMessage->good->name}\n";
                if ($siteMessage->good->slug) {
                    // Получаем путь каталога из настроек
                    $catalogPathSetting = \App\Models\Setting::where('key', 'shop_catalog_path')->first();
                    $catalogPath = $catalogPathSetting && $catalogPathSetting->value 
                        ? '/' . ltrim($catalogPathSetting->value, '/') 
                        : '/catalog';
                    
                    // Формируем URL товара
                    $baseUrl = config('app.frontend_url', config('app.url', 'https://skateandsnow.ru'));
                    $goodUrl = $baseUrl . $catalogPath . '/' . $siteMessage->good->slug;
                    $message .= "Ссылка: {$goodUrl}\n";
                }
            }
            
            if ($siteMessage->good_link) {
                $message .= "\n--- Ссылка на аналогичный товар ---\n";
                $message .= "Ссылка: {$siteMessage->good_link}\n";
            }
            if ($siteMessage->good_price) {
                $message .= "Указанная цена: " . number_format($siteMessage->good_price, 2, ',', ' ') . " ₽\n";
            }
            if ($siteMessage->message) {
                $message .= "\nСообщение: {$siteMessage->message}\n";
            }
            $message .= "\nВремя отправки: " . $siteMessage->created_at->format('d.m.Y H:i');
        } else {
            $message = "Новое сообщение на сайте\n\n";
            $message .= "Имя: {$siteMessage->name}\n";
            $message .= "Телефон: " . $this->formatPhoneForEmail($siteMessage->phone) . "\n";
            if ($siteMessage->email) {
                $message .= "Email: {$siteMessage->email}\n";
            }
            if ($siteMessage->message) {
                $message .= "Сообщение: {$siteMessage->message}\n";
            }
            $message .= "Тип: {$siteMessage->type}\n";
            $message .= "\nВремя отправки: " . $siteMessage->created_at->format('d.m.Y H:i');
        }
        
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
        } elseif ($siteMessage->type === 'found_cheaper') {
            $message = "💰 <b>Нашел дешевле</b>\n\n";
            $message .= "👤 <b>Имя:</b> {$siteMessage->name}\n";
            $message .= "📧 <b>Email:</b> {$siteMessage->email}\n";
            if ($siteMessage->phone) {
                $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($siteMessage->phone) . "\n";
            }
            
            // Информация о товаре со страницы
            if ($siteMessage->good && $siteMessage->good->name) {
                $message .= "\n🛍️ <b>Товар со страницы:</b>\n";
                $message .= "📦 <b>Название:</b> {$siteMessage->good->name}\n";
                if ($siteMessage->good->slug) {
                    // Получаем путь каталога из настроек
                    $catalogPathSetting = \App\Models\Setting::where('key', 'shop_catalog_path')->first();
                    $catalogPath = $catalogPathSetting && $catalogPathSetting->value 
                        ? '/' . ltrim($catalogPathSetting->value, '/') 
                        : '/catalog';
                    
                    // Формируем URL товара
                    $baseUrl = config('app.frontend_url', config('app.url', 'https://skateandsnow.ru'));
                    $goodUrl = $baseUrl . $catalogPath . '/' . $siteMessage->good->slug;
                    $message .= "🔗 <b>Ссылка:</b> {$goodUrl}\n";
                }
            }
            
            if ($siteMessage->good_link) {
                $message .= "\n🔗 <b>Ссылка на аналогичный товар:</b> {$siteMessage->good_link}\n";
            }
            if ($siteMessage->good_price) {
                $message .= "💵 <b>Указанная цена:</b> " . number_format($siteMessage->good_price, 2, ',', ' ') . " ₽\n";
            }
            if ($siteMessage->message) {
                $message .= "💭 <b>Сообщение:</b> {$siteMessage->message}\n";
            }
            $message .= "\n🕐 <b>Время:</b> " . $siteMessage->created_at->format('d.m.Y H:i');
            $message .= "\n\n⚠️ <b>Требуется рассмотрение предложения!</b>";
        } else {
            $message = "💬 <b>Новое сообщение на сайте</b>\n\n";
            $message .= "👤 <b>Имя:</b> {$siteMessage->name}\n";
            $message .= "📞 <b>Телефон:</b> " . $this->formatPhoneForTelegram($siteMessage->phone) . "\n";
            if ($siteMessage->email) {
                $message .= "📧 <b>Email:</b> {$siteMessage->email}\n";
            }
            if ($siteMessage->message) {
                $message .= "💭 <b>Сообщение:</b> {$siteMessage->message}\n";
            }
            $message .= "\n🕐 <b>Время:</b> " . $siteMessage->created_at->format('d.m.Y H:i');
        }
        
        return $message;
    }

    /**
     * Уведомление об успешной оплате заказа
     */
    public function notifyPaymentReceived(ShopOrder $order, $transaction, array $paymentObject): void
    {
        $this->sendNotification('payment_received', [
            'order' => $order,
            'transaction' => $transaction,
            'payment_object' => $paymentObject
        ]);
    }
}

