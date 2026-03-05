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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name')->nullable();
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
            // Удаляем внешний ключ contact_id из contact_socials
            try {
                DB::statement('ALTER TABLE contact_socials DROP FOREIGN KEY contact_socials_id_contact_foreign');
            } catch (\Exception $e) {
                // Пробуем альтернативные имена
                try {
                    DB::statement('ALTER TABLE contact_socials DROP FOREIGN KEY contact_social_contact_id_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку, если внешний ключ не существует
                }
            }
        }

        if (Schema::hasTable('contact_phones')) {
            // Удаляем внешний ключ contact_id из contact_phones
            try {
                DB::statement('ALTER TABLE contact_phones DROP FOREIGN KEY contact_phones_contact_id_foreign');
            } catch (\Exception $e) {
                try {
                    DB::statement('ALTER TABLE contact_phones DROP FOREIGN KEY contact_phone_contact_id_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку
                }
            }
        }

        if (Schema::hasTable('contact_addresses')) {
            // Удаляем внешний ключ contact_id из contact_addresses
            try {
                DB::statement('ALTER TABLE contact_addresses DROP FOREIGN KEY contact_addresses_contact_id_foreign');
            } catch (\Exception $e) {
                try {
                    DB::statement('ALTER TABLE contact_addresses DROP FOREIGN KEY contact_address_contact_id_foreign');
                } catch (\Exception $e2) {
                    // Игнорируем ошибку
                }
            }
        }

        // Теперь безопасно удаляем таблицу
        Schema::dropIfExists('contacts');
    }
};
