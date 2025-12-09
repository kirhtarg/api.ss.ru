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
        if (Schema::hasTable('shop_dpd_settings')) {
            return;
        }

        Schema::create('shop_dpd_settings', function (Blueprint $table) {
            $table->id();
            // Учетные данные DPD
            $table->string('client_number')->comment('Номер клиента DPD');
            $table->string('api_key')->comment('API ключ DPD');

            // Адрес отправителя
            $table->string('sender_company')->nullable()->comment('Компания отправителя');
            $table->string('sender_name')->nullable()->comment('Контактное лицо');
            $table->string('sender_phone')->nullable()->comment('Телефон отправителя');
            $table->string('sender_email')->nullable()->comment('Email отправителя');
            $table->string('sender_inn')->nullable()->comment('ИНН отправителя');
            $table->string('sender_kpp')->nullable()->comment('КПП отправителя');
            $table->string('sender_city')->nullable()->comment('Город отправителя');
            $table->string('sender_street')->nullable()->comment('Улица отправителя');
            $table->string('sender_house')->nullable()->comment('Дом отправителя');
            $table->string('sender_flat')->nullable()->comment('Квартира/офис отправителя');
            $table->string('sender_postal_code')->nullable()->comment('Почтовый индекс');

            // Настройки по умолчанию для расчета
            $table->decimal('default_weight', 8, 2)->default(0.5)->comment('Вес по умолчанию (кг)');
            $table->decimal('default_length', 8, 2)->default(10)->comment('Длина по умолчанию (см)');
            $table->decimal('default_width', 8, 2)->default(10)->comment('Ширина по умолчанию (см)');
            $table->decimal('default_height', 8, 2)->default(10)->comment('Высота по умолчанию (см)');

            $table->boolean('is_active')->default(false)->comment('Активна ли настройка');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_dpd_settings');
    }
};















