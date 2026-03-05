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
        // Проверяем, существует ли таблица shop_user_addresses
        if (Schema::hasTable('shop_user_addresses')) {
            Schema::table('shop_user_addresses', function (Blueprint $table) {
                // Проверяем, существует ли колонка postal_code
                if (! Schema::hasColumn('shop_user_addresses', 'postal_code')) {
                    $table->string('postal_code')->nullable()->after('city'); // Почтовый индекс
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Проверяем, существует ли таблица shop_user_addresses
        if (Schema::hasTable('shop_user_addresses')) {
            Schema::table('shop_user_addresses', function (Blueprint $table) {
                // Проверяем, существует ли колонка postal_code
                if (Schema::hasColumn('shop_user_addresses', 'postal_code')) {
                    $table->dropColumn('postal_code');
                }
            });
        }
    }
};
