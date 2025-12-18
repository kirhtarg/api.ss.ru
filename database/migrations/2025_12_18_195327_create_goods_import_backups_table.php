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
        Schema::create('goods_import_backups', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название резервной копии
            $table->string('filename'); // Имя файла с данными
            $table->unsignedBigInteger('shop_id')->nullable(); // ID магазина (если есть мультишоп)
            $table->unsignedBigInteger('user_id'); // Кто создал копию
            $table->unsignedBigInteger('size')->default(0); // Размер файла в байтах
            $table->unsignedInteger('records_count')->default(0); // Количество записей
            $table->json('tables_backed_up')->nullable(); // Какие таблицы были сохранены
            $table->string('status')->default('completed'); // completed, failed, restoring
            $table->text('error_message')->nullable(); // Сообщение об ошибке
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_import_backups');
    }
};
