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
        Schema::table('shop_good_audits', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_good_audits', 'ip_address')) {
                $table->string('ip_address')->nullable()->after('new_values');
            }
            if (! Schema::hasColumn('shop_good_audits', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip_address');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_audits', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('shop_good_audits', 'ip_address')) {
                $columnsToDrop[] = 'ip_address';
            }
            if (Schema::hasColumn('shop_good_audits', 'user_agent')) {
                $columnsToDrop[] = 'user_agent';
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
