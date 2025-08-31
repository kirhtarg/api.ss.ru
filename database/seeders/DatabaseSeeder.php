<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Запускаем сидеры для административных таблиц
        $this->call([
            UserSeeder::class,
            AdminPageSeeder::class,
            AdminMenuItemSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
