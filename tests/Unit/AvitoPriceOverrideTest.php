<?php

namespace Tests\Unit;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Services\AvitoFeedService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class AvitoPriceOverrideTest extends TestCase
{
    public function test_variation_avito_price_has_priority_over_its_regular_price(): void
    {
        $good = new ShopGood(['price' => 3000, 'stock_quantity' => 1]);
        $good->setRelation('variations', new Collection([
            new ShopGoodVariation(['price' => 2200, 'sale_price' => 2000, 'stock_quantity' => 3, 'is_active' => true]),
            new ShopGoodVariation(['price' => 2500, 'avito_price' => 1700, 'stock_quantity' => 2, 'is_active' => true]),
        ]));

        self::assertSame(1700.0, $this->getMinPrice($good));
    }

    public function test_empty_avito_price_keeps_the_existing_price_algorithm(): void
    {
        $good = new ShopGood(['price' => 3000, 'stock_quantity' => 1]);
        // The existing Avito algorithm accepts any positive sale price, even if
        // it is higher than base. The override must not silently change that.
        $good->setRelation('variations', new Collection([
            new ShopGoodVariation(['price' => 1000, 'sale_price' => 1300, 'stock_quantity' => 1, 'is_active' => true]),
        ]));

        self::assertSame(1300.0, $this->getMinPrice($good));
    }

    public function test_main_good_avito_price_is_used_without_variation_prices(): void
    {
        $good = new ShopGood(['price' => 3000, 'sale_price' => 2500, 'avito_price' => 2100, 'stock_quantity' => 1]);
        $good->setRelation('variations', new Collection());

        self::assertSame(2100.0, $this->getMinPrice($good));
    }

    private function getMinPrice(ShopGood $good): float
    {
        $service = (new ReflectionClass(AvitoFeedService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AvitoFeedService::class, 'getMinPrice');
        $method->setAccessible(true);

        return (float) $method->invoke($service, $good);
    }
}
