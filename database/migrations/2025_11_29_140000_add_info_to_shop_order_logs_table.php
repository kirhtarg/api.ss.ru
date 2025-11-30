<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_order_logs', function (Blueprint $table) {
            $table->string('info', 500)->nullable()->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('shop_order_logs', function (Blueprint $table) {
            $table->dropColumn('info');
        });
    }
};






