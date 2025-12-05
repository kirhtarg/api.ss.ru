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
        Schema::table('site_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('good_id')->nullable()->after('id');
            $table->foreign('good_id')->references('id')->on('shop_goods')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_messages', function (Blueprint $table) {
            $table->dropForeign(['good_id']);
            $table->dropColumn('good_id');
        });
    }
};
