<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_order_logs', function (Blueprint $table) {
            // Удаляем внешний ключ, чтобы можно было использовать таблицу для разных сущностей
            $table->dropForeign(['order_id']);

            // Переименовываем order_id в entity_id для универсальности
            $table->renameColumn('order_id', 'entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('shop_order_logs', function (Blueprint $table) {
            // Возвращаем название колонки
            $table->renameColumn('entity_id', 'order_id');

            // Восстанавливаем внешний ключ
            $table->foreign('order_id')->references('id')->on('shop_orders')->onDelete('cascade');
        });
    }
};
