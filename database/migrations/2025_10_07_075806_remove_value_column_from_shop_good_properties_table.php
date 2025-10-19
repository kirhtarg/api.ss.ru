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
        Schema::table('shop_good_properties', function (Blueprint $table) {
            // Удаляем колонку value, так как теперь используем shop_property_value_id
            $table->dropColumn('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_properties', function (Blueprint $table) {
            // Возвращаем колонку value
            $table->text('value')->after('shop_property_value_id');
        });
    }
};
