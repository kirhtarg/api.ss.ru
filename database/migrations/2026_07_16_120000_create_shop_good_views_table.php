<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shop_good_views')) {
            return;
        }

        Schema::create('shop_good_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('good_id');
            $table->timestamp('viewed_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'good_id']);
            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_good_views');
    }
};
