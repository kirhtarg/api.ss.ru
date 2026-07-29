<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasForeignKey = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'shop_good_variations')
            ->where('COLUMN_NAME', 'good_id')
            ->where('REFERENCED_TABLE_NAME', 'shop_goods')
            ->exists();

        if ($hasForeignKey) {
            return;
        }

        $orphanCount = DB::table('shop_good_variations as v')
            ->leftJoin('shop_goods as g', 'g.id', '=', 'v.good_id')
            ->whereNull('g.id')
            ->count();

        if ($orphanCount > 0) {
            throw new RuntimeException(
                "Нельзя добавить каскадный внешний ключ: найдены осиротевшие вариации ({$orphanCount}). "
                .'Сначала выполните их резервное удаление штатной командой обслуживания.'
            );
        }

        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->foreign('good_id', 'shop_good_variations_good_id_foreign')
                ->references('id')
                ->on('shop_goods')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->dropForeign('shop_good_variations_good_id_foreign');
        });
    }
};
