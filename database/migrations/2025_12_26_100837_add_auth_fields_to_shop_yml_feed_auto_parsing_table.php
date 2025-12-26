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
        Schema::table('shop_yml_feed_auto_parsing', function (Blueprint $table) {
            $table->string('auth_username')->nullable()->after('yml_feed_url');
            $table->string('auth_password')->nullable()->after('auth_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_yml_feed_auto_parsing', function (Blueprint $table) {
            $table->dropColumn(['auth_username', 'auth_password']);
        });
    }
};
