<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_orders', 'certificate_code')) {
                $table->string('certificate_code', 255)->nullable()->after('promo_code');
            }

            if (! Schema::hasColumn('shop_orders', 'has_certificate')) {
                $table->boolean('has_certificate')->default(false)->after('certificate_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'has_certificate')) {
                $table->dropColumn('has_certificate');
            }

            if (Schema::hasColumn('shop_orders', 'certificate_code')) {
                $table->dropColumn('certificate_code');
            }
        });
    }
};
