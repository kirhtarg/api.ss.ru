<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('good_id');
            $table->timestamps();
            
            // Индексы и внешние ключи
            $table->unique(['user_id', 'good_id'], 'shop_favorites_user_id_good_id_unique');
            $table->index('user_id', 'shop_favorites_user_id_foreign');
            $table->index('good_id', 'shop_favorites_good_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_favorites');
    }
};