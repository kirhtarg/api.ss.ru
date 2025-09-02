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
            // Добавляем недостающие поля
            $table->decimal('depth', 8, 2)->nullable()->after('height');
            $table->boolean('is_new')->default(false)->after('is_featured');
            $table->boolean('is_sale')->default(false)->after('is_new');
            $table->integer('sort_order')->default(0)->after('is_sale');
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
