<?php

namespace Tests\Unit;

use App\Services\OzonDeliveryService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OzonDeliveryServiceTest extends TestCase
{
    public function test_testcookie_redirect_replays_original_post_with_cookie_and_bearer_token(): void
    {
        Http::fakeSequence()
            ->push('', 307, [
                'Location' => '/v1/delivery-point/list',
                'Set-Cookie' => ['testcookie=accepted; Path=/; Secure'],
            ])
            ->push(['delivery_points' => []], 200);

        $payload = ['pagination' => ['cursor' => null, 'limit' => 1]];
        $response = app(OzonDeliveryService::class)->requestWithToken(
            'oauth-test-token',
            '/v1/delivery-point/list',
            $payload
        );

        self::assertSame(200, $response->status());
        self::assertSame(['delivery_points' => []], $response->json());
        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://api-delivery.ozon.ru/v1/delivery-point/list' &&
            $request->method() === 'POST' &&
            $request->data() === $payload &&
            $request->hasHeader('Authorization', 'Bearer oauth-test-token') &&
            $request->hasHeader('Cookie', 'testcookie=accepted')
        );
        Http::assertSentCount(2);
    }

    public function test_testcookie_redirect_cannot_forward_credentials_to_another_host(): void
    {
        Http::fakeSequence()->push('', 302, [
            'Location' => 'https://example.com/steal-token',
            'Set-Cookie' => ['testcookie=accepted; Path=/; Secure'],
        ]);

        try {
            app(OzonDeliveryService::class)->requestWithToken(
                'oauth-test-token',
                '/v1/delivery-point/list',
                ['pagination' => ['cursor' => null, 'limit' => 1]]
            );
            self::fail('Expected an unsafe redirect to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('небезопасный redirect', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_pickup_point_search_text_supports_nested_address_fields(): void
    {
        $service = app(OzonDeliveryService::class);
        $method = new \ReflectionMethod($service, 'pickupPointSearchText');
        $method->setAccessible(true);

        $searchText = $method->invoke($service, [
            'delivery_point_id' => 123,
            'address' => ['city' => 'Санкт-Петербург', 'street' => 'Невский проспект'],
            'location' => ['region' => 'Ленинградская область'],
        ]);

        self::assertStringContainsString('санкт-петербург', $searchText);
        self::assertStringContainsString('невский проспект', $searchText);
        self::assertStringContainsString('ленинградская область', $searchText);
        self::assertStringNotContainsString('123', $searchText);
    }
}
