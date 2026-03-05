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
            $table->string('name')->nullable()->after('id_contact');
            $table->boolean('is_delivery')->default(true)->after('work_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_addresses', function (Blueprint $table) {
            $table->dropColumn(['name', 'is_delivery']);
        });
    }
};
