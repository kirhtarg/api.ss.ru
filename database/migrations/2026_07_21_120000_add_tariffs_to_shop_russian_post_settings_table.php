<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_russian_post_settings', function (Blueprint $table) {
            $table->json('tariffs')->nullable()->after('default_height');
        });
    }

    public function down(): void
    {
        Schema::table('shop_russian_post_settings', function (Blueprint $table) {
            $table->dropColumn('tariffs');
        });
    }
};
