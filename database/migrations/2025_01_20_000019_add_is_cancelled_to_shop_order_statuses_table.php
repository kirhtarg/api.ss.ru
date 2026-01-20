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
        Schema::table('shop_order_statuses', function (Blueprint $table) {
            $table->boolean('is_cancelled')->default(false)->after('is_finished');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_order_statuses', function (Blueprint $table) {
            $table->dropColumn('is_cancelled');
        });
    }
};

