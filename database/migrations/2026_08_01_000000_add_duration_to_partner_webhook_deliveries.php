<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_webhook_deliveries', function (Blueprint $table): void {
            $table->unsignedInteger('duration_ms')->nullable()->after('response_status');
        });
    }

    public function down(): void
    {
        Schema::table('partner_webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
    }
};
