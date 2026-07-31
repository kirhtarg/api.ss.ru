<?php

namespace Tests\Unit\Partner;

use App\Services\Partner\PartnerWebhookUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PartnerWebhookUrlGuardTest extends TestCase
{
    public function test_public_https_address_is_allowed(): void
    {
        $this->assertSame(['8.8.8.8'], app(PartnerWebhookUrlGuard::class)->assertAllowed('https://8.8.8.8/webhook'));
    }

    #[DataProvider('unsafeUrls')]
    public function test_private_reserved_and_insecure_urls_are_rejected(string $url): void
    {
        $this->expectException(RuntimeException::class);

        app(PartnerWebhookUrlGuard::class)->assertAllowed($url);
    }

    public static function unsafeUrls(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/webhook'],
            'ipv6 loopback' => ['https://[::1]/webhook'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data'],
            'ipv6 link local' => ['https://[fe80::1]/webhook'],
            'private network' => ['https://10.0.0.1/webhook'],
            'carrier grade nat' => ['https://100.64.0.1/webhook'],
            'documentation range' => ['https://192.0.2.1/webhook'],
            'multicast' => ['https://224.0.0.1/webhook'],
            'plain HTTP' => ['http://8.8.8.8/webhook'],
            'credentials' => ['https://user:password@8.8.8.8/webhook'],
            'unapproved port' => ['https://8.8.8.8:8443/webhook'],
        ];
    }
}
