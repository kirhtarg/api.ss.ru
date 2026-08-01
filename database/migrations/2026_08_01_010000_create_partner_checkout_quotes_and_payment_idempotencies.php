<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_checkout_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->char('request_hash', 64);
            $table->json('request_payload');
            $table->json('snapshot');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_by_partner_order_id')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'expires_at'], 'partner_quote_expiration_lookup');
        });

        Schema::create('partner_payment_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_order_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 24)->default('processing');
            $table->unsignedBigInteger('payment_transaction_id')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['partner_id', 'idempotency_key'], 'partner_payment_idempotency_unique');
            $table->index(['partner_order_id', 'status'], 'partner_payment_order_status_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payment_idempotencies');
        Schema::dropIfExists('partner_checkout_quotes');
    }
};
