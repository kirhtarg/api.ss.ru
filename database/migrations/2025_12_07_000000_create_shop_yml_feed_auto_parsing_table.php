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
        Schema::create('shop_yml_feed_auto_parsing', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->text('yml_feed_url');
            $table->json('field_mapping')->nullable(); // Маппинг полей (объект с индексами столбцов)
            $table->json('parse_options')->nullable(); // Опции парсинга (категории, цены, производители)
            $table->json('settings')->nullable(); // Дополнительные настройки (sheetTabs и другие параметры)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_yml_feed_auto_parsing');
    }
};
