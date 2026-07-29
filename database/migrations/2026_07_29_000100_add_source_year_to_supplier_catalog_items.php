<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->string('source_year')->nullable()->after('source_size');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->dropColumn('source_year');
        });
    }
};
