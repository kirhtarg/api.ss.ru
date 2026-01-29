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
        Schema::table('export_files', function (Blueprint $table) {
            // Удаляем индекс, если он существует
            try {
                $table->dropIndex(['user_id', 'status']);
            } catch (\Exception $e) {
                // Индекс может уже быть удален
            }

            // Удаляем колонку user_id, если она существует
            if (Schema::hasColumn('export_files', 'user_id')) {
                $table->dropColumn('user_id');
            }

            // Создаем новый индекс для created_by, если он еще не существует
            try {
                $table->index(['created_by', 'status'], 'export_files_created_by_status_index');
            } catch (\Exception $e) {
                // Индекс может уже существовать
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_files', function (Blueprint $table) {
            // Добавляем обратно колонку user_id
            $table->unsignedBigInteger('user_id')->nullable()->after('id');

            // Копируем данные обратно
            DB::statement('UPDATE export_files SET user_id = created_by');

            // Делаем user_id NOT NULL и добавляем foreign key
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Удаляем created_by
            $table->dropForeign(['created_by']);
            $table->dropIndex('export_files_created_by_status_index');
            $table->dropColumn('created_by');

            // Создаем старый индекс
            $table->index(['user_id', 'status']);
        });
    }
};
