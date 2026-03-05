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
        // Сначала добавляем новые колонки
        Schema::table('shop_orders', function (Blueprint $table) {
            // Добавляем новую колонку payed (boolean, default 0)
            if (! Schema::hasColumn('shop_orders', 'payed')) {
                $table->boolean('payed')->default(0)->after('status_id');
            }

            // Добавляем колонку is_active (boolean, default false)
            if (! Schema::hasColumn('shop_orders', 'is_active')) {
                $table->boolean('is_active')->default(false)->after('payed');
            }
        });

        // Обновляем существующие записи: если payment_status_id был 2 (paid), то payed = 1
        // Это нужно сделать только если таблица shop_payment_statuses существует и колонка payment_status_id еще есть
        if (Schema::hasTable('shop_payment_statuses') && Schema::hasColumn('shop_orders', 'payment_status_id')) {
            try {
                DB::statement('
                    UPDATE shop_orders 
                    SET payed = CASE 
                        WHEN payment_status_id = 2 THEN 1 
                        ELSE 0 
                    END
                    WHERE payment_status_id IS NOT NULL
                ');
            } catch (\Exception $e) {
                // Если ошибка, просто устанавливаем все в 0
                DB::table('shop_orders')->update(['payed' => 0]);
            }
        } else {
            // Если таблицы или колонки нет, просто устанавливаем все в 0
            DB::table('shop_orders')->update(['payed' => 0]);
        }

        // Устанавливаем is_active = false для всех существующих записей
        DB::table('shop_orders')->update(['is_active' => false]);

        // Теперь удаляем старую колонку payment_status_id, если она существует
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'payment_status_id')) {
                // Пытаемся удалить внешний ключ, если существует
                try {
                    $table->dropForeign(['payment_status_id']);
                } catch (\Exception $e) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
                $table->dropColumn('payment_status_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            // Удаляем новые колонки
            if (Schema::hasColumn('shop_orders', 'payed')) {
                $table->dropColumn('payed');
            }

            if (Schema::hasColumn('shop_orders', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });

        // Восстанавливаем payment_status_id, если нужно
        if (Schema::hasTable('shop_payment_statuses')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('shop_orders', 'payment_status_id')) {
                    $table->unsignedBigInteger('payment_status_id')->default(1)->after('status_id');
                }
            });
        }
    }
};
