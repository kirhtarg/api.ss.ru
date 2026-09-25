<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->longText('formatted_description_html')->nullable()->after('description');
            $table->string('description_format_hash', 64)->nullable()->index()->after('formatted_description_html');
            $table->unsignedInteger('description_format_version')->default(1)->after('description_format_hash');
            $table->string('description_format_status', 24)->default('pending')->index()->after('description_format_version');
            $table->boolean('description_format_manual')->default(false)->after('description_format_status');
            $table->timestamp('description_formatted_at')->nullable()->after('description_format_manual');
        });
    }

    public function down(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->dropColumn([
                'formatted_description_html',
                'description_format_hash',
                'description_format_version',
                'description_format_status',
                'description_format_manual',
                'description_formatted_at',
            ]);
        });
    }
};
