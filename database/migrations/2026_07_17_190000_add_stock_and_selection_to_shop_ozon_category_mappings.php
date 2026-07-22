<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->string('warehouse_id')->nullable()->after('account_id');
            $table->foreignId('selection_tag_id')->nullable()->after('warehouse_id')->constrained('shop_tags')->nullOnDelete();
        });

        // Preserve the current account-wide behaviour for profiles created before this change.
        DB::table('shop_ozon_category_mappings as mappings')
            ->join('shop_ozon_accounts as accounts', 'accounts.id', '=', 'mappings.account_id')
            ->orderBy('mappings.id')
            ->select(['mappings.id', 'accounts.warehouse_id', 'accounts.selection_tag_id'])
            ->get()
            ->each(fn ($row) => DB::table('shop_ozon_category_mappings')->where('id', $row->id)->update([
                'warehouse_id' => $row->warehouse_id,
                'selection_tag_id' => $row->selection_tag_id,
            ]));
    }

    public function down(): void
    {
        Schema::table('shop_ozon_category_mappings', function (Blueprint $table) {
            $table->dropForeign(['selection_tag_id']);
            $table->dropColumn(['selection_tag_id', 'warehouse_id']);
        });
    }
};
