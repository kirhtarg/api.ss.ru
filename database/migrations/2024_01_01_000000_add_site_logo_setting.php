<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Добавляем настройку site_logo
        DB::table('settings')->insert([
            'key' => 'site_logo',
            'name' => 'Логотип сайта',
            'value' => '',
            'type' => 'image',
            'group' => 'general',
            'description' => 'Логотип сайта для отображения в шапке',
            'image_width' => 200,
            'image_height' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Добавляем настройку main_site
        DB::table('settings')->insert([
            'key' => 'main_site',
            'name' => 'Основной сайт',
            'value' => 'https://skateandsnow.ru',
            'type' => 'string',
            'group' => 'general',
            'description' => 'URL основного сайта для ссылки в шапке',
            'image_width' => null,
            'image_height' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем добавленные настройки
        DB::table('settings')->where('key', 'site_logo')->delete();
        DB::table('settings')->where('key', 'main_site')->delete();
    }
};
