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
        // Переименовываем таблицу только если она еще не переименована
        if (Schema::hasTable('social_types') && !Schema::hasTable('contact_social_types')) {
            Schema::rename('social_types', 'contact_social_types');
        }
        
        // Если таблица contact_social_types уже существует, просто обновляем внешний ключ
        if (Schema::hasTable('contact_social_types') && Schema::hasTable('contact_socials')) {
            // Получаем список всех внешних ключей для колонки social_type
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'contact_socials' 
                AND COLUMN_NAME = 'social_type' 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            // Удаляем все найденные внешние ключи
            foreach ($foreignKeys as $key) {
                try {
                    DB::statement("ALTER TABLE contact_socials DROP FOREIGN KEY {$key->CONSTRAINT_NAME}");
                } catch (\Exception $e) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
            }
            
            // Добавляем новый внешний ключ на contact_social_types
            try {
                Schema::table('contact_socials', function (Blueprint $table) {
                    $table->foreign('social_type')
                        ->references('id')
                        ->on('contact_social_types')
                        ->onDelete('cascade');
                });
            } catch (\Exception $e) {
                // Игнорируем ошибку, если внешний ключ уже существует
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем внешний ключ
        if (Schema::hasTable('contact_socials')) {
            try {
                DB::statement('ALTER TABLE contact_socials DROP FOREIGN KEY contact_socials_social_type_foreign');
            } catch (\Exception $e) {
                // Игнорируем ошибку
            }
        }
        
        // Переименовываем обратно
        if (Schema::hasTable('contact_social_types') && !Schema::hasTable('social_types')) {
            Schema::rename('contact_social_types', 'social_types');
        }
        
        // Восстанавливаем внешний ключ
        if (Schema::hasTable('contact_socials')) {
            Schema::table('contact_socials', function (Blueprint $table) {
                $table->foreign('social_type')
                    ->references('id')
                    ->on('social_types')
                    ->onDelete('cascade');
            });
        }
    }
};

