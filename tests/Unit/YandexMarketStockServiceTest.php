<?php

namespace Tests\Unit;

use App\Services\YandexMarketStockService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class YandexMarketStockServiceTest extends TestCase
{
    public function test_api_key_uses_dedicated_header_without_prefix(): void
    {
        $headers = $this->headers(['auth_type' => 'api_key', 'auth_token' => 'ACMA:test:key']);

        $this->assertSame('ACMA:test:key', $headers['Api-Key']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    #[DataProvider('oauthTokens')]
    public function test_oauth_uses_authorization_header(string $token, string $expected): void
    {
        $headers = $this->headers(['auth_type' => 'oauth', 'auth_token' => $token]);

        $this->assertSame($expected, $headers['Authorization']);
        $this->assertArrayNotHasKey('Api-Key', $headers);
    }

    public static function oauthTokens(): array
    {
        return [
            'raw token' => ['token-value', 'OAuth token-value'],
            'oauth prefix' => ['OAuth token-value', 'OAuth token-value'],
            'bearer prefix' => ['Bearer token-value', 'Bearer token-value'],
        ];
    }

    private function headers(array $settings): array
    {
        $method = new ReflectionMethod(YandexMarketStockService::class, 'apiHeaders');

        return $method->invoke(new YandexMarketStockService(), $settings);
    }
}
