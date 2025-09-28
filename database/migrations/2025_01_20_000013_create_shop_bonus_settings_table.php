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
        Schema::create('shop_bonus_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Название бонусной системы
            $table->decimal('regular_price_percentage', 5, 2)->default(5.00); // Процент бонусов за обычную цену
            $table->decimal('sale_price_percentage', 5, 2)->default(2.50); // Процент бонусов за акционную цену
            $table->decimal('max_usage_percentage', 5, 2)->default(50.00); // Максимальный процент списания бонусами
            $table->boolean('is_active')->default(true); // Активна ли система
            $table->integer('min_order_amount')->default(0); // Минимальная сумма заказа для начисления
            $table->integer('min_bonus_amount')->default(1); // Минимальная сумма бонусов для списания
            $table->integer('max_bonus_amount')->nullable(); // Максимальная сумма бонусов для списания
            $table->integer('bonus_expiry_days')->default(365); // Срок действия бонусов в днях
            $table->json('metadata')->nullable(); // Дополнительные настройки
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_bonus_settings');
    }
};
