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
        return $this->sendMessageWithToken($this->botToken, $chatId, $message, $options);
    }

    /**
     * Отправить сообщение в Telegram с указанным токеном
     */
    public function sendMessageWithToken(string $botToken, string $chatId, string $message, array $options = []): array
    {
        $maxRetries = 2; // Количество повторных попыток
        $retryDelay = 2; // Задержка между попытками в секундах
        
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $apiUrl = "https://api.telegram.org/bot{$botToken}";
                
                // Настройка HTTP клиента с увеличенным таймаутом
                // Увеличиваем таймаут для DNS резолвинга и подключения
                $httpClient = Http::timeout(60); // Общий таймаут (включая DNS резолвинг)
                
                // Отключение проверки SSL для локальной разработки или если явно указано в конфиге
                $verifySsl = config('telegram.verify_ssl', true);
                $isLocal = config('app.env') === 'local' || config('app.debug') === true;
                
                // Для локальной разработки автоматически отключаем проверку SSL
                if ($verifySsl === false || $isLocal) {
                    $httpClient = $httpClient->withoutVerifying();
                }
                
                $response = $httpClient->post("{$apiUrl}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    ...$options
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if ($attempt > 0) {
                        Log::info("Telegram message sent successfully after {$attempt} retry attempts");
                    }
                    return [
                        'success' => true,
                        'message_id' => $data['result']['message_id'] ?? null,
                        'data' => $data
                    ];
                } else {
                    // Если это не таймаут, не повторяем
                    if ($response->status() !== 408 && !str_contains(strtolower($response->body()), 'timeout')) {
                        return [
                            'success' => false,
                            'error' => $response->body(),
                            'status' => $response->status()
                        ];
                    }
                    // Если таймаут и есть еще попытки, продолжаем цикл
                    if ($attempt < $maxRetries) {
                        Log::warning("Telegram API timeout, retrying... (attempt " . ($attempt + 1) . "/{$maxRetries})");
                        sleep($retryDelay);
                        continue;
                    }
                    return [
                        'success' => false,
                        'error' => $response->body(),
                        'status' => $response->status()
                    ];
                }
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $errorMessage = $e->getMessage();
                $isSslError = str_contains(strtolower($errorMessage), 'ssl') || 
                             str_contains(strtolower($errorMessage), 'certificate') ||
                             str_contains(strtolower($errorMessage), 'cURL error 60');
                $isTimeout = str_contains(strtolower($errorMessage), 'timed out') || 
                            str_contains(strtolower($errorMessage), 'timeout') ||
                            str_contains($errorMessage, 'cURL error 28');
                
                // Если это таймаут и есть еще попытки, повторяем
                if ($isTimeout && $attempt < $maxRetries) {
                    Log::warning("Telegram connection timeout, retrying... (attempt " . ($attempt + 1) . "/{$maxRetries})");
                    sleep($retryDelay);
                    continue;
                }
                
                // Если это SSL ошибка, не повторяем
                if ($isSslError) {
                    $isLocal = config('app.env') === 'local' || config('app.debug') === true;
                    if ($isLocal) {
                        $errorMessage = 'Ошибка SSL сертификата на localhost. Для локальной разработки проверка SSL автоматически отключена при следующей попытке.';
                    } else {
                        $errorMessage = 'Ошибка SSL сертификата при подключении к Telegram API. Проверьте настройки SSL на сервере.';
                    }
                } elseif ($isTimeout) {
                    $errorMessage = 'Таймаут подключения к Telegram API после ' . ($maxRetries + 1) . ' попыток. Проверьте интернет-соединение или попробуйте позже.';
                } else {
                    $errorMessage = 'Ошибка подключения к Telegram API. Проверьте интернет-соединение или попробуйте позже.';
                }
                
                Log::error('Telegram send message connection error: ' . $e->getMessage(), [
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries
                ]);
                return [
                    'success' => false,
                    'error' => $errorMessage,
                    'is_ssl_error' => $isSslError,
                    'is_timeout' => $isTimeout
                ];
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $isSslError = str_contains(strtolower($errorMessage), 'ssl') || 
                             str_contains(strtolower($errorMessage), 'certificate') ||
                             str_contains(strtolower($errorMessage), 'cURL error 60');
                $isTimeout = str_contains(strtolower($errorMessage), 'timed out') || 
                            str_contains(strtolower($errorMessage), 'timeout') ||
                            str_contains($errorMessage, 'cURL error 28');
                
                // Если это таймаут и есть еще попытки, повторяем
                if ($isTimeout && $attempt < $maxRetries) {
                    Log::warning("Telegram timeout error, retrying... (attempt " . ($attempt + 1) . "/{$maxRetries})");
                    sleep($retryDelay);
                    continue;
                }
                
                Log::error('Telegram send message error: ' . $e->getMessage(), [
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries
                ]);
                return [
                    'success' => false,
                    'error' => $errorMessage,
                    'is_ssl_error' => $isSslError,
                    'is_timeout' => $isTimeout
                ];
            }
        }
        
        // Если все попытки исчерпаны
        return [
            'success' => false,
            'error' => 'Не удалось отправить сообщение в Telegram после ' . ($maxRetries + 1) . ' попыток.'
        ];
    }

    /**
     * Получить информацию о боте
     */
    public function getBotInfo(?string $botToken = null): array
    {
        try {
            $token = $botToken ?? $this->botToken;
            $apiUrl = "https://api.telegram.org/bot{$token}";
            
            // Настройка HTTP клиента
            $httpClient = Http::timeout(30);
            
            // Отключение проверки SSL для локальной разработки или если явно указано в конфиге
            $verifySsl = config('telegram.verify_ssl', true);
            $isLocal = config('app.env') === 'local' || config('app.debug') === true;
            
            // Для локальной разработки автоматически отключаем проверку SSL
            if ($verifySsl === false || $isLocal) {
                $httpClient = $httpClient->withoutVerifying();
            }
            
            $response = $httpClient->get("{$apiUrl}/getMe");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'data' => $data['result'] ?? null
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response->body(),
                    'status' => $response->status()
                ];
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $errorMessage = $e->getMessage();
            $isTimeout = str_contains(strtolower($errorMessage), 'timed out') || 
                        str_contains(strtolower($errorMessage), 'timeout') ||
                        str_contains(strtolower($errorMessage), 'cURL error 28');
            
            $isSslError = str_contains(strtolower($errorMessage), 'ssl') || 
                         str_contains(strtolower($errorMessage), 'certificate') ||
                         str_contains(strtolower($errorMessage), 'cURL error 60');
            
            if ($isTimeout) {
                $errorMessage = 'Таймаут подключения к Telegram API. Возможные причины: проблемы с интернет-соединением, блокировка Telegram API на сервере, или временная недоступность сервиса. Попробуйте позже.';
            } elseif ($isSslError) {
                $errorMessage = 'Ошибка SSL сертификата при подключении к Telegram API. Это проблема конфигурации сервера, не связанная с вашим ботом. Вы можете сохранить канал без проверки - он будет работать при отправке уведомлений. Для решения проблемы обратитесь к администратору сервера.';
            } else {
                $errorMessage = 'Ошибка подключения к Telegram API: ' . $errorMessage . '. Возможные причины: блокировка Telegram API на сервере, проблемы с DNS, или временная недоступность сервиса. Вы можете сохранить канал без проверки - он будет работать при отправке уведомлений.';
            }
            
            Log::error('Telegram get bot info connection error: ' . $e->getMessage(), [
                'is_timeout' => $isTimeout,
                'is_ssl_error' => $isSslError,
                'error_class' => get_class($e)
            ]);
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'is_timeout' => $isTimeout,
                'is_ssl_error' => $isSslError,
                'can_skip_check' => true
            ];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $isTimeout = str_contains(strtolower($errorMessage), 'timed out') || 
                        str_contains(strtolower($errorMessage), 'timeout') ||
                        str_contains(strtolower($errorMessage), 'cURL error 28');
            
            $isSslError = str_contains(strtolower($errorMessage), 'ssl') || 
                         str_contains(strtolower($errorMessage), 'certificate') ||
                         str_contains(strtolower($errorMessage), 'cURL error 60');
            
            // Улучшаем сообщение об ошибке
            if ($isTimeout) {
                $errorMessage = 'Таймаут подключения к Telegram API. Проверьте интернет-соединение или попробуйте позже.';
            } elseif ($isSslError) {
                $errorMessage = 'Ошибка SSL сертификата при подключении к Telegram API. Это проблема конфигурации сервера, не связанная с вашим ботом. Вы можете сохранить канал без проверки - он будет работать при отправке уведомлений. Для решения проблемы обратитесь к администратору сервера.';
            } elseif (str_contains(strtolower($errorMessage), 'connection') || 
                      str_contains(strtolower($errorMessage), 'resolve') ||
                      str_contains(strtolower($errorMessage), 'dns')) {
                $errorMessage = 'Ошибка подключения к Telegram API: ' . $errorMessage . '. Возможные причины: блокировка Telegram API на сервере, проблемы с DNS, или временная недоступность сервиса. Вы можете сохранить канал без проверки.';
            }
            
            Log::error('Telegram get bot info error: ' . $e->getMessage(), [
                'is_timeout' => $isTimeout,
                'is_ssl_error' => $isSslError,
                'error_class' => get_class($e)
            ]);
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'is_timeout' => $isTimeout,
                'is_ssl_error' => $isSslError,
                'can_skip_check' => true // Всегда можно пропустить проверку
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
