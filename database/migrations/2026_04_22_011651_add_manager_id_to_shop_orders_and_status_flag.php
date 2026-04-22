<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_orders', 'manager_id')) {
                $table->unsignedBigInteger('manager_id')->nullable()->after('user_id');
                $table->index('manager_id');
            }
        });

        Schema::table('shop_order_statuses', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_order_statuses', 'is_taken_to_work')) {
                $table->boolean('is_taken_to_work')->default(false)->after('is_cancelled');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'manager_id')) {
                $table->dropIndex(['manager_id']);
                $table->dropColumn('manager_id');
            }
        });

        Schema::table('shop_order_statuses', function (Blueprint $table) {
            if (Schema::hasColumn('shop_order_statuses', 'is_taken_to_work')) {
                $table->dropColumn('is_taken_to_work');
            }
        });
    }

};
