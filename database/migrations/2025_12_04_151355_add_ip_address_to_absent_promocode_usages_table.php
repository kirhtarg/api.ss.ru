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
        // Удаляем старый foreign key и уникальный индекс
        Schema::table('absent_promocode_usages', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        
        Schema::table('absent_promocode_usages', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'good_id']);
        });
        
        // Делаем user_id nullable для незарегистрированных пользователей
        Schema::table('absent_promocode_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
        
        // Добавляем поле ip_address
        Schema::table('absent_promocode_usages', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('user_id');
        });
        
        // Восстанавливаем foreign key и добавляем индексы
        Schema::table('absent_promocode_usages', function (Blueprint $table) {
            // Восстанавливаем foreign key только для не-null значений
            // В MySQL foreign key на nullable колонку работает корректно только если значение не null
            // Для null значений проверка не выполняется, что нам и нужно
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Добавляем новые индексы
            $table->index(['user_id', 'good_id']);
            $table->index(['ip_address', 'good_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('absent_promocode_usages', function (Blueprint $table) {
            // Удаляем индексы
            $table->dropIndex(['user_id', 'good_id']);
            $table->dropIndex(['ip_address', 'good_id']);
            
            // Удаляем поле ip_address
            $table->dropColumn('ip_address');
            
            // Удаляем foreign key
            $table->dropForeign(['user_id']);
            
            // Возвращаем user_id как NOT NULL
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            
            // Восстанавливаем foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Восстанавливаем старый уникальный индекс
            $table->unique(['user_id', 'good_id']);
        });
    }
};
