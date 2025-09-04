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
        // Проверяем, что таблица admin_page_role не существует
        if (!Schema::hasTable('admin_page_role')) {
            Schema::create('admin_page_role', function (Blueprint $table) {
                $table->id();
                $table->foreignId('admin_page_id')->constrained('admin_pages')->onDelete('cascade');
                $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['admin_page_id', 'role_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_page_role');
    }
};
