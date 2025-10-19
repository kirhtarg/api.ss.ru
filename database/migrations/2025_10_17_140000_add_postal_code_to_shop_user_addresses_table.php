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
        Schema::table('shop_user_addresses', function (Blueprint $table) {
            $table->string('postal_code')->nullable()->after('city'); // Почтовый индекс
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_user_addresses', function (Blueprint $table) {
            $table->dropColumn('postal_code');
        });
    }
};
