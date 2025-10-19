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
            // Поля для скидок
            $table->decimal('sale_discount_amount', 10, 2)->default(0)->after('discount_amount'); // Скидка по акционным ценам
            $table->decimal('registered_user_discount_amount', 10, 2)->default(0)->after('sale_discount_amount'); // Скидка для зарегистрированных пользователей
            $table->decimal('promo_code_discount_amount', 10, 2)->default(0)->after('registered_user_discount_amount'); // Скидка по промокоду
            $table->decimal('total_discount_amount', 10, 2)->default(0)->after('promo_code_discount_amount'); // Общая сумма всех скидок
            
            // Поля для промокода
            $table->string('promo_code', 50)->nullable()->after('total_discount_amount'); // Код промокода
            $table->unsignedBigInteger('promo_code_id')->nullable()->after('promo_code'); // ID промокода
            
            // Поля для бонусов
            $table->boolean('use_bonus_points')->default(false)->after('promo_code_id'); // Использованы ли бонусы
            $table->integer('bonus_points_to_use')->default(0)->after('use_bonus_points'); // Количество списанных бонусов
            $table->integer('order_bonus_points')->default(0)->after('bonus_points_to_use'); // Бонусы за заказ
            
            // Стоимость доставки
            $table->decimal('delivery_cost', 10, 2)->default(0)->after('order_bonus_points'); // Стоимость доставки
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropColumn([
                'sale_discount_amount',
                'registered_user_discount_amount', 
                'promo_code_discount_amount',
                'total_discount_amount',
                'promo_code',
                'promo_code_id',
                'use_bonus_points',
                'bonus_points_to_use',
                'order_bonus_points',
                'delivery_cost'
            ]);
        });
    }
};
