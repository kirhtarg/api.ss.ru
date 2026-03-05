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
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->decimal('demping_price', 10, 2)->nullable()->after('sale_price');
            $table->boolean('show_demping')->default(false)->after('demping_price');
            $table->foreignId('label_id')->nullable()->after('show_demping')->constrained('shop_labels')->onDelete('set null');

            // Индексы для производительности
            $table->index('demping_price');
            $table->index('show_demping');
            $table->index('label_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->dropForeign(['label_id']);
            $table->dropIndex(['label_id']);
            $table->dropIndex(['show_demping']);
            $table->dropIndex(['demping_price']);
            $table->dropColumn(['demping_price', 'show_demping', 'label_id']);
        });
    }
};
