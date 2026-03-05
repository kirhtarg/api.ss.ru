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
        if (Schema::hasTable('shop_properties')) {
            if (! Schema::hasColumn('shop_properties', 'description')) {
                Schema::table('shop_properties', function (Blueprint $table) {
                    $table->text('description')->nullable()->after('name')
                        ->comment('Описание свойства');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shop_properties')) {
            if (Schema::hasColumn('shop_properties', 'description')) {
                Schema::table('shop_properties', function (Blueprint $table) {
                    $table->dropColumn('description');
                });
            }
        }
    }
};
