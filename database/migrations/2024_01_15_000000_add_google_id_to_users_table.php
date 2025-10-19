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
        Schema::table('users', function (Blueprint $table) {
            // Добавляем google_id только если колонка не существует
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique()->after('email');
            }
            
            // Добавляем avatar_url только если колонка не существует
            if (!Schema::hasColumn('users', 'avatar_url')) {
                if (Schema::hasColumn('users', 'avatar')) {
                    $table->string('avatar_url')->nullable()->after('avatar');
                } else {
                    $table->string('avatar_url')->nullable();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'avatar_url']);
        });
    }
};
