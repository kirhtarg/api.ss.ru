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
        Schema::table('shop_stocks', function (Blueprint $table) {
            // Добавляем поле min_quantity если его нет
            if (! Schema::hasColumn('shop_stocks', 'min_quantity')) {
                $table->integer('min_quantity')->default(0)->after('reserved_quantity');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_stocks', function (Blueprint $table) {
            // Удаляем поле min_quantity при откате
            if (Schema::hasColumn('shop_stocks', 'min_quantity')) {
                $table->dropColumn('min_quantity');
            }
        });
    }
};
