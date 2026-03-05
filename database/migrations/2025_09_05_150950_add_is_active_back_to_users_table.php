<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('email_verified_at');
        });

        // Устанавливаем is_active = true для всех пользователей с email_verified_at
        DB::table('users')
            ->whereNotNull('email_verified_at')
            ->update(['is_active' => true]);

        // Устанавливаем is_active = false для пользователей без email_verified_at
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['is_active' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
