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
        Schema::create('shop_delivery_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique(); // created, transferred_to_courier, cancelled, in_transit, at_pickup_point, delivered
            $table->string('display_name', 100); // Создан, Передан в ТК, Отменен, В пути, В месте выдачи, Выдан
            $table->string('color', 7)->default('#6B7280'); // Цвет для отображения
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Сразу заполняем данными
        DB::table('shop_delivery_statuses')->insert([
            [
                'id' => 1,
                'name' => 'created',
                'display_name' => 'Создан',
                'color' => '#6B7280',
                'is_active' => true,
                'sort_order' => 10,
                'description' => 'Заказ создан',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'transferred_to_courier',
                'display_name' => 'Передан в ТК',
                'color' => '#3B82F6',
                'is_active' => true,
                'sort_order' => 20,
                'description' => 'Заказ передан в транспортную компанию',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'name' => 'cancelled',
                'display_name' => 'Отменен',
                'color' => '#EF4444',
                'is_active' => true,
                'sort_order' => 30,
                'description' => 'Доставка отменена',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'name' => 'in_transit',
                'display_name' => 'В пути',
                'color' => '#8B5CF6',
                'is_active' => true,
                'sort_order' => 40,
                'description' => 'Заказ в пути',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'name' => 'at_pickup_point',
                'display_name' => 'В месте выдачи',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 50,
                'description' => 'Заказ прибыл в место выдачи',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 6,
                'name' => 'delivered',
                'display_name' => 'Выдан',
                'color' => '#10B981',
                'is_active' => true,
                'sort_order' => 60,
                'description' => 'Заказ выдан получателю',
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
        Schema::dropIfExists('shop_delivery_statuses');
    }
};
