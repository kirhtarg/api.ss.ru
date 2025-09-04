<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopGoodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $goods = [
            [
                'id' => 1,
                'name' => 'товар1',
                'slug' => 'tovar1',
                'description' => '22333',
                'short_description' => null,
                'sku' => '3453-009',
                'price' => 234.00,
                'sale_price' => 876.00,
                'weight' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'depth' => null,
                'stock_quantity' => 45,
                'is_active' => 0,
                'is_featured' => 0,
                'is_new' => 0,
                'is_sale' => 0,
                'sort_order' => 0,
                'rating' => 0.00,
                'reviews_count' => 0,
                'meta_title' => null,
                'meta_description' => null,
                'created_at' => '2025-09-02 09:02:12',
                'updated_at' => '2025-09-02 09:02:19',
                'deleted_at' => null,
            ],
        ];

        foreach ($goods as $good) {
            DB::table('shop_goods')->updateOrInsert(
                ['id' => $good['id']],
                $good
            );
        }

        // Создаем связи товара с категориями
        $goodCategories = [
            ['good_id' => 1, 'category_id' => 1],
            ['good_id' => 1, 'category_id' => 2],
        ];

        foreach ($goodCategories as $goodCategory) {
            DB::table('shop_good_category')->updateOrInsert(
                ['good_id' => $goodCategory['good_id'], 'category_id' => $goodCategory['category_id']],
                $goodCategory
            );
        }

        // Создаем записи аудита для товара
        $auditRecords = [
            [
                'id' => 1,
                'good_id' => 1,
                'user_id' => 1,
                'action' => 'created',
                'old_values' => null,
                'new_values' => '{"id": 1, "sku": "3453-009", "name": "товар1", "slug": "tovar1", "price": "234.00", "rating": "0.00", "is_active": true, "created_at": "2025-09-02T12:02:12.000000Z", "sale_price": "876.00", "updated_at": "2025-09-02T12:02:12.000000Z", "description": "22333", "is_featured": false, "reviews_count": 0, "stock_quantity": 45}',
                'created_at' => '2025-09-02 09:02:12',
                'updated_at' => '2025-09-02 09:02:12',
            ],
            [
                'id' => 2,
                'good_id' => 1,
                'user_id' => 1,
                'action' => 'updated',
                'old_values' => '{"id": 1, "sku": "3453-009", "name": "товар1", "slug": "tovar1", "price": "234.00", "width": null, "height": null, "length": null, "rating": "0.00", "weight": null, "is_active": true, "created_at": "2025-09-02T12:02:12.000000Z", "deleted_at": null, "meta_title": null, "sale_price": "876.00", "updated_at": "2025-09-02T12:02:12.000000Z", "description": "22333", "is_featured": false, "reviews_count": 0, "stock_quantity": 45, "meta_description": null, "short_description": null}',
                'new_values' => '{"id": 1, "sku": "3453-009", "name": "товар1", "slug": "tovar1", "price": "234.00", "width": null, "height": null, "length": null, "rating": "0.00", "weight": null, "is_active": false, "created_at": "2025-09-02T12:02:12.000000Z", "deleted_at": null, "meta_title": null, "sale_price": "876.00", "updated_at": "2025-09-02T12:02:19.000000Z", "description": "22333", "is_featured": false, "reviews_count": 0, "stock_quantity": 45, "meta_description": null, "short_description": null}',
                'created_at' => '2025-09-02 09:02:19',
                'updated_at' => '2025-09-02 09:02:19',
            ],
        ];

        foreach ($auditRecords as $audit) {
            DB::table('shop_good_audit')->updateOrInsert(
                ['id' => $audit['id']],
                $audit
            );
        }
    }
}
