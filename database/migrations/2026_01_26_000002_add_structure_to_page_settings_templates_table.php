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
        Schema::table('page_settings_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('page_settings_templates', 'structure')) {
                $table->json('structure')->nullable()->after('settings');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('page_settings_templates', function (Blueprint $table) {
            if (Schema::hasColumn('page_settings_templates', 'structure')) {
                $table->dropColumn('structure');
            }
        });
    }
};
