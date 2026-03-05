<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Отключаем проверку внешних ключей
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Очищаем связанные таблицы сначала, если они существуют
        if (Schema::hasTable('shop_payment_transactions')) {
            DB::table('shop_payment_transactions')->delete();
        }

        if (Schema::hasTable('shop_order_items')) {
            DB::table('shop_order_items')->delete();
        }

        if (Schema::hasTable('shop_orders')) {
            DB::table('shop_orders')->delete();
        }

        // Включаем проверку внешних ключей обратно
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстановление данных не предусмотрено
    }
};
