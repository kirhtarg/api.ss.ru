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
        Schema::create('shop_variation_attributes_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained('shop_good_variations')->onDelete('cascade');
            $table->foreignId('attribute_value_id')->constrained('shop_variation_attribute_values')->onDelete('cascade');
            $table->timestamps();
            
            // Уникальный индекс для предотвращения дублирования
            $table->unique(['variation_id', 'attribute_value_id'], 'shop_var_attr_vals_unique');
            
            // Индексы для производительности
            $table->index('variation_id');
            $table->index('attribute_value_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_variation_attributes_values');
    }
};
