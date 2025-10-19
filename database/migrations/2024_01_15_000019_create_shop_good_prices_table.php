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
        Schema::create('shop_good_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->constrained('shop_goods')->onDelete('cascade');
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->onDelete('cascade');
            $table->foreignId('price_type_id')->constrained('shop_price_types')->onDelete('cascade');
            $table->decimal('price', 10, 2);
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->datetime('valid_from')->nullable();
            $table->datetime('valid_until')->nullable();
            $table->timestamps();
            
            // Уникальный индекс для предотвращения дублирования
            $table->unique(['good_id', 'variation_id', 'price_type_id'], 'shop_good_prices_unique');
            
            // Индексы для производительности
            $table->index(['good_id', 'price_type_id']);
            $table->index(['variation_id', 'price_type_id']);
            $table->index('price');
            $table->index(['valid_from', 'valid_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_good_prices');
    }
};
