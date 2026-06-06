<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AvitoApiService
{
    protected $clientId;
    protected $clientSecret;
    protected $baseUrl = 'https://api.avito.ru';
    protected $storagePath = 'avito/category_tree.json';

    public function __construct()
    {
        $this->clientId = Setting::where('key', 'avito_client_id')->value('value');
        $this->clientSecret = Setting::where('key', 'avito_api_key')->value('value');
    }

    /**
     * Получить токен доступа
     */
    public function getAccessToken()
    {
        if (!$this->clientId || !$this->clientSecret) {
            throw new \Exception('Не указаны Client ID и API key Авито в настройках.', 422);
        }

        $response = Http::withoutVerifying()
            ->timeout(30)
            ->asForm()
            ->post($this->baseUrl . '/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if ($response->failed()) {
            $payload = $response->json() ?: [];
            Log::error('Avito API Token Error', [
                'status' => $response->status(),
                'response' => $payload ?: $response->body(),
            ]);

            $message = $payload['error_description'] ?? $payload['message'] ?? $payload['error'] ?? 'Авито отклонил данные авторизации';
            throw new \Exception('Не удалось авторизоваться в API Авито: ' . $message, $response->status());
        }

        return $response->json()['access_token'];
    }

    /**
     * Получить дерево категорий из API Авито
     */
    public function fetchCategoryTree()
    {
        $token = $this->getAccessToken();

        $response = Http::withoutVerifying()
            ->timeout(60)
            ->withToken($token)
            ->get($this->baseUrl . '/autoload/v1/user-docs/tree');

        if ($response->failed()) {
            $payload = $response->json() ?: [];
            Log::error('Avito API Tree Error', [
                'status' => $response->status(),
                'response' => $payload ?: $response->body(),
            ]);

            $message = $payload['message'] ?? $payload['error_description'] ?? $payload['error'] ?? 'Авито не вернул дерево категорий';
            throw new \Exception('Не удалось получить дерево категорий Авито: ' . $message, $response->status());
        }

        $res = $response->json();
        $tree = $res['categories'] ?? $res;

        // Кэшируем локально
        Storage::put($this->storagePath, json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $tree;
    }

    /**
     * Получить кэшированное дерево категорий
     */
    public function getCachedTree()
    {
        if (!Storage::exists($this->storagePath)) {
            return $this->fetchCategoryTree();
        }

        return json_decode(Storage::get($this->storagePath), true);
    }
}
