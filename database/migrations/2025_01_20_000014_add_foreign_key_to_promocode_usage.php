<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Проверяем, существует ли уже внешний ключ
        $exists = DB::select("
            SELECT COUNT(*) as count 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'promocode_usage' 
            AND CONSTRAINT_NAME = 'promocode_usage_order_id_foreign'
        ");

        if ($exists[0]->count == 0) {
            Schema::table('promocode_usage', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('shop_orders')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('promocode_usage', function (Blueprint $table) {
                $table->dropForeign(['order_id']);
            });
        } catch (Exception $e) {
            // Игнорируем ошибку, если ключ не существует
        }
    }
};
