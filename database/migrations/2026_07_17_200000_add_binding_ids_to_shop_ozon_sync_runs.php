<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_sync_runs', function (Blueprint $table) {
            $table->json('binding_ids')->nullable()->after('good_ids');
        });
    }

    public function down(): void
    {
        Schema::table('shop_ozon_sync_runs', function (Blueprint $table) {
            $table->dropColumn('binding_ids');
        });
    }
};
