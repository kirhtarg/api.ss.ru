<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('shop_promocode_popup_settings', function(Blueprint $t){ $t->id(); $t->string('title')->nullable(); $t->text('text')->nullable(); $t->unsignedInteger('delay_seconds')->default(90); $t->foreignId('promocode_id')->nullable()->constrained('promocodes')->nullOnDelete(); $t->boolean('is_active')->default(false); $t->timestamps(); }); }
 public function down(): void { Schema::dropIfExists('shop_promocode_popup_settings'); }
};
