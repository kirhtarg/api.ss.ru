<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Основные данные системы
            RoleSeeder::class,
            UserSeeder::class,
            SettingSeeder::class,
            
            // Админка
            AdminPageSeeder::class,
            AdminMenuItemSeeder::class,
            
            // Магазин
            ShopCategorySeeder::class,
            ShopBrandSeeder::class,
            ShopPropertySeeder::class,
            ShopTagSeeder::class,
            ShopVariationAttributeSeeder::class,
            ShopPriceTypeSeeder::class,
            ShopWarehouseSeeder::class,
            ShopGoodSeeder::class,
            
            // Сайт
            SiteMenuSeeder::class,
            SiteTemplateSeeder::class,
        ]);
    }
}
