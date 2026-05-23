<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_dellin_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_dellin_settings', 'auth_type')) {
                $table->string('auth_type', 20)->default('appkey')->after('appkey');
            }
            if (! Schema::hasColumn('shop_dellin_settings', 'pat')) {
                $table->string('pat')->nullable()->after('auth_type');
            }
            if (! Schema::hasColumn('shop_dellin_settings', 'login')) {
                $table->string('login')->nullable()->after('pat');
            }
            if (! Schema::hasColumn('shop_dellin_settings', 'password')) {
                $table->string('password')->nullable()->after('login');
            }
            if (! Schema::hasColumn('shop_dellin_settings', 'session_id')) {
                $table->string('session_id')->nullable()->after('password');
            }
            if (! Schema::hasColumn('shop_dellin_settings', 'session_expires_at')) {
                $table->timestamp('session_expires_at')->nullable()->after('session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_dellin_settings', function (Blueprint $table) {
            $columns = ['auth_type', 'pat', 'login', 'password', 'session_id', 'session_expires_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('shop_dellin_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
