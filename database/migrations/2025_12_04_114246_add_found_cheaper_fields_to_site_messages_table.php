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
        Schema::table('site_messages', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
            $table->text('good_link')->nullable()->after('email');
            $table->decimal('good_price', 10, 2)->nullable()->after('good_link');
            $table->enum('type', ['callback', 'message', 'found_cheaper'])->default('callback')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_messages', function (Blueprint $table) {
            $table->dropColumn(['email', 'good_link', 'good_price']);
            $table->enum('type', ['callback', 'message'])->default('callback')->change();
        });
    }
};
