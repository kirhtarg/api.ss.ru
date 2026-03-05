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
        Schema::create('shop_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed', 'buy_x_get_y']); // Тип скидки
            $table->decimal('value', 8, 2); // Значение скидки
            $table->decimal('min_amount', 10, 2)->nullable(); // Минимальная сумма заказа
            $table->integer('min_quantity')->nullable(); // Минимальное количество товаров
            $table->datetime('starts_at');
            $table->datetime('ends_at');
            $table->integer('usage_limit')->nullable(); // Лимит использований
            $table->integer('used_count')->default(0); // Количество использований
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Индексы для производительности
            $table->index(['is_active', 'starts_at', 'ends_at'], 'shop_discounts_active_dates_idx');
            $table->index('type', 'shop_discounts_type_idx');
            $table->index('usage_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_discounts');
    }
};
