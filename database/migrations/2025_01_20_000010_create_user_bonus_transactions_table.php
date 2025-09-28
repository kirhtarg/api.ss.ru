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
        Schema::create('user_bonus_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 20); // earn, spend, expire, refund
            $table->integer('points'); // Количество баллов (положительное для earn, отрицательное для spend)
            $table->string('description'); // Описание операции
            $table->unsignedBigInteger('order_id')->nullable(); // Связанный заказ
            $table->date('expires_at')->nullable(); // Дата истечения баллов
            $table->json('metadata')->nullable(); // Дополнительные данные
            $table->timestamps();
            
            // Индексы
            $table->index(['user_id', 'created_at']);
            $table->index(['type']);
            $table->index(['order_id']);
            $table->index(['expires_at']);
            
            // Внешние ключи
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('shop_orders')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_bonus_transactions');
    }
};
