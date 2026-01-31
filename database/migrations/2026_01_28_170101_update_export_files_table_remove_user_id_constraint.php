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
        // 1. Ensure created_by exists
        if (!Schema::hasColumn('export_files', 'created_by')) {
            Schema::table('export_files', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->after('id');
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            });
        }

        // 2. Handle data migration and cleanup of user_id
        if (Schema::hasColumn('export_files', 'user_id')) {
             // Copy data
             DB::statement('UPDATE export_files SET created_by = user_id WHERE created_by IS NULL');

             Schema::table('export_files', function (Blueprint $table) {
                 // Drop FK first
                 $fkName = 'export_files_user_id_foreign';
                 $fkExists = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'export_files' AND CONSTRAINT_NAME = '$fkName' AND TABLE_SCHEMA = '" . DB::getDatabaseName() . "'"))->count() > 0;
                 if ($fkExists) {
                     $table->dropForeign($fkName);
                 }

                // Drop index
                $indexExists = collect(DB::select("SHOW INDEXES FROM export_files WHERE Key_name = 'export_files_user_id_status_index'"))->count() > 0;
                if ($indexExists) {
                    $table->dropIndex('export_files_user_id_status_index');
                }
                
                // Drop column
                $table->dropColumn('user_id');
             });
        }

        // 3. Create index for created_by
        $newIndexExists = collect(DB::select("SHOW INDEXES FROM export_files WHERE Key_name = 'export_files_created_by_status_index'"))->count() > 0;

        if (!$newIndexExists) {
            Schema::table('export_files', function (Blueprint $table) {
                $table->index(['created_by', 'status'], 'export_files_created_by_status_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Добавляем обратно колонку user_id
        if (!Schema::hasColumn('export_files', 'user_id')) {
            Schema::table('export_files', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });
        }

        // Копируем данные обратно
        if (Schema::hasColumn('export_files', 'created_by')) {
            DB::statement('UPDATE export_files SET user_id = created_by');
        }

        Schema::table('export_files', function (Blueprint $table) {
            // Делаем user_id NOT NULL и добавляем foreign key
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            
            $fkName = 'export_files_user_id_foreign';
            $fkExists = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'export_files' AND CONSTRAINT_NAME = '$fkName' AND TABLE_SCHEMA = '" . DB::getDatabaseName() . "'"))->count() > 0;
            if (!$fkExists) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            }

            // Удаляем created_by
            if (Schema::hasColumn('export_files', 'created_by')) {
                $fkNameCreatedBy = 'export_files_created_by_foreign';
                $fkExistsCreatedBy = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'export_files' AND CONSTRAINT_NAME = '$fkNameCreatedBy' AND TABLE_SCHEMA = '" . DB::getDatabaseName() . "'"))->count() > 0;
                
                if ($fkExistsCreatedBy) {
                    $table->dropForeign($fkNameCreatedBy);
                }
                
                $newIndexExists = collect(DB::select("SHOW INDEXES FROM export_files WHERE Key_name = 'export_files_created_by_status_index'"))->count() > 0;
                if ($newIndexExists) {
                    $table->dropIndex('export_files_created_by_status_index');
                }

                $table->dropColumn('created_by');
            }

            // Создаем старый индекс
            $indexExists = collect(DB::select("SHOW INDEXES FROM export_files WHERE Key_name = 'export_files_user_id_status_index'"))->count() > 0;
            if (!$indexExists) {
                $table->index(['user_id', 'status'], 'export_files_user_id_status_index');
            }
        });
    }
};
