<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shop_goods_action_logs');
    }

    public function down(): void
    {
        // Журнал удален намеренно и не восстанавливается автоматически.
    }
};
