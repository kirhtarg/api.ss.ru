<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('shop_russian_post_settings', function(Blueprint $t){ $t->decimal('max_weight_kg',8,2)->nullable()->after('default_weight'); }); }
 public function down(): void { Schema::table('shop_russian_post_settings', function(Blueprint $t){ $t->dropColumn('max_weight_kg'); }); }
};
