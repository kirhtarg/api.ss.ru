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
        Schema::create('telegram_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50); // order_created, order_updated, order_cancelled, payment_success, payment_failed
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('chat_id', 50); // ID чата для отправки
            $table->text('message'); // Текст сообщения
            $table->json('data')->nullable(); // Дополнительные данные
            $table->string('status', 20)->default('pending'); // pending, sent, failed
            $table->text('error_message')->nullable(); // Сообщение об ошибке
            $table->integer('attempts')->default(0); // Количество попыток отправки
            $table->timestamp('sent_at')->nullable(); // Время отправки
            $table->timestamps();
            
            // Индексы
            $table->index(['type', 'status']);
            $table->index(['order_id']);
            $table->index(['chat_id']);
            $table->index(['status', 'created_at']);
            
            // Внешние ключи
            $table->foreign('order_id')->references('id')->on('shop_orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_notifications');
    }
};
