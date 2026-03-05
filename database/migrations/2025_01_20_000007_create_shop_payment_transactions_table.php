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
        Schema::create('shop_payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_method_id');
            $table->string('status', 50)->default('pending'); // pending, success, failed, cancelled
            $table->decimal('amount', 10, 2);
            $table->string('transaction_id')->nullable(); // ID транзакции от платежной системы
            $table->json('request_data')->nullable(); // Данные запроса
            $table->json('response_data')->nullable(); // Ответ от платежной системы
            $table->text('error_message')->nullable(); // Сообщение об ошибке
            $table->timestamp('processed_at')->nullable(); // Время обработки
            $table->timestamps();

            // Индексы
            $table->index(['order_id']);
            $table->index(['payment_method_id']);
            $table->index(['status']);
            $table->index(['transaction_id']);

            // Внешние ключи
            $table->foreign('order_id')->references('id')->on('shop_orders')->onDelete('cascade');
            $table->foreign('payment_method_id')->references('id')->on('shop_payment_methods')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_payment_transactions');
    }
};
