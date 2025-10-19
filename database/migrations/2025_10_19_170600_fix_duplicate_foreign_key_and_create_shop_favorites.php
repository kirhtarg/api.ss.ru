<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Проверяем и удаляем дублирующийся внешний ключ в promocode_usage
        $this->dropForeignKeyIfExists('promocode_usage', 'promocode_usage_order_id_foreign');
        
        // Создаем таблицу shop_favorites
        Schema::create('shop_favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('good_id');
            $table->timestamps();
            
            // Индексы и внешние ключи
            $table->unique(['user_id', 'good_id'], 'shop_favorites_user_id_good_id_unique');
            $table->index('user_id', 'shop_favorites_user_id_foreign');
            $table->index('good_id', 'shop_favorites_good_id_foreign');
        });
    }

    public function down(): void
    {
        // Удаляем таблицу shop_favorites
        Schema::dropIfExists('shop_favorites');
    }

    private function dropForeignKeyIfExists($table, $constraintName)
    {
        try {
            // Проверяем, существует ли внешний ключ
            $exists = DB::select("
                SELECT COUNT(*) as count 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND CONSTRAINT_NAME = ?
            ", [$table, $constraintName]);

            if ($exists[0]->count > 0) {
                DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$constraintName}");
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }
};
