<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            // Сначала добавляем текстовое поле supplier
            $table->string('supplier')->nullable()->after('label_id');
        });

        // Переносим данные из supplier_id в supplier (название поставщика)
        // Получаем названия поставщиков из связанной таблицы
        DB::statement('
            UPDATE shop_goods 
            SET supplier = (
                SELECT name 
                FROM shop_suppliers 
                WHERE shop_suppliers.id = shop_goods.supplier_id
            )
            WHERE supplier_id IS NOT NULL
        ');

        Schema::table('shop_goods', function (Blueprint $table) {
            // Добавляем индекс для supplier
            $table->index('supplier');

            // Удаляем внешний ключ и индекс supplier_id
            $table->dropForeign(['supplier_id']);
            $table->dropIndex(['supplier_id']);

            // Удаляем колонку supplier_id
            $table->dropColumn('supplier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            // Удаляем текстовое поле supplier
            $table->dropIndex(['supplier']);
            $table->dropColumn('supplier');

            // Восстанавливаем supplier_id
            $table->foreignId('supplier_id')->nullable()->after('label_id')->constrained('shop_suppliers')->onDelete('set null');
            $table->index('supplier_id');

            // Восстанавливаем данные из supplier обратно в supplier_id (по имени)
            DB::statement('
                UPDATE shop_goods 
                SET supplier_id = (
                    SELECT id 
                    FROM shop_suppliers 
                    WHERE shop_suppliers.name = shop_goods.supplier
                    LIMIT 1
                )
                WHERE supplier IS NOT NULL
            ');
        });
    }
};
