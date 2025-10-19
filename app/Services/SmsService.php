<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected $provider;
    protected $apiId;
    protected $apiUrl;
    protected $from;
    protected $login;
    protected $password;

    public function __construct()
    {
        $this->provider = config('services.sms.provider', 'smsru');
        
        switch ($this->provider) {
            case 'smsprofi':
                $this->login = config('services.smsprofi.login');
                $this->password = config('services.smsprofi.password');
                $this->apiUrl = config('services.smsprofi.api_url', 'https://api.smsprofi.ru/send');
                $this->from = config('services.smsprofi.from', 'SkateAndSnow');
                break;
            case 'notifylk':
                $this->login = config('services.notifylk.user_id');
                $this->password = config('services.notifylk.api_key');
                $this->apiUrl = config('services.notifylk.api_url', 'https://app.notify.lk/api/v1/send');
                $this->from = config('services.notifylk.from', 'NotifyDEMO');
                break;
            default: // smsru
                $this->apiId = config('services.smsru.api_id');
                $this->apiUrl = config('services.smsru.api_url', 'https://sms.ru/sms/send');
                $this->from = config('services.smsru.from', 'SkateAndSnow');
                break;
        }
    }

    /**
     * Отправить SMS с кодом
     */
    public function sendSmsCode(string $phone, string $code): array
    {
        try {
            switch ($this->provider) {
                case 'smsprofi':
                    return $this->sendSmsProfi($phone, $code);
                case 'notifylk':
                    return $this->sendNotifyLk($phone, $code);
                default: // smsru
                    return $this->sendSmsRu($phone, $code);
            }
        } catch (\Exception $e) {
            Log::error('SMS service error', ['phone' => $phone, 'provider' => $this->provider, 'error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Ошибка сервиса SMS: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Отправить SMS через SMS.ru
     */
    private function sendSmsRu(string $phone, string $code): array
    {
        $response = Http::timeout(30)->post($this->apiUrl, [
            'api_id' => $this->apiId,
            'to' => $phone,
            'msg' => "Код подтверждения: {$code}. Не сообщайте его никому.",
            'from' => $this->from,
            'json' => 1
        ]);

        $data = $response->json();

        if ($response->successful() && isset($data['status']) && $data['status'] === 'OK') {
            Log::info('SMS sent successfully via SMS.ru', ['phone' => $phone, 'code' => $code]);
            return [
                'success' => true,
                'message' => 'SMS отправлено',
                'data' => $data
            ];
        } else {
            Log::error('SMS sending failed via SMS.ru', ['phone' => $phone, 'response' => $data]);
            return [
                'success' => false,
                'message' => $data['status_text'] ?? 'Ошибка отправки SMS',
                'data' => $data
            ];
        }
    }

    /**
     * Отправить SMS через SMSProfi.ru
     */
    private function sendSmsProfi(string $phone, string $code): array
    {
        $response = Http::timeout(30)->post($this->apiUrl, [
            'login' => $this->login,
            'password' => $this->password,
            'phone' => $phone,
            'text' => "Код подтверждения: {$code}. Не сообщайте его никому.",
            'sender' => $this->from
        ]);

        $data = $response->json();

        if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
            Log::info('SMS sent successfully via SMSProfi', ['phone' => $phone, 'code' => $code]);
            return [
                'success' => true,
                'message' => 'SMS отправлено',
                'data' => $data
            ];
        } else {
            Log::error('SMS sending failed via SMSProfi', ['phone' => $phone, 'response' => $data]);
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Ошибка отправки SMS',
                'data' => $data
            ];
        }
    }

    /**
     * Отправить SMS через Notify.lk
     */
    private function sendNotifyLk(string $phone, string $code): array
    {
        // Форматируем номер для Notify.lk (убираем +7 и добавляем 94)
        $formattedPhone = $this->formatPhoneForNotifyLk($phone);
        
        $response = Http::timeout(30)->get($this->apiUrl, [
            'user_id' => $this->login,
            'api_key' => $this->password,
            'sender_id' => $this->from,
            'to' => $formattedPhone,
            'message' => "Код подтверждения: {$code}. Не сообщайте его никому."
        ]);

        $data = $response->json();

        if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
            Log::info('SMS sent successfully via Notify.lk', ['phone' => $phone, 'code' => $code]);
            return [
                'success' => true,
                'message' => 'SMS отправлено',
                'data' => $data
            ];
        } else {
            Log::error('SMS sending failed via Notify.lk', ['phone' => $phone, 'response' => $data]);
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Ошибка отправки SMS',
                'data' => $data
            ];
        }
    }

    /**
     * Форматировать номер для Notify.lk
     */
    private function formatPhoneForNotifyLk(string $phone): string
    {
        // Убираем все нецифровые символы
        $cleaned = preg_replace('/\D/', '', $phone);
        
        // Если номер начинается с 7, заменяем на 94
        if (str_starts_with($cleaned, '7') && strlen($cleaned) === 11) {
            return '94' . substr($cleaned, 1);
        }
        
        // Если номер уже в правильном формате, возвращаем как есть
        return $cleaned;
    }

    /**
     * Отправить звонок с кодом (Flash Call)
     */
    public function sendCallCode(string $phone, string $code): array
    {
        try {
            // Для SMS.ru используем API для звонков
            $response = Http::timeout(30)->post('https://sms.ru/call', [
                'api_id' => $this->apiId,
                'to' => $phone,
                'json' => 1
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'OK') {
                // Сохраняем код для проверки
                \Illuminate\Support\Facades\Cache::put("call_code_{$phone}", $code, 300);
                
                Log::info('Call sent successfully', ['phone' => $phone, 'code' => $code]);
                return [
                    'success' => true,
                    'message' => 'Звонок отправлен',
                    'data' => $data
                ];
            } else {
                Log::error('Call sending failed', ['phone' => $phone, 'response' => $data]);
                return [
                    'success' => false,
                    'message' => $data['status_text'] ?? 'Ошибка отправки звонка',
                    'data' => $data
                ];
            }

        } catch (\Exception $e) {
            Log::error('Call service error', ['phone' => $phone, 'error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Ошибка сервиса звонков: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Проверить баланс SMS.ru
     */
    public function checkBalance(): array
    {
        try {
            $response = Http::timeout(30)->get('https://sms.ru/my/balance', [
                'api_id' => $this->apiId,
                'json' => 1
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'OK') {
                return [
                    'success' => true,
                    'balance' => $data['balance'] ?? 0,
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $data['status_text'] ?? 'Ошибка получения баланса',
                    'data' => $data
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Ошибка сервиса: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получить информацию о номере телефона
     */
    public function getPhoneInfo(string $phone): array
    {
        try {
            $response = Http::timeout(30)->get('https://sms.ru/my/phones', [
                'api_id' => $this->apiId,
                'json' => 1
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'OK') {
                return [
                    'success' => true,
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $data['status_text'] ?? 'Ошибка получения информации',
                    'data' => $data
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Ошибка сервиса: ' . $e->getMessage()
            ];
        }
    }
}
