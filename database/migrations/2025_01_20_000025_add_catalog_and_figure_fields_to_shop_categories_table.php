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
        Schema::table('shop_categories', function (Blueprint $table) {
            $table->boolean('in_catalog')->default(false)->after('is_main');
            $table->boolean('in_figure')->default(false)->after('in_catalog');
            $table->string('in_figure_img')->nullable()->after('in_figure');
            $table->text('in_figure_text')->nullable()->after('in_figure_img');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_categories', function (Blueprint $table) {
            $table->dropColumn(['in_catalog', 'in_figure', 'in_figure_img', 'in_figure_text']);
        });
    }
};

