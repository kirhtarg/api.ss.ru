<?php

namespace Tests\Unit\Partner;

use App\Http\Resources\Partner\V1\CategoryResource;
use App\Http\Resources\Partner\V1\ProductResource;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Services\OrderCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PartnerCatalogResourcesTest extends TestCase
{
    public function test_category_resource_has_stable_explicit_contract(): void
    {
        $category = new ShopCategory([
            'name' => 'Велосипеды', 'slug' => 'bikes', 'image' => '/images/bikes.jpg',
            'is_active' => true, 'sort_order' => 10, 'parent_id' => null,
        ]);
        $category->id = 7;
        $category->updated_at = now();

        $data = (new CategoryResource($category))->toArray(Request::create('/'));

        $this->assertSame(['id', 'parent_id', 'name', 'slug', 'image_url', 'is_active', 'sort_order', 'updated_at'], array_keys($data));
        $this->assertSame(7, $data['id']);
        $this->assertTrue($data['is_active']);
    }

    public function test_product_resource_excludes_internal_and_supplier_fields(): void
    {
        $this->mock(OrderCalculationService::class, function ($mock): void {
            $mock->shouldReceive('calculateFinalUnitPrice')->once()->andReturn([
                'base_price' => 120000, 'sale_price' => 110000, 'final_price' => 110000,
                'discounts' => ['sale' => ['amount' => 10000]],
            ]);
        });
        $good = new ShopGood([
            'name' => 'Trail Bike', 'slug' => 'trail-bike', 'sku' => 'BIKE-1',
            'short_description' => 'Trail', 'description' => 'Full description',
            'price' => 120000, 'sale_price' => 110000, 'stock_quantity' => 3,
            'is_active' => true, 'supplier' => 'internal-supplier',
        ]);
        $good->id = 11;
        $good->updated_at = now();
        foreach (['brands', 'categories', 'images', 'properties', 'variations', 'stock'] as $relation) {
            $good->setRelation($relation, new Collection());
        }

        $data = (new ProductResource($good))->toArray(Request::create('/'));

        $this->assertSame(110000.0, $data['final_price']);
        $this->assertSame(3, $data['available_quantity']);
        $this->assertArrayNotHasKey('supplier', $data);
        $this->assertArrayNotHasKey('demping_price', $data);
        $this->assertArrayNotHasKey('metadata', $data);
        $this->assertArrayNotHasKey('cost_price', $data);
    }
}
