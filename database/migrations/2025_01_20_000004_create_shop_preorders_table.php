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
        Schema::create('shop_preorders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_id')->nullable(); // Для незарегистрированных пользователей
            $table->unsignedBigInteger('good_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
            $table->decimal('total', 10, 2);
            $table->string('good_name');
            $table->string('variation_name')->nullable();
            $table->string('good_sku');
            $table->string('good_image')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'fulfilled'])->default('pending');
            $table->text('notes')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->timestamps();

            // Индексы
            $table->index('user_id');
            $table->index('session_id');
            $table->index('good_id');
            $table->index('variation_id');
            $table->index('status');
            $table->index(['user_id', 'status']);
            $table->index(['session_id', 'status']);

            // Внешние ключи
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('good_id')->references('id')->on('shop_goods')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('shop_good_variations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_preorders');
    }
};
