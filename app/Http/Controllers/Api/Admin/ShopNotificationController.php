<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopNotificationChannel;
use App\Models\ShopNotificationEvent;
use App\Models\ShopOrder;
use App\Models\ShopPreorder;
use App\Models\SiteMessage;
use App\Services\EmailNotificationService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopNotificationController extends Controller
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
     * Получить список всех каналов оповещений
     */
    public function index(): JsonResponse
    {
        try {
            $channels = ShopNotificationChannel::with('events')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $channels,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching notification channels: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения каналов оповещений',
            ], 500);
        }
    }

    /**
     * Получить канал оповещений по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $channel = ShopNotificationChannel::with('events')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $channel,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Канал оповещений не найден',
            ], 404);
        }
    }

    /**
     * Создать новый канал оповещений
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Подготовка данных для валидации
            $data = $request->all();

            // Преобразуем is_active в boolean если нужно
            if (isset($data['is_active'])) {
                $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($data['is_active'] === null) {
                    $data['is_active'] = true; // По умолчанию
                }
            }

            // Очищаем поля, которые не нужны для данного типа
            if ($data['type'] === 'email') {
                $data['telegram_chat_id'] = null;
                $data['telegram_bot_token'] = null;
                $data['telegram_bot_username'] = null;
            } else {
                $data['email'] = null;
            }

            $validator = Validator::make($data, [
                'type' => 'required|in:email,telegram',
                'name' => 'required|string|max:255',
                'email' => 'required_if:type,email|nullable|email|max:255',
                'telegram_chat_id' => 'required_if:type,telegram|nullable|string|max:255',
                'telegram_bot_token' => 'required_if:type,telegram|nullable|string|max:255',
                'telegram_bot_username' => 'nullable|string|max:255',
                'is_active' => 'sometimes|boolean',
                'description' => 'nullable|string',
                'skip_bot_check' => 'sometimes|boolean', // Флаг для пропуска проверки бота
                'events' => 'nullable|array',
                'events.*.event_type' => 'required|in:order_created,cancellation_request,order_cancelled,preorder_created,site_message,backup',
                'events.*.is_enabled' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $channel = ShopNotificationChannel::create([
                'type' => $data['type'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
                'telegram_bot_token' => $data['telegram_bot_token'] ?? null,
                'telegram_bot_username' => $data['telegram_bot_username'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'description' => $data['description'] ?? null,
                'settings' => $data['settings'] ?? [],
            ]);

            // Создаем события для канала
            if ($request->has('events') && is_array($request->events)) {
                foreach ($request->events as $eventData) {
                    ShopNotificationEvent::create([
                        'channel_id' => $channel->id,
                        'event_type' => $eventData['event_type'],
                        'is_enabled' => $eventData['is_enabled'] ?? true,
                    ]);
                }
            } else {
                // По умолчанию создаем все события как включенные
                $defaultEvents = [
                    'order_created',
                    'cancellation_request',
                    'order_cancelled',
                    'preorder_created',
                    'site_message',
                    'backup',
                ];
                foreach ($defaultEvents as $eventType) {
                    ShopNotificationEvent::create([
                        'channel_id' => $channel->id,
                        'event_type' => $eventType,
                        'is_enabled' => true,
                    ]);
                }
            }

            $channel->load('events');

            return response()->json([
                'success' => true,
                'message' => 'Канал оповещений создан',
                'data' => $channel,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating notification channel: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания канала оповещений: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить канал оповещений
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $channel = ShopNotificationChannel::findOrFail($id);

            // Подготовка данных для валидации
            $data = $request->all();

            // Преобразуем is_active в boolean если нужно
            if (isset($data['is_active'])) {
                $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($data['is_active'] === null) {
                    $data['is_active'] = $channel->is_active; // Оставляем текущее значение
                }
            }

            $validator = Validator::make($data, [
                'type' => 'sometimes|in:email,telegram',
                'name' => 'sometimes|string|max:255',
                'email' => 'required_if:type,email|nullable|email|max:255',
                'telegram_chat_id' => 'required_if:type,telegram|nullable|string|max:255',
                'telegram_bot_token' => 'required_if:type,telegram|nullable|string|max:255',
                'telegram_bot_username' => 'nullable|string|max:255',
                'is_active' => 'sometimes|boolean',
                'description' => 'nullable|string',
                'events' => 'nullable|array',
                'events.*.event_type' => 'required|in:order_created,cancellation_request,order_cancelled,preorder_created,site_message,backup',
                'events.*.is_enabled' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updateData = [
                'type' => $data['type'] ?? $channel->type,
                'name' => $data['name'] ?? $channel->name,
                'is_active' => $data['is_active'] ?? $channel->is_active,
                'description' => $data['description'] ?? $channel->description,
                'settings' => $data['settings'] ?? $channel->settings,
            ];

            // Обновляем поля в зависимости от типа
            if (($data['type'] ?? $channel->type) === 'email') {
                $updateData['email'] = $data['email'] ?? $channel->email;
                $updateData['telegram_chat_id'] = null;
                $updateData['telegram_bot_token'] = null;
                $updateData['telegram_bot_username'] = null;
            } else {
                $updateData['telegram_chat_id'] = $data['telegram_chat_id'] ?? $channel->telegram_chat_id;
                $updateData['telegram_bot_token'] = $data['telegram_bot_token'] ?? $channel->telegram_bot_token;
                $updateData['telegram_bot_username'] = $data['telegram_bot_username'] ?? $channel->telegram_bot_username;
                $updateData['email'] = null;
            }

            $channel->update($updateData);

            // Обновляем события
            if ($request->has('events') && is_array($request->events)) {
                // Удаляем старые события
                $channel->events()->delete();

                // Создаем новые события
                foreach ($request->events as $eventData) {
                    ShopNotificationEvent::create([
                        'channel_id' => $channel->id,
                        'event_type' => $eventData['event_type'],
                        'is_enabled' => $eventData['is_enabled'] ?? true,
                    ]);
                }
            }

            $channel->load('events');

            return response()->json([
                'success' => true,
                'message' => 'Канал оповещений обновлен',
                'data' => $channel,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating notification channel: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления канала оповещений: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить канал оповещений
     */
    public function destroy($id): JsonResponse
    {
        try {
            $channel = ShopNotificationChannel::findOrFail($id);
            $channel->delete();

            return response()->json([
                'success' => true,
                'message' => 'Канал оповещений удален',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting notification channel: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления канала оповещений',
            ], 500);
        }
    }

    /**
     * Переключить активность канала
     */
    public function toggleActive(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'is_active' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $channel = ShopNotificationChannel::findOrFail($id);
            $channel->is_active = $request->is_active;
            $channel->save();

            return response()->json([
                'success' => true,
                'message' => $request->is_active ? 'Канал активирован' : 'Канал деактивирован',
                'data' => $channel->load('events'),
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling notification channel active status: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка изменения статуса канала',
            ], 500);
        }
    }

    /**
     * Тестировать подключение Telegram
     */
    public function testTelegram(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'bot_token' => 'required|string',
                'chat_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Пытаемся получить информацию о боте (опционально, не блокируем если не удалось)
            $botInfo = null;
            try {
                $botInfo = $this->telegramService->getBotInfo($request->bot_token);
            } catch (\Exception $e) {
                // Игнорируем ошибки получения информации о боте - это не критично для тестирования
                Log::info('Could not get bot info for testing, proceeding anyway: '.$e->getMessage());
            }

            // Получаем информацию о сайте для тестового сообщения
            $siteName = config('app.name', 'Магазин');
            $siteUrl = config('app.url', '');

            // Пытаемся получить название сайта из настроек
            try {
                $siteNameSetting = \App\Models\Setting::where('key', 'site_name')->first();
                if ($siteNameSetting && $siteNameSetting->value) {
                    $siteName = $siteNameSetting->value;
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки получения настроек
            }

            // Получаем URL фронтенда
            try {
                $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', ''));
                if ($frontendUrl) {
                    $siteUrl = $frontendUrl;
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }

            // Отправляем тестовое сообщение (это главное действие тестирования)
            $testMessage = "✅ <b>Тестовое сообщение</b>\n\n";
            $testMessage .= "Это тестовое сообщение для проверки подключения Telegram бота.\n\n";
            $testMessage .= "📦 <b>Магазин:</b> {$siteName}\n";
            if ($siteUrl) {
                $testMessage .= "🌐 <b>Сайт:</b> {$siteUrl}\n";
            }
            $testMessage .= "\nЕсли вы получили это сообщение, значит канал оповещений настроен правильно!";

            $result = $this->telegramService->sendMessageWithToken(
                $request->bot_token,
                $request->chat_id,
                $testMessage
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Тестовое сообщение отправлено успешно',
                    'bot_info' => $botInfo && $botInfo['success'] ? $botInfo['data'] : null,
                ]);
            } else {
                // Если отправка не удалась, возвращаем детальную ошибку
                $errorMessage = $result['error'] ?? 'Unknown error';
                $isSslError = $result['is_ssl_error'] ?? false;

                // Улучшаем сообщение об ошибке
                if ($isSslError) {
                    $errorMessage = 'Ошибка SSL сертификата при отправке сообщения. Это проблема конфигурации сервера. Проверьте настройки SSL или обратитесь к администратору сервера.';
                } elseif (str_contains(strtolower($errorMessage), 'chat not found') ||
                          str_contains(strtolower($errorMessage), 'chat_id')) {
                    $errorMessage = 'Не удалось отправить сообщение. Проверьте правильность Chat ID. Убедитесь, что вы отправили хотя бы одно сообщение боту перед использованием Chat ID.';
                } elseif (str_contains(strtolower($errorMessage), 'unauthorized') ||
                          str_contains(strtolower($errorMessage), 'invalid token')) {
                    $errorMessage = 'Неверный токен бота. Проверьте правильность Bot Token.';
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось отправить тестовое сообщение',
                    'error' => $errorMessage,
                    'bot_info' => $botInfo && $botInfo['success'] ? $botInfo['data'] : null,
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Error testing Telegram connection: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка тестирования подключения: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Тестировать отправку Email
     */
    public function testEmail(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'event_type' => 'nullable|in:order_created,payment_received,payment_failed,cancellation_request,order_cancelled,preorder_created,site_message,callback,found_cheaper,backup',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $template = $request->input('event_type', 'order_created');
            $eventType = in_array($template, ['callback', 'found_cheaper'], true) ? 'site_message' : $template;
            $subject = $this->getTestEmailSubject($template);
            $result = $this->emailService->sendHtml(
                $request->email,
                $subject,
                $eventType,
                $this->buildTestEmailData($template)
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Тестовое email сообщение отправлено успешно',
                ]);
            } else {
                // Возвращаем более детальную информацию об ошибке
                $errorMessage = $result['error'] ?? 'Unknown error';

                // Проверяем, не связана ли ошибка с настройками SMTP
                if (str_contains(strtolower($errorMessage), 'connection') ||
                    str_contains(strtolower($errorMessage), 'smtp') ||
                    str_contains(strtolower($errorMessage), 'mail')) {
                    $errorMessage .= '. Проверьте настройки SMTP в .env файле (MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD)';
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось отправить тестовое email сообщение',
                    'error' => $errorMessage,
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Error testing Email connection: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка тестирования отправки email: '.$e->getMessage(),
            ], 500);
        }
    }

    private function buildTestEmailData(string $template): array
    {
        if ($template === 'preorder_created') {
            return ['preorder' => $this->makeTestPreorder()];
        }

        if (in_array($template, ['site_message', 'callback', 'found_cheaper'], true)) {
            return ['message' => $this->makeTestSiteMessage($template)];
        }

        $data = ['order' => $this->makeTestOrder()];

        if (in_array($template, ['payment_received', 'payment_failed'], true)) {
            $data['transaction'] = (object) [
                'id' => 'test-payment-'.now()->format('YmdHis'),
                'amount' => 12990,
                'status' => $template === 'payment_received' ? 'CONFIRMED' : 'REJECTED',
                'created_at' => now(),
            ];
            $data['payment_object'] = [
                'Status' => $template === 'payment_received' ? 'CONFIRMED' : 'REJECTED',
                'PaymentId' => 'test-payment-'.now()->format('YmdHis'),
                'Amount' => 1299000,
                'ErrorCode' => $template === 'payment_failed' ? 'TEST_ERROR' : null,
                'Message' => $template === 'payment_failed' ? 'Тестовая ошибка оплаты' : null,
            ];
        }

        return $data;
    }

    private function makeTestOrder(): ShopOrder
    {
        $order = new ShopOrder([
            'order_number' => 'TEST-'.now()->format('Ymd-His'),
            'customer_name' => 'Тестовый покупатель',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+7 900 000-00-00',
            'items' => [
                [
                    'good_name' => 'Тестовый товар',
                    'variation_name' => 'Размер M',
                    'quantity' => 1,
                    'price' => 12990,
                    'total' => 12990,
                ],
            ],
            'subtotal' => 12990,
            'total_amount' => 12990,
            'total_quantity' => 1,
            'sale_discount_amount' => 0,
            'registered_user_discount_amount' => 0,
            'promo_code_discount_amount' => 0,
            'birthday_discount_amount' => 0,
            'bonus_points_to_use' => 0,
            'delivery_cost' => 0,
            'payment_method' => 'Тестовая оплата',
            'shipping_method' => 'Тестовая доставка',
            'shipping_address' => 'г. Санкт-Петербург, тестовый адрес',
            'notes' => 'Это тестовое письмо из админки.',
            'payed' => true,
            'metadata' => [],
        ]);

        $order->created_at = Carbon::now();

        return $order;
    }

    private function makeTestPreorder(): ShopPreorder
    {
        $preorder = new ShopPreorder([
            'good_name' => 'Тестовый товар для предзаказа',
            'variation_name' => 'Цвет синий',
            'good_sku' => 'TEST-SKU',
            'quantity' => 1,
            'price' => 12990,
            'total' => 12990,
            'customer_name' => 'Тестовый покупатель',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+7 900 000-00-00',
            'notes' => 'Это тестовое письмо из админки.',
        ]);

        $preorder->created_at = Carbon::now();

        return $preorder;
    }

    private function makeTestSiteMessage(string $template): SiteMessage
    {
        $type = match ($template) {
            'callback' => 'callback',
            'found_cheaper' => 'found_cheaper',
            default => 'message',
        };

        $message = new SiteMessage([
            'name' => 'Тестовый клиент',
            'phone' => '+7 900 000-00-00',
            'email' => 'customer@example.com',
            'message' => 'Это тестовое уведомление из админки.',
            'type' => $type,
            'good_link' => $type === 'found_cheaper' ? 'https://example.com/test-good' : null,
            'good_price' => $type === 'found_cheaper' ? 11990 : null,
        ]);

        $message->created_at = Carbon::now();

        return $message;
    }

    private function getTestEmailSubject(string $template): string
    {
        return match ($template) {
            'payment_received' => 'Тест: успешная оплата заказа',
            'payment_failed' => 'Тест: неудачная оплата заказа',
            'cancellation_request' => 'Тест: заявка на отмену заказа',
            'order_cancelled' => 'Тест: заказ отменён',
            'preorder_created' => 'Тест: новый предзаказ',
            'callback' => 'Тест: запрос обратного звонка',
            'found_cheaper' => 'Тест: нашёл дешевле',
            'site_message' => 'Тест: сообщение на сайте',
            default => 'Тест: новый заказ',
        };
    }

    /**
     * Получить информацию о боте по токену
     */
    public function getBotInfo(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'bot_token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $botInfo = $this->telegramService->getBotInfo($request->bot_token);

            if ($botInfo['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $botInfo['data'],
                ]);
            } else {
                $isTimeout = $botInfo['is_timeout'] ?? false;
                $canSkipCheck = $botInfo['can_skip_check'] ?? true;

                $message = 'Не удалось получить информацию о боте. ';
                if ($isTimeout) {
                    $message .= 'Таймаут подключения к Telegram API. Проверьте интернет-соединение или попробуйте позже.';
                } else {
                    $message .= 'Возможные причины: блокировка Telegram API на сервере, проблемы с DNS, или временная недоступность сервиса. Вы можете сохранить канал без проверки.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error' => $botInfo['error'] ?? 'Unknown error',
                    'is_timeout' => $isTimeout,
                    'is_ssl_error' => $botInfo['is_ssl_error'] ?? false,
                    'can_skip_check' => $canSkipCheck,
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Error getting bot info: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения информации о боте: '.$e->getMessage(),
            ], 500);
        }
    }
}
