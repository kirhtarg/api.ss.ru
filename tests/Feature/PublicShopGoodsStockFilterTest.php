<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Public\ShopGoodsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use ReflectionMethod;
use Tests\TestCase;

class PublicShopGoodsStockFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('shop_good_variations', function (Blueprint $table): void {
            $table->id();
            $table->integer('stock_quantity')->default(0);
            $table->string('remote_stock_quantity')->nullable();
            $table->string('fast_remote_stock_quantity')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shop_good_variations');
        parent::tearDown();
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function test_remote_variation_stock_counts_when_store_policy_includes_remote_warehouses(): void
    {
        DB::table('shop_good_variations')->insert([
            ['id' => 1, 'stock_quantity' => 0, 'remote_stock_quantity' => null, 'fast_remote_stock_quantity' => '3'],
            ['id' => 2, 'stock_quantity' => 0, 'remote_stock_quantity' => '5', 'fast_remote_stock_quantity' => null],
            ['id' => 3, 'stock_quantity' => 0, 'remote_stock_quantity' => '0', 'fast_remote_stock_quantity' => ''],
            ['id' => 4, 'stock_quantity' => 2, 'remote_stock_quantity' => null, 'fast_remote_stock_quantity' => null],
        ]);

        $controller = new ShopGoodsController;
        $method = new ReflectionMethod(ShopGoodsController::class, 'applyVariationInStockCondition');
        $method->setAccessible(true);
        $query = DB::table('shop_good_variations')->select('id');
        $method->invoke($controller, $query, 2);

        self::assertEqualsCanonicalizing([1, 2, 4], $query->pluck('id')->all());
    }
}
