<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('social_types', function (Blueprint $table) {
            $table->id();
            $table->string('social');
            $table->string('icon')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Сначала удаляем все внешние ключи, которые ссылаются на эту таблицу
        if (Schema::hasTable('contact_socials')) {
            // Удаляем внешний ключ social_type_id из contact_socials
            try {
                DB::statement('ALTER TABLE contact_socials DROP FOREIGN KEY contact_socials_social_type_foreign');
            } catch (\Exception $e) {
                // Пробуем альтернативные имена
                try {
                    DB::statement('ALTER TABLE contact_socials DROP FOREIGN KEY contact_social_social_type_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
            }
        }
        
        // Теперь безопасно удаляем таблицу
        Schema::dropIfExists('social_types');
    }
};
