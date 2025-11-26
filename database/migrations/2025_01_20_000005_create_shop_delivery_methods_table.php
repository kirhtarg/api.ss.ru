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
        Schema::create('shop_delivery_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255); // Название способа доставки
            $table->string('type', 50); // pickup, courier, post, cdek
            $table->boolean('is_active')->default(true);
            $table->decimal('cost', 10, 2)->default(0); // Стоимость доставки
            $table->decimal('free_from', 10, 2)->nullable(); // Бесплатная доставка от суммы
            $table->text('description')->nullable(); // Описание
            $table->json('settings')->nullable(); // Дополнительные настройки
            $table->integer('sort_order')->default(0); // Порядок сортировки
            $table->boolean('is_default')->default(false); // Способ по умолчанию
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
        Schema::dropIfExists('shop_delivery_methods');
    }
};
