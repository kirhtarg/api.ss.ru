<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_feed_price_stock_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('supplier_catalog_profiles')->cascadeOnDelete();
            $table->string('status', 24)->default('queued')->index();
            $table->string('trigger', 24)->default('scheduled');
            $table->unsignedInteger('offers_total')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('updated_prices')->default(0);
            $table->unsignedInteger('updated_stocks')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('not_found')->default(0);
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_feed_price_stock_runs');
    }
};
