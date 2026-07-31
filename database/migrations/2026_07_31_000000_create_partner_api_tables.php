<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->decimal('commission_rate', 7, 4)->default(0);
            $table->string('commission_status', 24)->default('after_completed');
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_api_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('key_id', 80)->unique();
            $table->text('secret');
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'revoked_at']);
        });

        Schema::create('partner_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('shop_order_id')->nullable()->constrained('shop_orders')->nullOnDelete();
            $table->string('external_order_id', 128);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 40)->default('created')->index();
            $table->char('currency', 3)->default('RUB');
            $table->decimal('items_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('delivery_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('commission_rate', 7, 4)->default(0);
            $table->decimal('commission_base', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->string('commission_status', 32)->default('pending')->index();
            $table->json('customer_reference')->nullable();
            $table->json('attribution')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['partner_id', 'external_order_id']);
            $table->unique(['partner_id', 'idempotency_key']);
        });

        Schema::create('partner_commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('status', 24)->default('pending')->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('RUB');
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recognized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_id', 80)->unique();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'created_at']);
        });

        Schema::create('partner_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 80);
            $table->json('payload');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_deliveries');
        Schema::dropIfExists('partner_api_request_logs');
        Schema::dropIfExists('partner_commission_entries');
        Schema::dropIfExists('partner_orders');
        Schema::dropIfExists('partner_api_credentials');
        Schema::dropIfExists('partners');
    }
};
