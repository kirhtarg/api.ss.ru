<?php

namespace App\Services\Ozon;

use App\Models\ShopOzonAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OzonSellerClient
{
    public function __construct(private readonly ShopOzonAccount $account) {}

    public function post(string $path, array $payload = []): array
    {
        $request = $this->request();
        $response = $payload === []
            ? $request->send('POST', $this->url($path), ['body' => '{}'])
            : $request->post($this->url($path), $payload);
        return $this->decode($response, $path);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['Client-Id' => $this->account->client_id, 'Api-Key' => $this->account->api_key])
            ->connectTimeout(10)
            ->timeout(45)
            ->retry(3, 750, function ($exception) {
                if ($exception instanceof ConnectionException) return true;
                if (! $exception instanceof RequestException) return false;
                $status = $exception->response->status();
                return $status === 429 || $status >= 500;
            }, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim($this->account->api_url, '/').'/'.ltrim($path, '/');
    }

    private function decode(Response $response, string $path): array
    {
        $data = $response->json();
        if (! $response->successful()) {
            $message = data_get($data, 'message') ?? data_get($data, 'error.message') ?? $response->body();
            throw new RuntimeException("Ozon {$path}: HTTP {$response->status()}: ".mb_substr((string) $message, 0, 1000));
        }
        return is_array($data) ? $data : [];
    }
}
