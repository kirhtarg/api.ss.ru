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
        Schema::create('export_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable(); // Кто создал файл (опционально)
            $table->string('filename'); // Внутреннее имя файла
            $table->string('original_filename'); // Оригинальное имя файла для пользователя
            $table->string('file_path')->nullable(); // Путь к файлу на диске
            $table->enum('format', ['excel', 'csv', 'txt']);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedBigInteger('file_size')->default(0); // Размер в байтах
            $table->text('error_message')->nullable();
            $table->json('export_config')->nullable(); // Конфигурация экспорта (фильтры, поля и т.д.)
            $table->timestamps();

            $table->index(['created_by', 'status']);
            $table->index('created_at');
            $table->index('status');

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_files');
    }
};
