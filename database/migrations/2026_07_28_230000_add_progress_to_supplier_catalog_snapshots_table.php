<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalog_snapshots', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
            $table->string('stage')->nullable()->after('progress');
            $table->unsignedInteger('processed_rows')->default(0)->after('stage');
            $table->unsignedInteger('total_rows')->default(0)->after('processed_rows');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_catalog_snapshots', function (Blueprint $table) {
            $table->dropColumn(['progress', 'stage', 'processed_rows', 'total_rows']);
        });
    }
};
