<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_delivery_point_sync_runs', function (Blueprint $table): void {
            $table->unsignedInteger('total_points')->nullable()->after('points_synced');
        });
    }

    public function down(): void
    {
        Schema::table('shop_ozon_delivery_point_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('total_points');
        });
    }
};
