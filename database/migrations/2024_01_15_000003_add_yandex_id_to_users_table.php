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
            // Проверяем, существует ли колонка yandex_id
            if (! Schema::hasColumn('users', 'yandex_id')) {
                // Если vk_id существует, добавляем yandex_id после него
                if (Schema::hasColumn('users', 'vk_id')) {
                    $table->string('yandex_id')->nullable()->unique()->after('vk_id');
                } elseif (Schema::hasColumn('users', 'google_id')) {
                    // Если vk_id нет, но есть google_id, добавляем после него
                    $table->string('yandex_id')->nullable()->unique()->after('google_id');
                } else {
                    // Иначе добавляем после email
                    $table->string('yandex_id')->nullable()->unique()->after('email');
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('yandex_id');
        });
    }
};
