<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_yandex_market_attribute_templates', function (Blueprint $table) {
            $table->string('source_signature', 100)->nullable()->after('market_parameter_name');
            $table->dropUnique('yandex_market_attribute_template_unique');
            $table->unique(['account_id', 'market_parameter_id', 'source_signature'], 'yandex_market_attribute_template_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shop_yandex_market_attribute_templates', function (Blueprint $table) {
            $table->dropUnique('yandex_market_attribute_template_source_unique');
            $table->dropColumn('source_signature');
            $table->unique(['account_id', 'market_parameter_id'], 'yandex_market_attribute_template_unique');
        });
    }
};
