<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_tags', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_tags', 'disables_bonuses')) {
                $table->boolean('disables_bonuses')->default(false)->after('sort_order');
            }
            if (! Schema::hasColumn('shop_tags', 'disables_registered_discount')) {
                $table->boolean('disables_registered_discount')->default(false)->after('disables_bonuses');
            }
            if (! Schema::hasColumn('shop_tags', 'extra_discount_percent')) {
                $table->decimal('extra_discount_percent', 5, 2)->default(0)->after('disables_registered_discount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_tags', function (Blueprint $table) {
            foreach (['extra_discount_percent', 'disables_registered_discount', 'disables_bonuses'] as $column) {
                if (Schema::hasColumn('shop_tags', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
