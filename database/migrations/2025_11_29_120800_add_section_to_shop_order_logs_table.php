<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_order_logs', function (Blueprint $table) {
            $table->string('section', 50)->nullable()->default('orders')->after('user_name');
            $table->index('section');
        });
    }

    public function down(): void
    {
        Schema::table('shop_order_logs', function (Blueprint $table) {
            $table->dropIndex(['section']);
            $table->dropColumn('section');
        });
    }
};
