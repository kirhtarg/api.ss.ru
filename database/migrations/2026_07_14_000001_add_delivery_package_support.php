<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_goods', function (Blueprint $table) {
            $table->decimal('shipping_weight', 10, 3)->nullable()->after('weight');
            $table->decimal('shipping_length', 10, 2)->nullable()->after('shipping_weight');
            $table->decimal('shipping_width', 10, 2)->nullable()->after('shipping_length');
            $table->decimal('shipping_height', 10, 2)->nullable()->after('shipping_width');
            $table->boolean('ships_separately')->default(false)->after('shipping_height');
        });

        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->decimal('shipping_weight', 10, 3)->nullable()->after('weight');
            $table->decimal('shipping_length', 10, 2)->nullable()->after('shipping_weight');
            $table->decimal('shipping_width', 10, 2)->nullable()->after('shipping_length');
            $table->decimal('shipping_height', 10, 2)->nullable()->after('shipping_width');
            $table->boolean('ships_separately')->nullable()->after('shipping_height');
        });

        Schema::create('shop_order_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->unsignedInteger('number')->default(1);
            $table->decimal('weight', 10, 3);
            $table->decimal('length', 10, 2);
            $table->decimal('width', 10, 2);
            $table->decimal('height', 10, 2);
            $table->string('source', 20)->default('estimated');
            $table->timestamp('confirmed_at')->nullable();
            $table->json('items')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_packages');

        Schema::table('shop_good_variations', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_weight', 'shipping_length', 'shipping_width',
                'shipping_height', 'ships_separately',
            ]);
        });

        Schema::table('shop_goods', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_weight', 'shipping_length', 'shipping_width',
                'shipping_height', 'ships_separately',
            ]);
        });
    }
};
