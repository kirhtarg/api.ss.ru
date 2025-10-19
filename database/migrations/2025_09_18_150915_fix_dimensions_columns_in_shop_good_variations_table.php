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
        Schema::table('shop_good_variations', function (Blueprint $table) {
            // Добавляем колонку length, если её нет
            if (!Schema::hasColumn('shop_good_variations', 'length')) {
                $table->decimal('length', 8, 2)->nullable()->after('weight')
                      ->comment('Длина товара в см');
            }
            
            // Переименовываем depth в width, если depth существует, а width нет
            if (Schema::hasColumn('shop_good_variations', 'depth') && !Schema::hasColumn('shop_good_variations', 'width')) {
                $table->renameColumn('depth', 'width');
            }
            
            // Если есть и depth, и width, то удаляем depth
            if (Schema::hasColumn('shop_good_variations', 'depth') && Schema::hasColumn('shop_good_variations', 'width')) {
                $table->dropColumn('depth');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_good_variations', function (Blueprint $table) {
            // Удаляем колонку length, если она была добавлена
            if (Schema::hasColumn('shop_good_variations', 'length')) {
                $table->dropColumn('length');
            }
            
            // Возвращаем width обратно в depth, если это было сделано
            if (Schema::hasColumn('shop_good_variations', 'width') && !Schema::hasColumn('shop_good_variations', 'depth')) {
                $table->renameColumn('width', 'depth');
            }
        });
    }
};
