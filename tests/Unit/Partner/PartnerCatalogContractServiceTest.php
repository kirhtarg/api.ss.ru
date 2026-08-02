<?php

namespace Tests\Unit\Partner;

use App\Services\Partner\PartnerCatalogContractService;
use Tests\TestCase;

class PartnerCatalogContractServiceTest extends TestCase
{
    public function test_source_stock_preserves_markers_and_normalizes_numbers(): void
    {
        $service = new PartnerCatalogContractService();

        $this->assertSame(0, $service->sourceStock(-5, true));
        $this->assertSame(10, $service->sourceStock('10'));
        $this->assertSame('>10', $service->sourceStock('>10'));
        $this->assertNull($service->sourceStock(null));
    }

    public function test_purchase_states_do_not_treat_preorder_as_regular_availability(): void
    {
        $service = new PartnerCatalogContractService();

        $this->assertSame(['is_preorder' => true, 'purchase_mode' => 'regular', 'can_order' => true, 'can_preorder' => false], $service->purchaseState(2, true));
        $this->assertSame(['is_preorder' => true, 'purchase_mode' => 'preorder', 'can_order' => false, 'can_preorder' => true], $service->purchaseState(0, true));
        $this->assertSame(['is_preorder' => false, 'purchase_mode' => 'unavailable', 'can_order' => false, 'can_preorder' => false], $service->purchaseState(0, false));
        $this->assertSame(['is_preorder' => true, 'purchase_mode' => 'unavailable', 'can_order' => false, 'can_preorder' => false], $service->purchaseState(5, true, false));
    }
}
