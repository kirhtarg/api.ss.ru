<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_ozon_delivery_point_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->index();
            $table->unsignedInteger('pages_synced')->default(0);
            $table->unsignedInteger('points_synced')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_ozon_delivery_points', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_point_id')->primary();
            $table->string('name')->nullable();
            $table->text('full_address')->nullable();
            $table->longText('search_text');
            $table->json('shipment_method_ids')->nullable();
            $table->json('point_data');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('last_sync_run_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_ozon_delivery_points');
        Schema::dropIfExists('shop_ozon_delivery_point_sync_runs');
    }
};
