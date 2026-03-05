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
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique(); // ORD-20250120-001
            $table->unsignedBigInteger('user_id')->nullable(); // Может быть null для незарегистрированных пользователей
            $table->unsignedBigInteger('status_id')->default(1); // Ссылка на shop_order_statuses

            // Данные клиента (для незарегистрированных пользователей)
            $table->string('customer_name', 255)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_phone', 20)->nullable();

            // Данные заказа
            $table->json('items'); // Массив товаров с вариациями
            $table->decimal('subtotal', 10, 2)->default(0); // Сумма без скидок
            $table->decimal('discount_amount', 10, 2)->default(0); // Размер скидки
            $table->decimal('total_amount', 10, 2)->default(0); // Итоговая сумма
            $table->integer('total_quantity')->default(0); // Общее количество товаров

            // Способы оплаты и доставки
            $table->string('payment_method', 100)->nullable(); // card, cash, transfer
            $table->string('shipping_method', 100)->nullable(); // courier, pickup, post
            $table->text('shipping_address')->nullable(); // Адрес доставки
            $table->text('notes')->nullable(); // Комментарии к заказу

            // Метаданные
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable(); // Дополнительные данные

            $table->timestamps();

            // Индексы
            $table->index(['user_id', 'created_at']);
            $table->index(['status_id']);
            $table->index(['order_number']);
            $table->index(['created_at']);

            // Внешние ключи
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('status_id')->references('id')->on('shop_order_statuses')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_orders');
    }
};
