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
        Schema::table('shop_goods', function (Blueprint $table) {
            // Добавляем недостающие поля только если они не существуют
            if (!Schema::hasColumn('shop_goods', 'depth')) {
                $table->decimal('depth', 8, 2)->nullable()->after('height');
            }
            if (!Schema::hasColumn('shop_goods', 'is_new')) {
                $table->boolean('is_new')->default(false)->after('is_featured');
            }
            if (!Schema::hasColumn('shop_goods', 'is_sale')) {
                $table->boolean('is_sale')->default(false)->after('is_new');
            }
            if (!Schema::hasColumn('shop_goods', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('is_sale');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            // Удаляем добавленные поля
            $table->dropColumn(['depth', 'is_new', 'is_sale', 'sort_order']);
        });
    }
};
