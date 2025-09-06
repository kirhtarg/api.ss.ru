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
        // Переименовываем таблицу shop_good_audit в shop_good_audits
        Schema::rename('shop_good_audit', 'shop_good_audits');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Возвращаем обратно название таблицы
        Schema::rename('shop_good_audits', 'shop_good_audit');
    }
};
