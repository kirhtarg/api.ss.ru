<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'shop_yandex_market_attribute_templates';
        if (! Schema::hasColumn($tableName, 'source_signature')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->string('source_signature', 100)->nullable()->after('market_parameter_name'));
        }

        if (! $this->hasIndex($tableName, 'yandex_market_attribute_template_account_index')) {
            // MySQL may use the old unique index to support account_id FK.
            Schema::table($tableName, fn (Blueprint $table) => $table->index('account_id', 'yandex_market_attribute_template_account_index'));
        }
        if ($this->hasIndex($tableName, 'yandex_market_attribute_template_unique')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique('yandex_market_attribute_template_unique'));
        }
        if (! $this->hasIndex($tableName, 'yandex_market_attribute_template_source_unique')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique(['account_id', 'market_parameter_id', 'source_signature'], 'yandex_market_attribute_template_source_unique'));
        }
    }

    public function down(): void
    {
        $tableName = 'shop_yandex_market_attribute_templates';
        if ($this->hasIndex($tableName, 'yandex_market_attribute_template_source_unique')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique('yandex_market_attribute_template_source_unique'));
        }
        if (! $this->hasIndex($tableName, 'yandex_market_attribute_template_unique')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique(['account_id', 'market_parameter_id'], 'yandex_market_attribute_template_unique'));
        }
        if ($this->hasIndex($tableName, 'yandex_market_attribute_template_account_index')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex('yandex_market_attribute_template_account_index'));
        }
        if (Schema::hasColumn($tableName, 'source_signature')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('source_signature'));
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
