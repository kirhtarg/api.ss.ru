<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_name', 100)->default('goods');
            $table->string('name', 255);
            $table->longText('configuration');
            $table->timestamps();

            $table->index(['entity_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_templates');
    }
};
