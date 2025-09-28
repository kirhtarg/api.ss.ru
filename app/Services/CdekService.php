<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CdekService
{
    private $apiUrl;
    private $clientId;
    private $clientSecret;
    private $accessToken;

    public function __construct()
    {
        $this->apiUrl = config('cdek.api_url', 'https://api.cdek.ru/v2');
        $this->clientId = config('cdek.client_id');
        $this->clientSecret = config('cdek.client_secret');
    }

    public function getAccessToken()
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = Http::post("{$this->apiUrl}/oauth/token", [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $this->accessToken = $data['access_token'];
            return $this->accessToken;
        }

        throw new \Exception('Ошибка получения токена СДЭК');
    }

    public function searchCities($query)
    {
        $token = $this->getAccessToken();
        
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}"
        ])->get("{$this->apiUrl}/location/cities", [
            'size' => 20,
            'name' => $query
        ]);

        return $response->json();
    }

    public function searchStreets($cityCode, $query)
    {
        $token = $this->getAccessToken();
        
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}"
        ])->get("{$this->apiUrl}/location/streets", [
            'city_code' => $cityCode,
            'size' => 20,
            'name' => $query
        ]);

        return $response->json();
    }

    public function calculateDelivery($from, $to, $packages)
    {
        $token = $this->getAccessToken();
        
        $data = [
            'from_location' => $from,
            'to_location' => $to,
            'packages' => $packages
        ];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json'
        ])->post("{$this->apiUrl}/calculator/tariff", $data);

        return $response->json();
    }

    public function getPvzList($cityCode)
    {
        $token = $this->getAccessToken();
        
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}"
        ])->get("{$this->apiUrl}/deliverypoints", [
            'city_code' => $cityCode,
            'type' => 'PVZ'
        ]);

        return $response->json();
    }
}
