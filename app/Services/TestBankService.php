<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestBankService
{
    private $apiUrl;
    private $apiKey;
    private $merchantId;

    public function __construct()
    {
        $this->apiUrl = config('testbank.api_url', 'https://test-bank.example.com/api');
        $this->apiKey = config('testbank.api_key');
        $this->merchantId = config('testbank.merchant_id');
    }

    public function createPayment($orderData, $cardData)
    {
        $data = [
            'merchant_id' => $this->merchantId,
            'amount' => $orderData['amount'],
            'currency' => 'RUB',
            'order_id' => $orderData['order_id'],
            'description' => $orderData['description'],
            'card_number' => $cardData['number'],
            'card_expiry' => $cardData['expiry'],
            'card_cvv' => $cardData['cvv'],
            'cardholder_name' => $cardData['name'],
            'email' => $cardData['email'] ?? null,
            'return_url' => $orderData['return_url'],
            'webhook_url' => $orderData['webhook_url']
        ];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json'
        ])->post("{$this->apiUrl}/payments", $data);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('Ошибка создания платежа: ' . $response->body());
    }

    public function getPaymentStatus($paymentId)
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}"
        ])->get("{$this->apiUrl}/payments/{$paymentId}");

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('Ошибка получения статуса платежа');
    }

    public function refundPayment($paymentId, $amount = null)
    {
        $data = ['payment_id' => $paymentId];
        if ($amount) {
            $data['amount'] = $amount;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json'
        ])->post("{$this->apiUrl}/refunds", $data);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('Ошибка возврата платежа');
    }

    public function validateCard($cardData)
    {
        // Валидация номера карты по алгоритму Луна
        $number = preg_replace('/\D/', '', $cardData['number']);
        
        if (strlen($number) < 13 || strlen($number) > 19) {
            return false;
        }

        $sum = 0;
        $length = strlen($number);
        
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $number[$length - $i - 1];
            
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
