<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_sync_runs', function (Blueprint $table) {
            $table->foreignId('parent_run_id')
                ->nullable()
                ->after('user_id')
                ->constrained('shop_ozon_sync_runs')
                ->nullOnDelete();
            $table->unique(['parent_run_id', 'mode'], 'shop_ozon_sync_runs_parent_mode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shop_ozon_sync_runs', function (Blueprint $table) {
            $table->dropUnique('shop_ozon_sync_runs_parent_mode_unique');
            $table->dropConstrainedForeignId('parent_run_id');
        });
    }
};
