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
        Schema::create('shop_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255); // Название способа оплаты
            $table->string('type', 50); // cash, card, transfer, test_bank
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable(); // Описание
            $table->json('settings')->nullable(); // Настройки (API ключи, настройки)
            $table->integer('sort_order')->default(0); // Порядок сортировки
            $table->boolean('is_default')->default(false); // Способ по умолчанию
            $table->boolean('can_disable_default')->default(true); // Можно ли отключить при наличии других
            $table->timestamps();
            
            // Индексы
            $table->index(['is_active', 'sort_order']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_payment_methods');
    }
};
