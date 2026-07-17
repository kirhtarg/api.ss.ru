<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_sync_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_ozon_sync_runs', 'mapping_ids')) {
                $table->json('mapping_ids')->nullable()->after('good_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_ozon_sync_runs', function (Blueprint $table) {
            if (Schema::hasColumn('shop_ozon_sync_runs', 'mapping_ids')) {
                $table->dropColumn('mapping_ids');
            }
        });
    }
};
