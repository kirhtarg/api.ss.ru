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
        Schema::create('shop_good_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->constrained('shop_goods')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('shop_categories')->onDelete('cascade');
            $table->timestamps();
            
            // Уникальный индекс для предотвращения дублирования
            $table->unique(['good_id', 'category_id']);
            
            // Индексы для производительности
            $table->index('good_id');
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_good_categories');
    }
};
