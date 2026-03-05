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
        Schema::create('shop_cart_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable(); // Может быть null для незарегистрированных пользователей
            $table->string('session_id', 100)->nullable(); // Для незарегистрированных пользователей
            $table->unsignedBigInteger('good_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 10, 2); // Цена на момент добавления
            $table->decimal('total', 10, 2); // Общая сумма позиции
            $table->string('good_name', 255); // Название товара на момент добавления
            $table->string('variation_name', 255)->nullable(); // Название вариации
            $table->string('good_sku', 100)->nullable(); // SKU товара
            $table->string('good_image', 500)->nullable(); // URL изображения
            $table->timestamps();

            // Индексы
            $table->index(['user_id', 'created_at']);
            $table->index(['session_id']);
            $table->index(['good_id']);
            $table->index(['variation_id']);

            // Внешние ключи
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('good_id')->references('id')->on('shop_goods')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('shop_good_variations')->onDelete('cascade');

            // Уникальный индекс для предотвращения дублирования
            $table->unique(['user_id', 'good_id', 'variation_id'], 'unique_user_good_variation');
            $table->unique(['session_id', 'good_id', 'variation_id'], 'unique_session_good_variation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_cart_items');
    }
};
