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
        Schema::create('shop_order_log_icons', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название иконки (Внимание, Положительное, Отрицательное)
            $table->string('icon'); // Иконка Iconify (mdi:alert, mdi:check-circle и т.д.)
            $table->string('color', 7)->default('#6B7280'); // Цвет иконки HEX
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Добавляем базовые иконки действий
        DB::table('shop_order_log_icons')->insert([
            [
                'name' => 'Внимание',
                'icon' => 'mdi:alert-circle',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Положительное действие',
                'icon' => 'mdi:check-circle',
                'color' => '#10B981',
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Отрицательное действие',
                'icon' => 'mdi:close-circle',
                'color' => '#EF4444',
                'is_active' => true,
                'sort_order' => 3,
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
        Schema::dropIfExists('shop_order_log_icons');
    }
};
