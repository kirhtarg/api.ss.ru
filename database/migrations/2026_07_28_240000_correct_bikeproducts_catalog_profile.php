<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('supplier_catalog_profiles')
            ->where('code', 'bikeproducts')
            ->update([
                'name' => 'Байкпродакс',
                'supplier_names' => json_encode(['Байкпродакс'], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Не восстанавливаем ошибочное имя поставщика.
    }
};
