<?php

namespace App\Services;

use App\Models\TelegramNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private $botToken;
    private $adminChatId;
    private $apiUrl;

    public function __construct()
    {
        $this->botToken = config('telegram.bot_token');
        $this->adminChatId = config('telegram.admin_chat_id');
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    /**
     * Отправить сообщение в Telegram
     */
    public function sendMessage(string $chatId, string $message, array $options = []): array
    {
        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                ...$options
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message_id' => $data['result']['message_id'] ?? null,
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response->body(),
                    'status' => $response->status()
                ];
            }
        } catch (\Exception $e) {
            Log::error('Telegram send message error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Отправить уведомление о новом заказе администратору
     */
    public function sendOrderNotification(TelegramNotification $notification): array
    {
        $order = $notification->order;
        $message = $this->formatOrderMessage($order, $notification->type);
        
        $result = $this->sendMessage($this->adminChatId, $message);
        
        if ($result['success']) {
            $notification->markAsSent();
        } else {
            $notification->markAsFailed($result['error'] ?? 'Unknown error');
        }
        
        return $result;
    }

    /**
     * Отправить уведомление клиенту
     */
    public function sendCustomerNotification(TelegramNotification $notification): array
    {
        $result = $this->sendMessage($notification->chat_id, $notification->message);
        
        if ($result['success']) {
            $notification->markAsSent();
        } else {
            $notification->markAsFailed($result['error'] ?? 'Unknown error');
        }
        
        return $result;
    }

    /**
     * Форматировать сообщение о заказе
     */
    private function formatOrderMessage($order, string $type): string
    {
        $emoji = $this->getOrderEmoji($type);
        $status = $this->getOrderStatusText($type);
        
        $message = "{$emoji} <b>{$status}</b>\n\n";
        $message .= "📋 <b>Заказ #{$order->order_number}</b>\n";
        $message .= "👤 <b>Клиент:</b> {$order->customer_name}\n";
        $message .= "📧 <b>Email:</b> {$order->customer_email}\n";
        $message .= "📞 <b>Телефон:</b> {$order->customer_phone}\n\n";
        
        $message .= "💰 <b>Сумма:</b> " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
        $message .= "📦 <b>Товаров:</b> {$order->total_quantity} шт.\n\n";
        
        if ($order->shipping_method) {
            $message .= "🚚 <b>Доставка:</b> {$order->shipping_method}\n";
        }
        
        if ($order->payment_method) {
            $message .= "💳 <b>Оплата:</b> {$order->payment_method}\n";
        }
        
        if ($order->shipping_address) {
            $message .= "📍 <b>Адрес:</b> {$order->shipping_address}\n";
        }
        
        if ($order->notes) {
            $message .= "📝 <b>Комментарий:</b> {$order->notes}\n";
        }
        
        $message .= "\n🕐 <b>Время:</b> " . $order->created_at->format('d.m.Y H:i');
        
        return $message;
    }

    /**
     * Получить эмодзи для типа уведомления
     */
    private function getOrderEmoji(string $type): string
    {
        return match($type) {
            'order_created' => '🆕',
            'order_updated' => '🔄',
            'order_cancelled' => '❌',
            'payment_success' => '✅',
            'payment_failed' => '❌',
            default => '📢'
        };
    }

    /**
     * Получить текст статуса заказа
     */
    private function getOrderStatusText(string $type): string
    {
        return match($type) {
            'order_created' => 'Новый заказ',
            'order_updated' => 'Заказ обновлен',
            'order_cancelled' => 'Заказ отменен',
            'payment_success' => 'Оплата прошла успешно',
            'payment_failed' => 'Ошибка оплаты',
            default => 'Уведомление о заказе'
        };
    }

    /**
     * Создать уведомление в базе данных
     */
    public function createNotification(string $type, ?int $orderId, string $chatId, string $message, array $data = []): TelegramNotification
    {
        return TelegramNotification::create([
            'type' => $type,
            'order_id' => $orderId,
            'chat_id' => $chatId,
            'message' => $message,
            'data' => $data,
            'status' => 'pending'
        ]);
    }

    /**
     * Отправить уведомление администратору о новом заказе
     */
    public function notifyAdminNewOrder($order): TelegramNotification
    {
        $message = $this->formatOrderMessage($order, 'order_created');
        
        $notification = $this->createNotification(
            'order_created',
            $order->id,
            $this->adminChatId,
            $message
        );
        
        $this->sendOrderNotification($notification);
        
        return $notification;
    }

    /**
     * Отправить уведомление клиенту
     */
    public function notifyCustomer(string $chatId, string $type, ?int $orderId, string $message, array $data = []): TelegramNotification
    {
        $notification = $this->createNotification($type, $orderId, $chatId, $message, $data);
        
        $this->sendCustomerNotification($notification);
        
        return $notification;
    }

    /**
     * Обработать все ожидающие уведомления
     */
    public function processPendingNotifications(): int
    {
        $notifications = TelegramNotification::pending()
            ->where('attempts', '<', 3) // Максимум 3 попытки
            ->orderBy('created_at')
            ->limit(10) // Обрабатываем по 10 за раз
            ->get();

        $processed = 0;

        foreach ($notifications as $notification) {
            $notification->incrementAttempts();
            
            if ($notification->order_id) {
                $result = $this->sendOrderNotification($notification);
            } else {
                $result = $this->sendCustomerNotification($notification);
            }
            
            $processed++;
            
            // Небольшая задержка между отправками
            usleep(100000); // 0.1 секунды
        }

        return $processed;
    }
}
