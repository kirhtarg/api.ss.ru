<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавить поле birthday_discount_amount в таблицу orders
     *
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            // Скидка ко дню рождения
            if (! Schema::hasColumn('shop_orders', 'birthday_discount_amount')) {
                $table->decimal('birthday_discount_amount', 10, 2)->default(0)->after('promo_code_discount_amount')->comment('Скидка в честь дня рождения');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'birthday_discount_amount')) {
                $table->dropColumn('birthday_discount_amount');
            }
        });
    }
};
