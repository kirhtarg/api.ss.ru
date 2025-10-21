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
        Schema::table('shop_cdek_settings', function (Blueprint $table) {
            $table->string('sender_city_code')->nullable()->after('sender_country_code');
            $table->string('developer_key')->nullable()->after('sender_city_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_cdek_settings', function (Blueprint $table) {
            $table->dropColumn(['sender_city_code', 'developer_key']);
        });
    }
};
