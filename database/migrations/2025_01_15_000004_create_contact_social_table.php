<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contact_socials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_contact')->constrained('contacts')->onDelete('cascade');
            $table->foreignId('social_type')->constrained('social_types')->onDelete('cascade');
            $table->string('social_name');
            $table->string('social_url');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_social');
    }
};
