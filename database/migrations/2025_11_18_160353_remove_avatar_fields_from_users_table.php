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
            // Удаляем только поле avatar из таблицы users
            // Поле avatar_url оставляем для OAuth авторизации (Google, VK, Yandex)
            // Локальные аватары хранятся в папке images/users/user_{id}.jpg
            if (Schema::hasColumn('users', 'avatar')) {
                $table->dropColumn('avatar');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Восстанавливаем поле avatar при откате миграции
            $table->string('avatar')->nullable()->after('email');
        });
    }
};
