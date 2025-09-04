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
        Schema::create('shop_variation_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('shop_variation_attributes')->onDelete('cascade');
            $table->string('value');
            $table->string('color', 7)->nullable(); // HEX цвет для цветовых атрибутов
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            // Индексы для производительности
            $table->index(['attribute_id', 'is_active', 'sort_order'], 'shop_var_attr_vals_attr_active_sort_idx');
            $table->index('value', 'shop_var_attr_vals_value_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_variation_attribute_values');
    }
};
