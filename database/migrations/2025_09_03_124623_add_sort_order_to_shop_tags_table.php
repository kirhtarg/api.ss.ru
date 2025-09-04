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
        Schema::table('shop_tags', function (Blueprint $table) {
            // Добавляем sort_order только если колонка не существует
            if (!Schema::hasColumn('shop_tags', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('is_active');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_tags', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
