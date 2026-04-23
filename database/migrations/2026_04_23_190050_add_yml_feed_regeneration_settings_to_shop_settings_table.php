<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('settings')->insert([
            [
                'key' => 'yml_feed_regeneration_frequency',
                'name' => 'Периодичность генерации YML фида',
                'value' => 'daily',
                'default_value' => 'daily',
                'type' => 'select',
                'group' => 'shop',
                'description' => 'Как часто автоматически перегенерировать YML фид (daily, hourly, weekly)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'yml_feed_regeneration_time',
                'name' => 'Время генерации YML фида',
                'value' => '03:00',
                'default_value' => '03:00',
                'type' => 'text',
                'group' => 'shop',
                'description' => 'Время автоматической перегенерации в формате HH:mm',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', ['yml_feed_regeneration_frequency', 'yml_feed_regeneration_time'])
            ->where('group', 'shop')
            ->delete();
    }
};
