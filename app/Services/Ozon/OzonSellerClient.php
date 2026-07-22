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
        $clientId = $this->normalizeCredential((string) $this->account->client_id);
        $apiKey = $this->normalizeCredential((string) $this->account->api_key);

        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['Client-Id' => $clientId, 'Api-Key' => $apiKey])
            ->connectTimeout(10)
            ->timeout(45)
            ->retry(3, 750, function ($exception) {
                if ($exception instanceof ConnectionException) return true;
                if (! $exception instanceof RequestException) return false;
                $status = $exception->response->status();
                return $status === 429 || $status >= 500;
            }, throw: false);
    }

    private function normalizeCredential(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\xEF\xBB\xBF");
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = trim(substr($value, 1, -1));
            }
        }
        return $value;
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
