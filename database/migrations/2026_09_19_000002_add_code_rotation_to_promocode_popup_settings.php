<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('shop_promocode_popup_settings', function(Blueprint $t){ $t->string('promocode_code',40)->default('')->after('promocode_id'); $t->unsignedTinyInteger('discount_percent')->default(5)->after('promocode_code'); $t->boolean('rotation_enabled')->default(false)->after('discount_percent'); $t->unsignedSmallInteger('rotation_minutes')->default(60)->after('rotation_enabled'); }); }
 public function down(): void { Schema::table('shop_promocode_popup_settings', function(Blueprint $t){ $t->dropColumn(['promocode_code','discount_percent','rotation_enabled','rotation_minutes']); }); }
};
