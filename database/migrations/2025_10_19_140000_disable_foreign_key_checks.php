<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Отключаем проверки внешних ключей для всех операций
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Включаем проверки внешних ключей обратно
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
};
