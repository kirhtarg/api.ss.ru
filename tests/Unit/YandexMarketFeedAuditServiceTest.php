<?php

namespace Tests\Unit;

use App\Services\YandexMarketFeedAuditService;
use PHPUnit\Framework\TestCase;

class YandexMarketFeedAuditServiceTest extends TestCase
{
    public function test_it_detects_critical_and_recommended_feed_issues(): void
    {
        $path = $this->feed(<<<'XML'
<offer id="10" available="true">
  <url>https://example.test/product</url><count>3</count><price>100</price>
  <currencyId>RUR</currencyId><categoryId>1</categoryId><name>Товар</name>
</offer>
<offer id="11" available="false"><count>0</count><price>0</price></offer>
XML);

        try {
            $result = (new YandexMarketFeedAuditService())->audit($path, ['scope' => 'all']);
            $this->assertSame(2, $result['summary']['total']);
            $this->assertSame(1, $result['summary']['critical']);
            $this->assertSame(2, $result['summary']['warnings']);
            $this->assertContains('price', array_column($result['items'][1]['issues'], 'code'));
            $this->assertContains('dimensions', array_column($result['items'][0]['issues'], 'code'));
        } finally {
            @unlink($path);
        }
    }

    public function test_it_filters_clean_offers(): void
    {
        $path = $this->feed(<<<'XML'
<offer id="20" available="true">
  <url>https://example.test/product</url><count>2</count><price>100</price><currencyId>RUR</currencyId>
  <categoryId>1</categoryId><picture>https://example.test/image.jpg</picture><name>Чистый товар</name>
  <vendor>Brand</vendor><description>Описание</description><weight>1.2</weight><dimensions>10/20/30</dimensions>
</offer>
XML);

        try {
            $result = (new YandexMarketFeedAuditService())->audit($path, ['scope' => 'clean']);
            $this->assertSame(1, $result['matched']);
            $this->assertSame([], $result['items'][0]['issues']);
        } finally {
            @unlink($path);
        }
    }

    private function feed(string $offers): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yandex-feed-');
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><yml_catalog><shop><offers>'.$offers.'</offers></shop></yml_catalog>');

        return $path;
    }
}
