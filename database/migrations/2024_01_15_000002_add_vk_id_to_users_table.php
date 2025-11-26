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
            // Проверяем, существует ли колонка vk_id
            if (!Schema::hasColumn('users', 'vk_id')) {
                // Если google_id существует, добавляем vk_id после него
                if (Schema::hasColumn('users', 'google_id')) {
                    $table->string('vk_id')->nullable()->unique()->after('google_id');
                } else {
                    // Иначе добавляем после email
                    $table->string('vk_id')->nullable()->unique()->after('email');
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
            $table->dropColumn('vk_id');
        });
    }
};
