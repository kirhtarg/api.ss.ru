<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $settingsPageId = DB::table('admin_pages')->where('slug', 'settings')->value('id');

        if (! $settingsPageId) {
            Log::warning('Partner API admin menu item was not created: settings page is missing');

            return;
        }

        $itemExists = DB::table('admin_menu_items')
            ->where('href', 'partner-api')
            ->where('page_id', $settingsPageId)
            ->exists();
        if ($itemExists) {
            Log::info('Partner API admin menu item already exists', [
                'page_id' => $settingsPageId,
                'href' => 'partner-api',
            ]);

            return;
        }

        $order = (int) DB::table('admin_menu_items')
            ->where('page_id', $settingsPageId)
            ->max('order') + 1;

        DB::table('admin_menu_items')->insert([
            [
                'href' => 'partner-api',
                'page_id' => $settingsPageId,
                'label' => 'Partner API',
                'icon' => 'mdi:api',
                'description' => 'Партнёры, API-ключи, заказы, комиссии, выплаты и журналы интеграции',
                'order' => $order,
                'is_active' => true,
                'in_menu' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Log::info('Partner API admin menu item registered', [
            'page_id' => $settingsPageId,
            'href' => 'partner-api',
        ]);
    }

    public function down(): void
    {
        $settingsPageId = DB::table('admin_pages')->where('slug', 'settings')->value('id');

        if (! $settingsPageId) {
            Log::warning('Partner API admin menu item was not removed: settings page is missing');

            return;
        }

        DB::table('admin_menu_items')
            ->where('href', 'partner-api')
            ->where('page_id', $settingsPageId)
            ->where('label', 'Partner API')
            ->where('icon', 'mdi:api')
            ->where('description', 'Партнёры, API-ключи, заказы, комиссии, выплаты и журналы интеграции')
            ->delete();

        Log::info('Partner API admin menu item removed', [
            'page_id' => $settingsPageId,
            'href' => 'partner-api',
        ]);
    }
};
