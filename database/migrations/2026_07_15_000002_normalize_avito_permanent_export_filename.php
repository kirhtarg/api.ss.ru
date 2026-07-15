<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('export_files')
            ->where('filename', 'avito.xml')
            ->update(['original_filename' => 'avito.xml']);
    }

    public function down(): void
    {
        // Историческое отображаемое имя не влияет на содержимое или URL фида.
    }
};
