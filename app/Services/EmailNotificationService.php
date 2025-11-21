<?php

namespace App\Services;

use App\Models\ShopNotificationChannel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailNotificationService
{
    /**
     * Отправить email уведомление
     */
    public function send(string $email, string $subject, string $message, array $data = []): array
    {
        try {
            // Проверяем настройки почты
            $mailer = config('mail.default');
            if ($mailer === 'log') {
                // Если используется log драйвер, письмо будет записано в лог, но не отправлено
                
                return [
                    'success' => true,
                    'message' => 'Email будет записан в лог (используется log драйвер). Для реальной отправки настройте SMTP в .env'
                ];
            }

            Mail::raw($message, function ($mail) use ($email, $subject) {
                $mail->to($email)
                    ->subject($subject);
            });

            return [
                'success' => true,
                'message' => 'Email отправлен успешно'
            ];
        } catch (\Exception $e) {
            // Определяем тип ошибки для более понятного сообщения
            $errorMessage = $e->getMessage();
            $errorClass = get_class($e);
            
            // Проверяем, связана ли ошибка с SMTP подключением
            if (str_contains(strtolower($errorMessage), 'connection') || 
                str_contains(strtolower($errorMessage), 'smtp') ||
                str_contains(strtolower($errorMessage), 'stream_socket_client') ||
                str_contains(strtolower($errorMessage), 'failed to connect') ||
                str_contains(strtolower($errorMessage), 'connection timed out')) {
                $errorMessage = 'Ошибка подключения к SMTP серверу: ' . $errorMessage;
                $errorMessage .= '. Проверьте настройки MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD в .env';
            } elseif (str_contains(strtolower($errorMessage), 'authentication') ||
                      str_contains(strtolower($errorMessage), 'login') ||
                      str_contains(strtolower($errorMessage), 'password') ||
                      str_contains(strtolower($errorMessage), '535') ||
                      str_contains(strtolower($errorMessage), 'auth')) {
                $errorMessage = 'Ошибка аутентификации SMTP: ' . $errorMessage;
                $errorMessage .= '. Проверьте MAIL_USERNAME и MAIL_PASSWORD в .env';
            }
            
            Log::error('Email notification error: ' . $e->getMessage(), [
                'email' => $email,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'error_class' => $errorClass,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $errorMessage
            ];
        }
    }

    /**
     * Отправить уведомление через канал
     */
    public function sendViaChannel(ShopNotificationChannel $channel, string $subject, string $message, array $data = []): array
    {
        if ($channel->type !== 'email') {
            return [
                'success' => false,
                'error' => 'Канал не является email каналом'
            ];
        }

        if (!$channel->email) {
            return [
                'success' => false,
                'error' => 'Email адрес не указан для канала'
            ];
        }

        return $this->send($channel->email, $subject, $message, $data);
    }

    /**
     * Отправить HTML email уведомление через канал
     */
    public function sendHtmlViaChannel(ShopNotificationChannel $channel, string $subject, string $eventType, array $data = []): array
    {
        if ($channel->type !== 'email') {
            return [
                'success' => false,
                'error' => 'Канал не является email каналом'
            ];
        }

        if (!$channel->email) {
            return [
                'success' => false,
                'error' => 'Email адрес не указан для канала'
            ];
        }

        return $this->sendHtml($channel->email, $subject, $eventType, $data);
    }

    /**
     * Отправить HTML email уведомление
     */
    public function sendHtml(string $email, string $subject, string $eventType, array $data = []): array
    {
        try {
            // Проверяем настройки почты
            $mailer = config('mail.default');
            if ($mailer === 'log') {
                return [
                    'success' => true,
                    'message' => 'Email будет записан в лог (используется log драйвер). Для реальной отправки настройте SMTP в .env'
                ];
            }

            // Получаем информацию о сайте
            $siteInfo = \App\Services\SiteInfoService::getSiteInfoForEmail();
            
            // Для site_message определяем шаблон до подготовки данных
            if ($eventType === 'site_message') {
                $view = $this->getSiteMessageView($data);
                if (!$view) {
                    // Если шаблон не найден, используем обычный текст
                    // Используем обычный текст вместо HTML
                    $textMessage = "Новое сообщение на сайте\n\n";
                    if (isset($data['message']) && is_object($data['message'])) {
                        $msg = $data['message'];
                        $textMessage .= "Имя: " . ($msg->name ?? 'не указано') . "\n";
                        $textMessage .= "Телефон: " . ($msg->phone ?? 'не указано') . "\n";
                        if (isset($msg->message) && $msg->message) {
                            $textMessage .= "Сообщение: {$msg->message}\n";
                        }
                        $textMessage .= "Тип: " . ($msg->type ?? 'не указан') . "\n";
                    }
                    return $this->send($email, $subject, $textMessage, $data);
                }
            } else {
                // Определяем шаблон в зависимости от типа события
                $view = match($eventType) {
                    'order_created' => 'emails.order-notification',
                    'cancellation_request' => 'emails.cancellation-request-notification',
                    'order_cancelled' => 'emails.order-cancelled-notification',
                    'preorder_created' => 'emails.preorder-notification',
                    default => null
                };
            }
            
            // Подготавливаем данные для шаблона
            // Переименовываем 'message' в 'siteMessage' для избежания конфликта с Laravel Mail
            $viewData = $data;
            if (isset($viewData['message'])) {
                $viewData['siteMessage'] = $viewData['message'];
                // Добавляем отформатированный телефон с гиперссылкой
                if (isset($viewData['siteMessage']->phone)) {
                    $viewData['siteMessagePhoneLink'] = $this->formatPhoneForEmailHtml($viewData['siteMessage']->phone);
                }
                unset($viewData['message']);
            }
            // Добавляем отформатированные телефоны и email для заказов
            if (isset($viewData['order'])) {
                if (isset($viewData['order']->customer_phone)) {
                    $viewData['orderPhoneLink'] = $this->formatPhoneForEmailHtml($viewData['order']->customer_phone);
                }
                if (isset($viewData['order']->customer_email)) {
                    $viewData['orderEmailLink'] = '<a href="mailto:' . htmlspecialchars($viewData['order']->customer_email, ENT_QUOTES, 'UTF-8') . '" style="color: #667eea; text-decoration: none;">' . htmlspecialchars($viewData['order']->customer_email, ENT_QUOTES, 'UTF-8') . '</a>';
                }
            }
            // Добавляем отформатированные телефоны для предзаказов
            if (isset($viewData['preorder']) && isset($viewData['preorder']->customer_phone)) {
                $viewData['preorderPhoneLink'] = $this->formatPhoneForEmailHtml($viewData['preorder']->customer_phone);
            }
            $viewData['siteInfo'] = $siteInfo;

            if (!$view) {
                // Если шаблон не найден, используем обычный текст
                return $this->send($email, $subject, 'Уведомление о событии: ' . $eventType, $data);
            }
            
            // Проверяем существование шаблона
            if (!view()->exists($view)) {
                Log::error("View template does not exist: {$view}", [
                    'event_type' => $eventType,
                    'view' => $view
                ]);
                return [
                    'success' => false,
                    'error' => "Шаблон {$view} не найден"
                ];
            }

            try {
                Mail::send($view, $viewData, function ($mail) use ($email, $subject) {
                    $mail->to($email)
                        ->subject($subject);
                });
                
                Log::info("Email sent successfully", [
                    'email' => $email,
                    'subject' => $subject,
                    'view' => $view
                ]);
            } catch (\Exception $mailException) {
                Log::error("Mail::send exception", [
                    'email' => $email,
                    'subject' => $subject,
                    'view' => $view,
                    'error' => $mailException->getMessage(),
                    'trace' => $mailException->getTraceAsString()
                ]);
                throw $mailException;
            }

            return [
                'success' => true,
                'message' => 'Email отправлен успешно'
            ];
        } catch (\Exception $e) {
            // Определяем тип ошибки для более понятного сообщения
            $errorMessage = $e->getMessage();
            
            // Проверяем, связана ли ошибка с SMTP подключением
            if (str_contains(strtolower($errorMessage), 'connection') || 
                str_contains(strtolower($errorMessage), 'smtp') ||
                str_contains(strtolower($errorMessage), 'stream_socket_client') ||
                str_contains(strtolower($errorMessage), 'failed to connect') ||
                str_contains(strtolower($errorMessage), 'connection timed out')) {
                $errorMessage = 'Ошибка подключения к SMTP серверу: ' . $errorMessage;
                $errorMessage .= '. Проверьте настройки MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD в .env';
            } elseif (str_contains(strtolower($errorMessage), 'authentication') ||
                      str_contains(strtolower($errorMessage), 'login') ||
                      str_contains(strtolower($errorMessage), 'password') ||
                      str_contains(strtolower($errorMessage), '535') ||
                      str_contains(strtolower($errorMessage), 'auth')) {
                $errorMessage = 'Ошибка аутентификации SMTP: ' . $errorMessage;
                $errorMessage .= '. Проверьте MAIL_USERNAME и MAIL_PASSWORD в .env';
            }
            
            Log::error('HTML Email notification error: ' . $e->getMessage(), [
                'email' => $email,
                'subject' => $subject,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $errorMessage
            ];
        }
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
     * Получить шаблон для сообщения на сайте в зависимости от типа
     */
    protected function getSiteMessageView(array $data): ?string
    {
        if (!isset($data['message'])) {
            Log::warning('Site message data missing in getSiteMessageView', [
                'data_keys' => array_keys($data),
                'data' => $data
            ]);
            return null;
        }

        $message = $data['message'];
        
        // Проверяем, что это объект SiteMessage
        if (!is_object($message)) {
            Log::warning('Invalid message object in getSiteMessageView', [
                'message_type' => gettype($message),
                'message' => $message
            ]);
            return null;
        }
        
        // Получаем тип сообщения
        $messageType = null;
        
        // Пробуем разные способы получения типа
        if (property_exists($message, 'type')) {
            $messageType = $message->type;
        } elseif (method_exists($message, 'getAttribute')) {
            $messageType = $message->getAttribute('type');
        } elseif (method_exists($message, 'getType')) {
            $messageType = $message->getType();
        } elseif (method_exists($message, '__get')) {
            $messageType = $message->type;
        } else {
            // Пробуем получить через массив, если это Eloquent модель
            try {
                $attributes = $message->getAttributes();
                if (isset($attributes['type'])) {
                    $messageType = $attributes['type'];
                } elseif (isset($message->attributes['type'])) {
                    $messageType = $message->attributes['type'];
                }
            } catch (\Exception $e) {
                // Игнорируем ошибку
            }
        }
        
        if (!$messageType) {
            return null;
        }
        
        // Определяем шаблон в зависимости от типа сообщения
        $view = match($messageType) {
            'callback' => 'emails.callback-notification',
            'message' => 'emails.site-message-notification',
            default => null
        };
        
        return $view;
    }
}

