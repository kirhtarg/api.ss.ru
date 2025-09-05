<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CallService
{
    protected $provider;
    protected $apiKey;
    protected $apiUrl;
    protected $from;

    protected $login;
    protected $password;

    public function __construct()
    {
        $this->provider = config('services.call.provider', 'voicepassword');
        
        switch ($this->provider) {
            case 'voicepassword':
                $this->apiKey = config('services.voicepassword.api_key');
                $this->apiUrl = config('services.voicepassword.api_url', 'https://api.voicepassword.ru/call');
                $this->from = config('services.voicepassword.from', 'SkateAndSnow');
                break;
            case 'loginbot':
                $this->apiKey = config('services.loginbot.api_key');
                $this->apiUrl = config('services.loginbot.api_url', 'https://api.loginbot.ru/call');
                $this->from = config('services.loginbot.from', 'SkateAndSnow');
                break;
            case 'unibell':
                $this->apiKey = config('services.unibell.api_key');
                $this->apiUrl = config('services.unibell.api_url', 'https://api.unibell.ru/call');
                $this->from = config('services.unibell.from', 'SkateAndSnow');
                break;
            case 'authcalls':
                $this->apiKey = config('services.authcalls.api_key');
                $this->apiUrl = config('services.authcalls.api_url', 'https://api.authcalls.net/call');
                $this->from = config('services.authcalls.from', 'SkateAndSnow');
                break;
            case 'smsprofi':
                $this->apiKey = config('services.smsprofi.api_key');
                $this->apiUrl = config('services.smsprofi.api_url', 'https://lcab.smsprofi.ru/json/v1.0/callpassword/send');
                $this->from = config('services.smsprofi.from', 'SkateAndSnow');
                break;
            default:
                throw new \Exception("Unsupported call provider: {$this->provider}");
        }
    }

    /**
     * Отправить звонок с кодом подтверждения
     */
    public function sendCallCode(string $phone, string $code): array
    {
        try {
            switch ($this->provider) {
                case 'voicepassword':
                    return $this->sendVoicePasswordCall($phone, $code);
                case 'loginbot':
                    return $this->sendLoginBotCall($phone, $code);
                case 'unibell':
                    return $this->sendUnibellCall($phone, $code);
                case 'authcalls':
                    return $this->sendAuthCallsCall($phone, $code);
                case 'smsprofi':
                    return $this->sendSmsProfiCall($phone, $code);
                default:
                    throw new \Exception("Unsupported call provider: {$this->provider}");
            }
        } catch (\Exception $e) {
            Log::error('Call service error', [
                'phone' => $phone, 
                'provider' => $this->provider, 
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Ошибка сервиса звонков: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Отправить звонок через Voice Password
     */
    private function sendVoicePasswordCall(string $phone, string $code): array
    {
        $response = Http::timeout(30)->post($this->apiUrl, [
            'api_key' => $this->apiKey,
            'phone' => $phone,
            'code' => $code,
            'sender' => $this->from
        ]);

        $data = $response->json();

        if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
            Log::info('Call sent successfully via Voice Password', ['phone' => $phone, 'code' => $code]);
            return [
                'success' => true,
                'message' => 'Звонок отправлен',
                'data' => $data
            ];
        } else {
            Log::error('Call sending failed via Voice Password', ['phone' => $phone, 'response' => $data]);
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Ошибка отправки звонка',
                'data' => $data
            ];
        }
    }

    /**
     * Отправить звонок через LoginBot
     */
    private function sendLoginBotCall(string $phone, string $code): array
    {
        $response = Http::timeout(30)->post($this->apiUrl, [
            'api_key' => $this->apiKey,
            'phone' => $phone,
            'code' => $code,
            'sender' => $this->from
        ]);

        $data = $response->json();

        if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
            Log::info('Call sent successfully via LoginBot', ['phone' => $phone, 'code' => $code]);
            return [
                'success' => true,
                'message' => 'Звонок отправлен',
                'data' => $data
            ];
        } else {
            Log::error('Call sending failed via LoginBot', ['phone' => $phone, 'response' => $data]);
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Ошибка отправки звонка',
                'data' => $data
            ];
        }
    }

    /**
     * Отправить звонок через Unibell
     */
    private function sendUnibellCall(string $phone, string $code): array
    {
        $response = Http::timeout(30)->post($this->apiUrl, [
            'api_key' => $this->apiKey,
            'phone' => $phone,
            'code' => $code,
            'sender' => $this->from
        ]);

        $data = $response->json();

        if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
            Log::info('Call sent successfully via Unibell', ['phone' => $phone, 'code' => $code]);
            return [
                'success' => true,
                'message' => 'Звонок отправлен',
                'data' => $data
            ];
        } else {
            Log::error('Call sending failed via Unibell', ['phone' => $phone, 'response' => $data]);
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Ошибка отправки звонка',
                'data' => $data
            ];
        }
    }

    /**
     * Отправить звонок через AuthCalls
     */
    private function sendAuthCallsCall(string $phone, string $code): array
    {
        $response = Http::timeout(30)->post($this->apiUrl, [
            'api_key' => $this->apiKey,
            'phone' => $phone,
            'code' => $code,
            'sender' => $this->from
        ]);

        $data = $response->json();

        if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
            Log::info('Call sent successfully via AuthCalls', ['phone' => $phone, 'code' => $code]);
            return [
                'success' => true,
                'message' => 'Звонок отправлен',
                'data' => $data
            ];
        } else {
            Log::error('Call sending failed via AuthCalls', ['phone' => $phone, 'response' => $data]);
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Ошибка отправки звонка',
                'data' => $data
            ];
        }
    }

    /**
     * Отправить звонок через SMSProfi.ru
     */
    private function sendSmsProfiCall(string $phone, string $code): array
    {
        // Согласно документации SMSProfi.ru для Callpassword
        // Генерируем уникальный ID для запроса
        $requestId = 'call_' . time() . '_' . substr(md5($phone . $code), 0, 8);
        
        // Подготавливаем данные согласно API документации
        // SMSProfi.ru ожидает номер без + в начале
        $recipientPhone = str_starts_with($phone, '+7') ? substr($phone, 1) : $phone;
        
        $requestData = [
            'recipient' => $recipientPhone,
            'id' => $requestId,
            'tags' => ['auth', 'callpassword']
        ];

        // Отправляем запрос с X-Token в заголовке
        $response = Http::timeout(30)->withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Token' => $this->apiKey
        ])->post($this->apiUrl, $requestData);

        $data = $response->json();

        if ($response->successful()) {
            // Проверяем успешность по структуре ответа SMSProfi
            if (isset($data['success']) && $data['success'] === true) {
                Log::info('Call sent successfully via SMSProfi', [
                    'original_phone' => $phone,
                    'recipient_phone' => $recipientPhone,
                    'request_id' => $requestId,
                    'call_id' => $data['result']['id'] ?? null,
                    'code' => $data['result']['code'] ?? null
                ]);
                return [
                    'success' => true,
                    'message' => 'Звонок отправлен',
                    'data' => [
                        'call_id' => $data['result']['id'] ?? null,
                        'code' => $data['result']['code'] ?? null,
                        'mobile_operator' => $data['result']['mobileOperator'] ?? null
                    ]
                ];
            } else {
                Log::error('Call sending failed via SMSProfi', [
                    'original_phone' => $phone,
                    'recipient_phone' => $recipientPhone,
                    'request_id' => $requestId,
                    'response' => $data
                ]);
                return [
                    'success' => false,
                    'message' => $data['error']['descr'] ?? 'Ошибка отправки звонка',
                    'error_code' => $data['error']['code'] ?? null,
                    'data' => $data
                ];
            }
        } else {
            Log::error('Call sending failed via SMSProfi - HTTP error', [
                'original_phone' => $phone,
                'recipient_phone' => $recipientPhone,
                'request_id' => $requestId,
                'status' => $response->status(),
                'response' => $data
            ]);
            return [
                'success' => false,
                'message' => 'HTTP ошибка: ' . $response->status() . ' - ' . ($data['error']['descr'] ?? 'Неизвестная ошибка'),
                'data' => $data
            ];
        }
    }

    /**
     * Проверить баланс
     */
    public function checkBalance(): array
    {
        try {
            $balanceUrl = str_replace('/call', '/balance', $this->apiUrl);
            
            $response = Http::timeout(30)->get($balanceUrl, [
                'api_key' => $this->apiKey
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
                return [
                    'success' => true,
                    'balance' => $data['balance'] ?? 0,
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $data['message'] ?? 'Ошибка получения баланса',
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
     * Получить информацию о провайдере
     */
    public function getProviderInfo(): array
    {
        return [
            'provider' => $this->provider,
            'api_url' => $this->apiUrl,
            'from' => $this->from
        ];
    }
}
