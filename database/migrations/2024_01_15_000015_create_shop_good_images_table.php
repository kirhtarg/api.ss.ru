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
        Schema::create('shop_good_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->constrained('shop_goods')->onDelete('cascade');
            $table->foreignId('variation_id')->nullable()->constrained('shop_good_variations')->onDelete('cascade');
            $table->string('image_path');
            $table->string('alt_text')->nullable();
            $table->boolean('is_main')->default(false); // Главное изображение
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Индексы для производительности
            $table->index(['good_id', 'sort_order']);
            $table->index(['variation_id', 'sort_order']);
            $table->index('is_main');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_good_images');
    }
};
