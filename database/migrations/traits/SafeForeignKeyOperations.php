<?php

namespace Database\Migrations\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait SafeForeignKeyOperations
{
    /**
     * Безопасно удаляет внешний ключ, если он существует
     */
    protected function safeDropForeign(Blueprint $table, string $column): void
    {
        try {
            $table->dropForeign([$column]);
        } catch (\Exception $e) {
            // Игнорируем ошибку, если внешний ключ не существует
        }
    }

    /**
     * Безопасно создает внешний ключ, если таблица существует
     */
    protected function safeAddForeign(Blueprint $table, string $column, string $referencedTable, string $referencedColumn = 'id', string $onDelete = 'cascade'): void
    {
        if (Schema::hasTable($referencedTable)) {
            try {
                $table->foreign($column)->references($referencedColumn)->on($referencedTable)->onDelete($onDelete);
            } catch (\Exception $e) {
                // Игнорируем ошибку, если не удается создать внешний ключ
            }
        }
    }

    /**
     * Проверяет, существует ли внешний ключ
     */
    protected function foreignKeyExists(string $table, string $column): bool
    {
        try {
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ? 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ", [$table, $column]);
            
            return !empty($foreignKeys);
        } catch (\Exception $e) {
            return false;
        }
    }
}
