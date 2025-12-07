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
        Schema::create('shop_google_sheets_auto_parsing', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->text('google_sheet_url');
            $table->json('field_mapping')->nullable(); // Маппинг полей (объект с индексами столбцов)
            $table->integer('skip_rows')->default(0); // Количество пропускаемых строк
            $table->integer('sheet_number')->default(1); // Номер листа
            $table->json('settings')->nullable(); // Дополнительные настройки (sheetTabs и другие параметры)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_google_sheets_auto_parsing');
    }
};
