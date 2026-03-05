<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('shop_stock', 'shop_stocks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('shop_stocks', 'shop_stock');
    }
};
