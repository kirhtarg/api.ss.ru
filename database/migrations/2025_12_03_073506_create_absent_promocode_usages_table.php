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
        Schema::create('absent_promocode_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('good_id')->constrained('shop_goods')->onDelete('cascade');
            $table->foreignId('promocode_id')->constrained('promocodes')->onDelete('cascade');
            $table->timestamps();

            // Уникальный индекс для предотвращения повторных промокодов для одного товара и пользователя
            $table->unique(['user_id', 'good_id']);
            $table->index('user_id');
            $table->index('good_id');
            $table->index('promocode_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absent_promocode_usages');
    }
};
