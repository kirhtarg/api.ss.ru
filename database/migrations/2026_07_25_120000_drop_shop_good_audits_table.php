<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shop_good_audits');
        Schema::dropIfExists('shop_good_audit');
    }

    public function down(): void
    {
        // История аудита намеренно не восстанавливается: данные удалены вместе с таблицей.
    }
};
