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
        Schema::create('shop_payment_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique(); // pending, paid
            $table->string('display_name', 100); // Ожидает оплаты, Оплачен
            $table->string('color', 7)->default('#6B7280'); // Цвет для отображения
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Сразу заполняем данными
        DB::table('shop_payment_statuses')->insert([
            [
                'id' => 1,
                'name' => 'pending',
                'display_name' => 'Ожидает оплаты',
                'color' => '#F59E0B',
                'is_active' => true,
                'sort_order' => 10,
                'description' => 'Заказ ожидает оплаты',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'paid',
                'display_name' => 'Оплачен',
                'color' => '#10B981',
                'is_active' => true,
                'sort_order' => 20,
                'description' => 'Заказ оплачен',
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
        Schema::dropIfExists('shop_payment_statuses');
    }
};
