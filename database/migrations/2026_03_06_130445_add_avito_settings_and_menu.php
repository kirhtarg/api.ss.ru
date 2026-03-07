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
        // Добавляем настройки для Авито
        $settings = [
            [
                'key' => 'avito_feed_enabled',
                'value' => '0',
                'group' => 'avito',
                'type' => 'boolean',
                'name' => 'Включить фид Авито',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'avito_category_mapping',
                'value' => '{}',
                'group' => 'avito',
                'type' => 'json',
                'name' => 'Сопоставление категорий Авито',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'avito_feed_last_updated',
                'value' => '',
                'group' => 'avito',
                'type' => 'string',
                'name' => 'Последнее обновление фида Авито',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(['key' => $setting['key']], $setting);
        }

        // Добавляем пункт меню "Avito" в раздел Магазин (page_id = 2)
        DB::table('admin_menu_items')->updateOrInsert(
        ['href' => 'avito', 'page_id' => 2],
        [
            'label' => 'Avito',
            'icon' => 'mdi:store-search',
            'description' => 'Управление интеграцией с Авито (фид, маппинг категорий)',
            'order' => 100,
            'is_active' => 1,
            'in_menu' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'avito_feed_enabled',
            'avito_category_mapping',
            'avito_feed_last_updated'
        ])->delete();

        DB::table('admin_menu_items')->where('href', 'avito')->where('page_id', 2)->delete();
    }
};
