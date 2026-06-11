<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_russian_post_settings') || Schema::hasColumn('shop_russian_post_settings', 'sender_postoffice_code')) {
            return;
        }

        Schema::table('shop_russian_post_settings', function (Blueprint $table) {
            $table->string('sender_postoffice_code')
                ->nullable()
                ->after('sender_postal_code')
                ->comment('Индекс ОПС/отделения отправки Почты России');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shop_russian_post_settings') || ! Schema::hasColumn('shop_russian_post_settings', 'sender_postoffice_code')) {
            return;
        }

        Schema::table('shop_russian_post_settings', function (Blueprint $table) {
            $table->dropColumn('sender_postoffice_code');
        });
    }
};
