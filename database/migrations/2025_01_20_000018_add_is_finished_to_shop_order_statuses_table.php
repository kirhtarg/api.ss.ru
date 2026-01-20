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
            // Добавляем поле is_finished после is_active
            if (!Schema::hasColumn('shop_order_statuses', 'is_finished')) {
                $table->boolean('is_finished')->default(false)->after('is_active');
                $table->index('is_finished');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_order_statuses', function (Blueprint $table) {
            if (Schema::hasColumn('shop_order_statuses', 'is_finished')) {
                $table->dropIndex(['is_finished']);
                $table->dropColumn('is_finished');
            }
        });
    }
};

