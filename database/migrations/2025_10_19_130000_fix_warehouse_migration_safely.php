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
        // Эта миграция ничего не делает, так как warehouse_id уже должен быть добавлен
        // предыдущей миграцией
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Безопасно удаляем warehouse_id если он существует
        if (Schema::hasTable('shop_stocks') && Schema::hasColumn('shop_stocks', 'warehouse_id')) {
            // Получаем список всех внешних ключей для таблицы shop_stocks
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'shop_stocks' 
                AND COLUMN_NAME = 'warehouse_id' 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            // Удаляем все найденные внешние ключи
            foreach ($foreignKeys as $key) {
                try {
                    DB::statement("ALTER TABLE shop_stocks DROP FOREIGN KEY {$key->CONSTRAINT_NAME}");
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            // Удаляем колонку
            try {
                Schema::table('shop_stocks', function (Blueprint $table) {
                    $table->dropColumn('warehouse_id');
                });
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
        }
    }
};
