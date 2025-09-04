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
        // Таблица site_menus уже создана в предыдущей миграции
        // Эта миграция больше не нужна, так как таблица уже существует
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Таблица site_menus не создается в этой миграции
        // Поэтому ничего не удаляем
    }
};
