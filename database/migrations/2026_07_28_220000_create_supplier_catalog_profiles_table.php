<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalog_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->json('supplier_names');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::table('supplier_catalog_profiles')->insert([
            'code' => 'bikeproducts',
            'name' => 'Байкпродакс',
            'supplier_names' => json_encode(['Байкпродакс'], JSON_UNESCAPED_UNICODE),
            'settings' => json_encode([
                'excel_source' => true,
                'yml_url' => 'https://www.bikeproducts.ru/bitrix/catalog_export/productsyml.php',
            ], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_catalog_profiles');
    }
};
