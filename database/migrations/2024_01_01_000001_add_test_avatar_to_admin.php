<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Проверяем, что колонка avatar существует
        if (Schema::hasColumn('users', 'avatar')) {
            // Добавляем тестовый аватар для администратора
            DB::table('users')
                ->where('email', 'admin@skateandsnow.ru')
                ->update([
                    'avatar' => 'avatars/default-admin-avatar.png',
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Проверяем, что колонка avatar существует
        if (Schema::hasColumn('users', 'avatar')) {
            // Убираем тестовый аватар
            DB::table('users')
                ->where('email', 'admin@skateandsnow.ru')
                ->update([
                    'avatar' => null,
                    'updated_at' => now(),
                ]);
        }
    }
};
