<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalog_action_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('supplier_catalog_snapshots')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('supplier_code', 100)->index();
            $table->string('scope', 40);
            $table->string('action', 60);
            $table->string('status', 20)->default('running')->index();
            $table->json('selection');
            $table->json('backup')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_catalog_action_runs');
    }
};
