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
        // Добавляем тестовый аватар для администратора
        DB::table('users')
            ->where('email', 'admin@skateandsnow.ru')
            ->update([
                'avatar' => 'avatars/default-admin-avatar.png',
                'updated_at' => now()
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Убираем тестовый аватар
        DB::table('users')
            ->where('email', 'admin@skateandsnow.ru')
            ->update([
                'avatar' => null,
                'updated_at' => now()
            ]);
    }
};
