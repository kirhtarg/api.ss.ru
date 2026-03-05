<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Проверяем, что таблицы статусов существуют и содержат нужные записи
        if (! Schema::hasTable('shop_payment_statuses') || ! Schema::hasTable('shop_delivery_statuses')) {
            throw new \Exception('Таблицы статусов должны быть созданы перед добавлением колонок в shop_orders');
        }

        // Проверяем, что в таблицах статусов есть записи с ID 1
        $paymentStatusExists = DB::table('shop_payment_statuses')->where('id', 1)->exists();
        $deliveryStatusExists = DB::table('shop_delivery_statuses')->where('id', 1)->exists();

        if (! $paymentStatusExists || ! $deliveryStatusExists) {
            throw new \Exception('В таблицах статусов должны быть записи с ID 1');
        }

        // Добавляем колонки если их нет
        if (! Schema::hasColumn('shop_orders', 'payment_status_id')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_status_id')->default(1)->after('status_id');
            });
        }

        if (! Schema::hasColumn('shop_orders', 'delivery_status_id')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('delivery_status_id')->default(1)->after('payment_status_id');
            });
        }

        // Обновляем ВСЕ существующие записи значениями по умолчанию
        DB::table('shop_orders')->update([
            'payment_status_id' => 1,
            'delivery_status_id' => 1,
        ]);

        // Добавляем индексы
        Schema::table('shop_orders', function (Blueprint $table) {
            if (! Schema::hasIndex('shop_orders', 'shop_orders_payment_status_id_index')) {
                $table->index(['payment_status_id']);
            }
            if (! Schema::hasIndex('shop_orders', 'shop_orders_delivery_status_id_index')) {
                $table->index(['delivery_status_id']);
            }
        });

        // Добавляем внешние ключи
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->foreign('payment_status_id')->references('id')->on('shop_payment_statuses')->onDelete('restrict');
            $table->foreign('delivery_status_id')->references('id')->on('shop_delivery_statuses')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropForeign(['payment_status_id']);
            $table->dropForeign(['delivery_status_id']);
            $table->dropIndex(['payment_status_id']);
            $table->dropIndex(['delivery_status_id']);
            $table->dropColumn(['payment_status_id', 'delivery_status_id']);
        });
    }
};
