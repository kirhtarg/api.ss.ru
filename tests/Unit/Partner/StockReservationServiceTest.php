<?php

namespace Tests\Unit\Partner;

use App\Models\ShopGood;
use App\Models\ShopOrder;
use App\Models\ShopStock;
use App\Models\ShopStockReservation;
use App\Services\StockReservationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class StockReservationServiceTest extends TestCase
{
    private ShopGood $good;
    private ShopStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('shop_goods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('sku');
            $table->integer('stock_quantity');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('shop_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('shop_stocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('good_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->integer('quantity');
            $table->integer('reserved_quantity')->default(0);
            $table->integer('min_quantity')->default(0);
            $table->timestamps();
        });
        Schema::create('shop_stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('good_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->integer('quantity');
            $table->timestamp('reserved_until');
            $table->string('reservation_type');
            $table->string('reference_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $goodId = DB::table('shop_goods')->insertGetId([
            'name' => 'Last snowboard', 'sku' => 'LAST-1', 'stock_quantity' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->good = ShopGood::findOrFail($goodId);
        $this->stock = ShopStock::create([
            'good_id' => $this->good->id, 'variation_id' => null, 'warehouse_id' => 1,
            'quantity' => 1, 'reserved_quantity' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['shop_stock_reservations', 'shop_stocks', 'shop_orders', 'shop_goods'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_second_sequential_reservation_cannot_sell_the_last_unit_twice(): void
    {
        $service = app(StockReservationService::class);
        $service->reserveForOrder($this->order('ORDER-1'), $this->items());

        try {
            $service->reserveForOrder($this->order('ORDER-2'), $this->items());
            $this->fail('The same final stock unit must not be reserved twice.');
        } catch (UnprocessableEntityHttpException) {
            $this->assertSame(1, $this->stock->fresh()->reserved_quantity);
            $this->assertDatabaseCount('shop_stock_reservations', 1);
        }
    }

    public function test_expired_reservation_is_released(): void
    {
        $service = app(StockReservationService::class);
        $service->reserveForOrder($this->order('ORDER-EXPIRED'), $this->items());
        ShopStockReservation::query()->update(['reserved_until' => now()->subMinute()]);

        $this->assertSame(1, $service->releaseExpired());
        $this->assertSame(0, $this->stock->fresh()->reserved_quantity);
        $this->assertDatabaseCount('shop_stock_reservations', 0);
    }

    public function test_expired_non_partner_reservation_is_not_touched(): void
    {
        $this->stock->update(['reserved_quantity' => 1]);
        ShopStockReservation::create([
            'good_id' => $this->good->id,
            'variation_id' => null,
            'warehouse_id' => 1,
            'quantity' => 1,
            'reserved_until' => now()->subMinute(),
            'reservation_type' => 'order',
            'reference_id' => 'ordinary-order-1',
            'notes' => 'Ordinary storefront order',
        ]);

        $this->assertSame(0, app(StockReservationService::class)->releaseExpired());
        $this->assertSame(1, $this->stock->fresh()->reserved_quantity);
        $this->assertDatabaseCount('shop_stock_reservations', 1);
    }

    public function test_reservation_is_committed_after_order_completion(): void
    {
        $service = app(StockReservationService::class);
        $order = $this->order('ORDER-COMPLETED');
        $service->reserveForOrder($order, $this->items());

        $this->assertSame(1, $service->commitForOrder($order));
        $this->assertSame(0, $this->stock->fresh()->quantity);
        $this->assertSame(0, $this->stock->fresh()->reserved_quantity);
        $this->assertSame(0, $this->good->fresh()->stock_quantity);
        $this->assertDatabaseCount('shop_stock_reservations', 0);
    }

    public function test_reservation_is_released_after_order_cancellation(): void
    {
        $service = app(StockReservationService::class);
        $order = $this->order('ORDER-CANCELLED');
        $service->reserveForOrder($order, $this->items());

        $this->assertSame(1, $service->releaseForOrder($order));
        $this->assertSame(1, $this->stock->fresh()->quantity);
        $this->assertSame(0, $this->stock->fresh()->reserved_quantity);
        $this->assertSame(1, $this->good->fresh()->stock_quantity);
        $this->assertDatabaseCount('shop_stock_reservations', 0);
    }

    private function order(string $number): ShopOrder
    {
        return ShopOrder::withoutEvents(fn () => ShopOrder::create(['order_number' => $number]));
    }

    private function items(): array
    {
        return [[
            'good_id' => $this->good->id,
            'variation_id' => null,
            'quantity' => 1,
        ]];
    }
}
