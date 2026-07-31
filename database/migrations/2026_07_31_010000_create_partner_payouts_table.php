<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('number', 40)->unique();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('formed')->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('RUB');
            $table->unsignedInteger('entries_count');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'created_at']);
        });

        Schema::table('partner_commission_entries', function (Blueprint $table): void {
            $table->foreignId('partner_payout_id')->nullable()->after('partner_order_id')->constrained('partner_payouts')->nullOnDelete();
            $table->index(['partner_id', 'status', 'partner_payout_id'], 'partner_commission_payout_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('partner_commission_entries', function (Blueprint $table): void {
            $table->dropIndex('partner_commission_payout_lookup');
            $table->dropConstrainedForeignId('partner_payout_id');
        });
        Schema::dropIfExists('partner_payouts');
    }
};
