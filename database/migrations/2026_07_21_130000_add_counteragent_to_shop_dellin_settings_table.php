<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_dellin_settings', function (Blueprint $table) {
            $table->string('counteragent_uid', 100)->nullable()->after('session_expires_at');
            $table->json('counteragent_data')->nullable()->after('counteragent_uid');
        });
    }

    public function down(): void
    {
        Schema::table('shop_dellin_settings', function (Blueprint $table) {
            $table->dropColumn(['counteragent_uid', 'counteragent_data']);
        });
    }
};
