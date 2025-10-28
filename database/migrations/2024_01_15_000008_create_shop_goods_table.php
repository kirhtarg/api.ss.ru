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
        Schema::create('shop_goods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->unique()->nullable(); // Артикул
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->decimal('width', 8, 2)->nullable(); // Ширина в см
            $table->decimal('height', 8, 2)->nullable(); // Высота в см
            $table->decimal('depth', 8, 2)->nullable(); // Глубина в см
            $table->decimal('weight', 8, 2)->nullable(); // Вес в кг
            $table->decimal('rating', 3, 2)->default(0); // Рейтинг от 0 до 5
            $table->integer('reviews_count')->default(0);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false); // Рекомендуемый товар
            $table->boolean('is_new')->default(false); // Новый товар
            $table->boolean('is_sale')->default(false); // Товар со скидкой
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            // Индексы для производительности
            $table->index(['is_active', 'sort_order']);
            $table->index(['is_featured', 'is_active']);
            $table->index(['is_new', 'is_active']);
            $table->index(['is_sale', 'is_active']);
            $table->index('sku');
            $table->index('slug');
            $table->index('price');
            $table->index('rating');
            $table->index('stock_quantity');
            $table->index('created_at');
            
            // Составные индексы для поиска
            $table->index(['name', 'is_active'], 'shop_goods_name_active_idx');
            $table->index(['sku', 'is_active'], 'shop_goods_sku_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_goods');
    }
};
