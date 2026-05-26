<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_tags', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_tags', 'increased_bonus_percent')) {
                $table->decimal('increased_bonus_percent', 5, 2)->default(0)->after('extra_discount_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_tags', function (Blueprint $table) {
            if (Schema::hasColumn('shop_tags', 'increased_bonus_percent')) {
                $table->dropColumn('increased_bonus_percent');
            }
        });
    }
};
