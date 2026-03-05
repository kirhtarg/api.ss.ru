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
        Schema::create('user_bonuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->integer('points')->default(0); // Текущее количество баллов
            $table->integer('total_earned')->default(0); // Всего заработано за все время
            $table->integer('total_spent')->default(0); // Всего потрачено за все время
            $table->timestamps();

            // Индексы
            $table->index('user_id');
            $table->unique('user_id');

            // Внешние ключи
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_bonuses');
    }
};
