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
        Schema::table('contact_addresses', function (Blueprint $table) {
            $table->text('work_mode')->nullable()->after('howtogo')->comment('Режим работы в HTML формате');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_addresses', function (Blueprint $table) {
            $table->dropColumn('work_mode');
        });
    }
};
