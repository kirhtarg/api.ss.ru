<?php

namespace Tests\Unit;

use App\Services\DeliveryPackageService;
use Tests\TestCase;

class DeliveryPackageServiceTest extends TestCase
{
    public function test_it_combines_regular_units_by_weight_and_volume(): void
    {
        $service = app(DeliveryPackageService::class);
        $packages = $service->fromCartItems([
            ['quantity' => 2, 'weight' => 3, 'length' => 40, 'width' => 20, 'height' => 10],
            ['quantity' => 1, 'weight' => 1, 'length' => 15, 'width' => 10, 'height' => 5],
        ]);

        $this->assertCount(1, $packages);
        $this->assertSame(7.0, $service->summary($packages)['total_weight']);
        $this->assertSame(40.0, $service->summary($packages)['max_length']);
        $this->assertSame(1, $packages[0]['items'][0]['quantity']);
    }

    public function test_dellin_uses_heaviest_place_and_total_weight_separately(): void
    {
        $service = app(DeliveryPackageService::class);
        $packages = $service->fromCartItems([
            ['quantity' => 2, 'weight' => 3, 'length' => 40, 'width' => 20, 'height' => 10, 'ships_separately' => true],
            ['quantity' => 1, 'weight' => 1, 'length' => 15, 'width' => 10, 'height' => 5],
        ]);
        $cargo = $service->dellinCargo($packages);

        $this->assertSame(3, $cargo['quantity']);
        $this->assertSame(3.0, $cargo['weight']);
        $this->assertSame(7.0, $cargo['totalWeight']);
        $this->assertSame(0.4, $cargo['length']);
    }

    public function test_volumetric_weight_is_kept_separate_from_physical_weight(): void
    {
        $service = app(DeliveryPackageService::class);
        $packages = $service->fromCartItems([
            ['quantity' => 1, 'weight' => 15, 'length' => 100, 'width' => 50, 'height' => 40],
        ]);

        $this->assertSame(40.0, $packages[0]['volumetric_weight']);
        $this->assertSame(40.0, $packages[0]['billable_weight']);
        $this->assertSame(15.0, $packages[0]['weight']);
    }
}
