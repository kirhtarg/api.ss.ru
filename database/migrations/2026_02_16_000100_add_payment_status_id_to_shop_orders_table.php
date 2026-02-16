<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_orders', 'payment_status_id')) {
                $table->unsignedBigInteger('payment_status_id')->nullable()->after('status_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'payment_status_id')) {
                $table->dropColumn('payment_status_id');
            }
        });
    }
};

