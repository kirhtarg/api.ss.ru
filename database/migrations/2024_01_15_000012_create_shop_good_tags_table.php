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
        Schema::create('shop_good_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('good_id')->constrained('shop_goods')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained('shop_tags')->onDelete('cascade');
            $table->timestamps();

            // Уникальный индекс для предотвращения дублирования
            $table->unique(['good_id', 'tag_id'], 'shop_good_tags_unique');

            // Индексы для производительности
            $table->index('good_id');
            $table->index('tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_good_tags');
    }
};
