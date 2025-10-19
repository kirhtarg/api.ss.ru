<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Сначала исправим проблему с дублированием внешнего ключа в promocode_usage
        try {
            DB::statement('ALTER TABLE promocode_usage DROP FOREIGN KEY promocode_usage_order_id_foreign');
        } catch (Exception $e) {
            // Игнорируем ошибку, если ключ не существует
        }

        // Создаем таблицу shop_favorites
        Schema::create('shop_favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('good_id');
            $table->timestamps();
            
            // Индексы и внешние ключи
            $table->unique(['user_id', 'good_id'], 'shop_favorites_user_id_good_id_unique');
            $table->index('user_id', 'shop_favorites_user_id_foreign');
            $table->index('good_id', 'shop_favorites_good_id_foreign');
        });
    }

    public function down(): void
    {
        // Удаляем таблицу shop_favorites
        Schema::dropIfExists('shop_favorites');
        
        // Восстанавливаем внешний ключ для promocode_usage (если нужно)
        try {
            DB::statement('ALTER TABLE promocode_usage ADD CONSTRAINT promocode_usage_order_id_foreign FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE SET NULL');
        } catch (Exception $e) {
            // Игнорируем ошибку
        }
    }
};
