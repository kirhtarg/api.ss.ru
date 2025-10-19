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
        // Эта миграция дублирует предыдущую, поэтому ничего не делаем
        // Изменения уже применены в миграции 2025_09_03_162400_modify_site_templates_table.php
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Эта миграция дублирует предыдущую, поэтому ничего не делаем
        // Откат уже выполнен в миграции 2025_09_03_162400_modify_site_templates_table.php
    }
};
