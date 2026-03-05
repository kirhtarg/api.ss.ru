<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DolyamePartnerService
{
    protected string $baseUrl;

    protected string $login;

    protected string $password;

    protected bool $verify;

    protected ?string $caBundle;

    public function __construct(array $settings)
    {
        $this->baseUrl = rtrim($settings['api_url'] ?? config('services.dolyame.api_url', 'https://partner.dolyame.ru/v1'), '/');
        $this->login = $settings['dolyame_login'] ?? '';
        $this->password = $settings['dolyame_password'] ?? '';
        $this->verify = $settings['verify_ssl'] ?? config('services.dolyame.verify_ssl', true);
        $this->caBundle = $settings['ca_bundle_path'] ?? config('services.dolyame.ca_bundle_path');
    }

    protected function http()
    {
        $auth = base64_encode($this->login.':'.$this->password);
        $headers = [
            'Authorization' => 'Basic '.$auth,
            'Content-Type' => 'application/json',
            'X-Correlation-ID' => (string) Str::uuid(),
        ];
        $options = [
            'verify' => app()->environment('production') ? ($this->caBundle ?: $this->verify) : false,
            'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            'connect_timeout' => 10,
        ];

        return Http::timeout(30)->retry(2, 1000)->withOptions($options)->withHeaders($headers);
    }

    public function createOrder(string $orderId, float $amount, array $items, string $notificationUrl, string $successUrl, string $failUrl): array
    {
        $payload = [
            'order' => [
                'id' => $orderId,
                'amount' => $amount,
                'items' => array_map(function ($it) {
                    return [
                        'name' => $it['name'],
                        'quantity' => (float) $it['quantity'],
                        'price' => (float) $it['price'],
                    ];
                }, $items),
            ],
            'notification_url' => $notificationUrl,
            'fail_url' => $failUrl,
            'success_url' => $successUrl,
        ];
        $url = $this->baseUrl.'/orders/create';
        Log::info('Dolyame create order request', ['url' => $url, 'payload' => $payload]);
        $res = $this->http()->post($url, $payload);
        $data = $res->json();
        Log::info('Dolyame create order response', ['status' => $res->status(), 'body' => $data]);
        if ($res->successful() && isset($data['link'])) {
            return ['success' => true, 'link' => $data['link'], 'response' => $data];
        }

        return ['success' => false, 'message' => $data['message'] ?? 'Dolyame: create failed', 'response' => $data];
    }

    public function commitOrder(string $orderId, float $amount, array $items): array
    {
        $payload = [
            'amount' => $amount,
            'items' => array_map(function ($it) {
                return [
                    'name' => $it['name'],
                    'quantity' => (float) $it['quantity'],
                    'price' => (float) $it['price'],
                ];
            }, $items),
        ];
        $url = $this->baseUrl.'/orders/'.urlencode($orderId).'/commit';
        $res = $this->http()->post($url, $payload);
        $data = $res->json();

        return $res->successful() ? ['success' => true, 'response' => $data] : ['success' => false, 'response' => $data];
    }

    public function refundOrder(string $orderId, float $amount, array $returnedItems): array
    {
        $payload = [
            'amount' => $amount,
            'returned_items' => array_map(function ($it) {
                return [
                    'name' => $it['name'],
                    'quantity' => (float) $it['quantity'],
                    'price' => (float) $it['price'],
                ];
            }, $returnedItems),
        ];
        $url = $this->baseUrl.'/orders/'.urlencode($orderId).'/refund';
        $res = $this->http()->post($url, $payload);
        $data = $res->json();

        return $res->successful() ? ['success' => true, 'response' => $data] : ['success' => false, 'response' => $data];
    }

    public function cancelOrder(string $orderId): array
    {
        $url = $this->baseUrl.'/orders/'.urlencode($orderId).'/cancel';
        $res = $this->http()->post($url, []);
        $data = $res->json();

        return $res->successful() ? ['success' => true, 'response' => $data] : ['success' => false, 'response' => $data];
    }

    public function getOrderInfo(string $orderId): array
    {
        $url = $this->baseUrl.'/orders/'.urlencode($orderId).'/info';
        $res = $this->http()->get($url);
        $data = $res->json();

        return $res->successful() ? ['success' => true, 'response' => $data] : ['success' => false, 'response' => $data];
    }
}
