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
        Schema::table('users', function (Blueprint $table) {
            // Проверяем, существуют ли уже поля
            if (! Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name', 255)->nullable()->after('name')->comment('Имя пользователя');
            }
            if (! Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name', 255)->nullable()->after('first_name')->comment('Фамилия пользователя');
            }
            if (! Schema::hasColumn('users', 'birthday')) {
                $table->date('birthday')->nullable()->after('last_name')->comment('Дата рождения пользователя');
            }
            if (! Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url', 500)->nullable()->after('birthday')->comment('URL аватара пользователя');
            }
        });

        // Добавляем индексы для оптимизации поиска
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasIndex('users', 'idx_users_first_name')) {
                $table->index('first_name', 'idx_users_first_name');
            }
            if (! Schema::hasIndex('users', 'idx_users_last_name')) {
                $table->index('last_name', 'idx_users_last_name');
            }
            if (! Schema::hasIndex('users', 'idx_users_birthday')) {
                $table->index('birthday', 'idx_users_birthday');
            }
        });

        // Обновляем существующие записи, если есть данные в поле name
        DB::statement("
            UPDATE users 
            SET 
                first_name = CASE 
                    WHEN name IS NOT NULL AND name != '' AND LOCATE(' ', name) > 0 
                    THEN SUBSTRING(name, 1, LOCATE(' ', name) - 1)
                    ELSE name
                END,
                last_name = CASE 
                    WHEN name IS NOT NULL AND name != '' AND LOCATE(' ', name) > 0 
                    THEN SUBSTRING(name, LOCATE(' ', name) + 1)
                    ELSE NULL
                END
            WHERE name IS NOT NULL AND name != '' AND (first_name IS NULL OR first_name = '')
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasIndex('users', 'idx_users_first_name')) {
                $table->dropIndex('idx_users_first_name');
            }
            if (Schema::hasIndex('users', 'idx_users_last_name')) {
                $table->dropIndex('idx_users_last_name');
            }
            if (Schema::hasIndex('users', 'idx_users_birthday')) {
                $table->dropIndex('idx_users_birthday');
            }

            if (Schema::hasColumn('users', 'first_name')) {
                $table->dropColumn('first_name');
            }
            if (Schema::hasColumn('users', 'last_name')) {
                $table->dropColumn('last_name');
            }
            if (Schema::hasColumn('users', 'birthday')) {
                $table->dropColumn('birthday');
            }
            if (Schema::hasColumn('users', 'avatar_url')) {
                $table->dropColumn('avatar_url');
            }
        });
    }
};
