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
        Schema::create('promocodes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed_amount', 'free_delivery']);
            $table->decimal('value', 10, 2)->nullable(); // Для процентной скидки или фиксированной суммы
            $table->decimal('min_order_amount', 10, 2)->nullable(); // Минимальная сумма заказа
            $table->decimal('max_discount_amount', 10, 2)->nullable(); // Максимальная сумма скидки
            $table->integer('usage_limit')->nullable(); // Лимит использований (null = безлимит)
            $table->integer('used_count')->default(0); // Количество использований
            $table->integer('usage_limit_per_user')->nullable(); // Лимит использований на пользователя
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable(); // Дата начала действия
            $table->timestamp('expires_at')->nullable(); // Дата окончания действия
            $table->json('applicable_categories')->nullable(); // Категории товаров (JSON массив ID)
            $table->json('applicable_goods')->nullable(); // Конкретные товары (JSON массив ID)
            $table->json('applicable_variations')->nullable(); // Конкретные вариации (JSON массив ID)
            $table->timestamps();
            
            $table->index(['code', 'is_active']);
            $table->index(['starts_at', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocodes');
    }
};
