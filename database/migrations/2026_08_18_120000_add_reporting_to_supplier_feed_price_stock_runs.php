<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_feed_price_stock_runs', function (Blueprint $table) {
            $table->string('mode', 16)->default('sync')->after('trigger')->index();
        });

        Schema::create('supplier_feed_price_stock_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('supplier_feed_price_stock_runs')->cascadeOnDelete();
            $table->string('sku', 190)->index();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('good_id')->nullable()->index();
            $table->unsignedBigInteger('variation_id')->nullable()->index();
            $table->string('good_name')->nullable();
            $table->string('field', 64);
            $table->string('before_value')->nullable();
            $table->string('after_value')->nullable();
            $table->boolean('is_applied')->default(false);
            $table->timestamps();

            $table->index(['run_id', 'field']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE shop_notification_events MODIFY event_type ENUM('order_created', 'cancellation_request', 'order_cancelled', 'preorder_created', 'site_message', 'backup', 'supplier_feed_sync') NOT NULL");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('shop_notification_events')->where('event_type', 'supplier_feed_sync')->delete();
            DB::statement("ALTER TABLE shop_notification_events MODIFY event_type ENUM('order_created', 'cancellation_request', 'order_cancelled', 'preorder_created', 'site_message', 'backup') NOT NULL");
        }

        Schema::dropIfExists('supplier_feed_price_stock_changes');
        Schema::table('supplier_feed_price_stock_runs', function (Blueprint $table) {
            $table->dropIndex(['mode']);
            $table->dropColumn('mode');
        });
    }
};
