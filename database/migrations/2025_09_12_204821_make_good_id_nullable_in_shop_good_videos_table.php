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
        Schema::table('shop_good_videos', function (Blueprint $table) {
            $table->unsignedBigInteger('good_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_videos', function (Blueprint $table) {
            $table->unsignedBigInteger('good_id')->nullable(false)->change();
        });
    }
};
