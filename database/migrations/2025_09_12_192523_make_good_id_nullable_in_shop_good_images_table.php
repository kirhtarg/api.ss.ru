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
        Schema::table('shop_good_images', function (Blueprint $table) {
            // Делаем good_id nullable для поддержки вариаций
            $table->unsignedBigInteger('good_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_images', function (Blueprint $table) {
            // Возвращаем good_id как NOT NULL
            $table->unsignedBigInteger('good_id')->nullable(false)->change();
        });
    }
};
