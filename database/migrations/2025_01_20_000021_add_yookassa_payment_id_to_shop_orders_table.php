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
        Schema::table('shop_orders', function (Blueprint $table) {
            // Добавляем поле yookassa_payment_id для хранения ID платежа в ЮKassa
            if (! Schema::hasColumn('shop_orders', 'yookassa_payment_id')) {
                $table->string('yookassa_payment_id')->nullable()->after('yandex_pay_order_id');
                $table->index('yookassa_payment_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'yookassa_payment_id')) {
                $table->dropIndex(['yookassa_payment_id']);
                $table->dropColumn('yookassa_payment_id');
            }
        });
    }
};
