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
        Schema::create('shop_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->constrained('shop_goods')->onDelete('cascade');
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('shop_warehouses')->onDelete('cascade');
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0); // Зарезервированное количество
            $table->integer('min_quantity')->default(0); // Минимальный остаток для уведомления
            $table->timestamps();

            // Уникальный индекс для предотвращения дублирования
            $table->unique(['good_id', 'variation_id', 'warehouse_id'], 'shop_stock_unique');

            // Индексы для производительности
            $table->index(['good_id', 'warehouse_id']);
            $table->index(['variation_id', 'warehouse_id']);
            $table->index('quantity');
            $table->index('reserved_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_stock');
    }
};
