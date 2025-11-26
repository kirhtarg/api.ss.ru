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
        // Мигрируем данные: если is_active = true, но email_verified_at = null,
        // то устанавливаем email_verified_at = created_at
        DB::table('users')
            ->where('is_active', true)
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
            
        // Удаляем колонку is_active
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстанавливаем колонку is_active
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
        
        // Обратная миграция: если email_verified_at заполнено, то is_active = true
        DB::table('users')
            ->whereNotNull('email_verified_at')
            ->update(['is_active' => true]);
            
        // Если email_verified_at пустое, то is_active = false
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['is_active' => false]);
    }
};
