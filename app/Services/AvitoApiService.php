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
            throw new \Exception('Avito API credentials not configured.');
        }

        $response = Http::withoutVerifying()
            ->asForm()
            ->post($this->baseUrl . '/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if ($response->failed()) {
            Log::error('Avito API Token Error', $response->json());
            throw new \Exception('Failed to get Avito access token: ' . ($response->json()['error_description'] ?? 'Unknown error'));
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
            ->withToken($token)
            ->get($this->baseUrl . '/autoload/v1/user-docs/tree');

        if ($response->failed()) {
            Log::error('Avito API Tree Error', $response->json());
            throw new \Exception('Failed to fetch Avito category tree.');
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
