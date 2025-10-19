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
            SettingSeederSafe::class, // Используем безопасный сидер
            UserRoleSeeder::class,
            
            // Админка
            AdminPageSeeder::class,
            AdminMenuItemSeeder::class,
            
            // Сайт
            SiteTemplateSeeder::class,
            SiteMenuSeeder::class,
            SiteMenuItemSeeder::class,
            
            // Магазин
            ShopTemplateSeeder::class,
            
            // Контакты
            SocialTypeSeeder::class,
            ContactSeeder::class,
            ContactPhoneSeeder::class,
            ContactAddressSeeder::class,
        ]);
    }
}
