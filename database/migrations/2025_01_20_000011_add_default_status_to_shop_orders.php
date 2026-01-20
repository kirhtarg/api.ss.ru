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
        // Добавляем статус по умолчанию для существующих заказов
        $defaultStatusId = DB::table('shop_order_statuses')
            ->where('name', 'Обрабатывается')
            ->value('id');
        
        if ($defaultStatusId) {
            DB::table('shop_orders')
                ->whereNull('status_id')
                ->update(['status_id' => $defaultStatusId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Не нужно ничего делать при откате
    }
};
