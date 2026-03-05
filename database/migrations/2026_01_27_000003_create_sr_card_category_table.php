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
        Schema::create('sr_card_category', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sr_card_id');
            $table->unsignedBigInteger('sr_category_id');
            $table->timestamps();

            // Внешние ключи
            $table->foreign('sr_card_id')->references('id')->on('sr_cards')->onDelete('cascade');
            $table->foreign('sr_category_id')->references('id')->on('sr_categories')->onDelete('cascade');

            // Уникальная комбинация
            $table->unique(['sr_card_id', 'sr_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sr_card_category');
    }
};
