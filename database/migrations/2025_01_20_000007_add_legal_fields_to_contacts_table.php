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
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->comment('Наименование юридического лица');
            $table->string('inn')->nullable()->comment('ИНН');
            $table->string('ogrnip')->nullable()->comment('ОГРНИП');
            $table->text('legal_address')->nullable()->comment('Юридический адрес');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'inn', 'ogrnip', 'legal_address']);
        });
    }
};
