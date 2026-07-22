<?php

namespace App\Services\YandexMarket;

use App\Models\ShopYandexMarketAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YandexMarketClient
{
    public function __construct(private readonly ShopYandexMarketAccount $account) {}

    public function get(string $path, array $query = []): array
    {
        return $this->decode($this->request()->get($this->url($path), $query), $path);
    }

    public function post(string $path, array $payload = [], array $query = []): array
    {
        return $this->decode($this->request()->post($this->url($path).($query ? '?'.http_build_query($query) : ''), $payload), $path);
    }

    public function put(string $path, array $payload = []): array
    {
        return $this->decode($this->request()->put($this->url($path), $payload), $path);
    }

    public function campaigns(): array
    {
        $response = $this->get('/v2/campaigns');
        return (array) data_get($response, 'campaigns', data_get($response, 'result.campaigns', []));
    }

    public function tokenInfo(): array
    {
        return (array) data_get($this->post('/v2/auth/token'), 'result.apiKey', []);
    }

    public function categoryTree(bool $refresh = false): array
    {
        $key = 'yandex-market:category-tree:ru';
        if ($refresh) Cache::forget($key);

        return Cache::remember($key, now()->addHours(12), function () {
            return (array) data_get($this->post('/v2/categories/tree', ['language' => 'RU']), 'result', []);
        });
    }

    public function categoryParameters(int $categoryId, bool $refresh = false): array
    {
        $key = "yandex-market:category-parameters:{$this->account->business_id}:{$categoryId}";
        if ($refresh) Cache::forget($key);

        return Cache::remember($key, now()->addHours(6), function () use ($categoryId) {
            $query = [];
            if ($this->account->business_id) $query['businessId'] = (int) $this->account->business_id;
            return (array) data_get($this->post("/v2/category/{$categoryId}/parameters", [], $query), 'result', []);
        });
    }

    private function request(): PendingRequest
    {
        $token = trim((string) $this->account->api_key);
        if ($token === '') throw new RuntimeException('Не указан API-ключ Яндекс Маркета.');

        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['Api-Key' => $token])
            ->timeout(90)
            ->retry(2, 500, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->account->api_url ?: 'https://api.partner.market.yandex.ru'), '/').'/'.ltrim($path, '/');
    }

    private function decode(Response $response, string $path): array
    {
        $body = $response->json();
        if (! $response->successful()) {
            $message = data_get($body, 'errors.0.message') ?: data_get($body, 'message') ?: $response->body();
            throw new RuntimeException("Яндекс Маркет {$path}: HTTP {$response->status()}: {$message}", $response->status());
        }

        return is_array($body) ? $body : [];
    }
}
