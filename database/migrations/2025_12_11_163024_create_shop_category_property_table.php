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
        Schema::create('shop_category_property', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('property_id');
            $table->timestamps();

            // Индексы
            $table->index('category_id');
            $table->index('property_id');

            // Внешние ключи
            $table->foreign('category_id')->references('id')->on('shop_categories')->onDelete('cascade');
            $table->foreign('property_id')->references('id')->on('shop_properties')->onDelete('cascade');

            // Уникальный индекс для предотвращения дубликатов
            $table->unique(['category_id', 'property_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_category_property');
    }
};
