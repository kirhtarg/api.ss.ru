<?php

namespace Tests\Unit\Partner;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopStock;
use App\Services\Partner\PartnerStockAvailabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartnerStockAvailabilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('shop_goods', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug')->nullable(); $table->integer('stock_quantity'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('shop_good_variations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('good_id'); $table->integer('stock_quantity'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('shop_stocks', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable();
            $table->integer('quantity'); $table->integer('reserved_quantity'); $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shop_stocks');
        Schema::dropIfExists('shop_good_variations');
        Schema::dropIfExists('shop_goods');
        parent::tearDown();
    }

    public function test_quantity_uses_stock_after_reservations(): void
    {
        $good = ShopGood::withoutEvents(fn () => ShopGood::create(['name' => 'Bike', 'stock_quantity' => 10, 'is_active' => true]));
        ShopStock::create(['good_id' => $good->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        $service = app(PartnerStockAvailabilityService::class);
        $this->assertSame(10, $service->quantity($good));

        ShopStock::query()->update(['reserved_quantity' => 10]);
        $this->assertSame(0, $service->quantity($good->fresh()));

        ShopStock::query()->update(['reserved_quantity' => 4]);
        $this->assertSame(6, $service->quantity($good->fresh()));
    }

    public function test_activity_and_multiple_variations_control_product_availability(): void
    {
        $good = ShopGood::withoutEvents(fn () => ShopGood::create(['name' => 'Bike', 'stock_quantity' => 0, 'is_active' => true]));
        $empty = ShopGoodVariation::create(['good_id' => $good->id, 'stock_quantity' => 5, 'is_active' => false]);
        $available = ShopGoodVariation::create(['good_id' => $good->id, 'stock_quantity' => 2, 'is_active' => true]);
        ShopStock::create(['good_id' => $good->id, 'variation_id' => $empty->id, 'quantity' => 5, 'reserved_quantity' => 0]);
        ShopStock::create(['good_id' => $good->id, 'variation_id' => $available->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $service = app(PartnerStockAvailabilityService::class);

        $this->assertSame(0, $service->quantity($good, $empty));
        $this->assertSame(1, $service->quantity($good, $available));
        $state = $service->productState($good->load('variations.stock'));
        $this->assertSame(['available_quantity' => 1, 'is_available' => true], $state);
        $this->assertTrue($service->productIsAvailable($good));
        $good->update(['is_active' => false]);
        $this->assertFalse($service->productIsAvailable($good->fresh()->load('variations.stock')));
    }

    public function test_product_quantity_sums_only_active_variations(): void
    {
        $good = ShopGood::withoutEvents(fn () => ShopGood::create(['name' => 'Bike', 'stock_quantity' => 99, 'is_active' => true]));
        ShopGoodVariation::create(['good_id' => $good->id, 'stock_quantity' => 2, 'is_active' => true]);
        ShopGoodVariation::create(['good_id' => $good->id, 'stock_quantity' => 3, 'is_active' => true]);
        ShopGoodVariation::create(['good_id' => $good->id, 'stock_quantity' => 100, 'is_active' => false]);

        $this->assertSame(
            ['available_quantity' => 5, 'is_available' => true],
            app(PartnerStockAvailabilityService::class)->productState($good->load('variations.stock')),
        );
    }
}
