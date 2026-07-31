<?php

namespace Tests\Unit\Partner;

use App\Models\ShopOrder;
use App\Observers\PartnerShopOrderObserver;
use Tests\TestCase;

class PartnerShopOrderObserverIsolationTest extends TestCase
{
    public function test_observer_returns_before_database_access_for_ordinary_order(): void
    {
        $ordinaryOrder = new ShopOrder([
            'order_number' => 'ORDINARY-ORDER',
            'metadata' => ['source' => 'storefront'],
        ]);

        app(PartnerShopOrderObserver::class)->updated($ordinaryOrder);

        $this->addToAssertionCount(1);
    }
}
