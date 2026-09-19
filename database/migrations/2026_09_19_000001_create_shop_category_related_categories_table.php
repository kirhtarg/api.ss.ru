<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('shop_category_related_categories', function(Blueprint $t){ $t->foreignId('category_id')->constrained('shop_categories')->cascadeOnDelete(); $t->foreignId('related_category_id')->constrained('shop_categories')->cascadeOnDelete(); $t->primary(['category_id','related_category_id']); }); }
 public function down(): void { Schema::dropIfExists('shop_category_related_categories'); }
};
