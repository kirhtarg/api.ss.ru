<?php

namespace Tests\Feature\PartnerApi;

use App\Models\Partner;
use App\Models\PartnerApiCredential;
use App\Models\ShopDeliveryMethod;
use App\Services\OrderCalculationService;
use App\Services\Partner\PartnerDeliveryService;
use App\Services\Partner\PartnerWebhookService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerOrderHttpTest extends TestCase
{
    private PartnerApiCredential $credential;
    private string $secret = 'partner-order-http-secret';
    private int $goodId;
    private int $deliveryMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        Cache::flush();

        $partner = Partner::create([
            'public_id' => (string) Str::uuid(), 'code' => 'order-http', 'name' => 'Order HTTP Partner',
            'is_active' => true, 'commission_rate' => 0.1,
        ]);
        $this->credential = $partner->credentials()->create([
            'key_id' => 'pk_order_http', 'secret' => $this->secret, 'scopes' => ['orders:write', 'orders:read'],
        ]);
        $this->goodId = DB::table('shop_goods')->insertGetId([
            'name' => 'Snowboard', 'sku' => 'SNOW-HTTP', 'price' => 1000, 'stock_quantity' => 2,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shop_stocks')->insert([
            'good_id' => $this->goodId, 'warehouse_id' => 1, 'quantity' => 2, 'reserved_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $deliveryMethod = ShopDeliveryMethod::create([
            'name' => 'Courier', 'type' => 'courier', 'is_active' => true, 'cost' => 300, 'is_default' => true,
        ]);
        $this->deliveryMethodId = $deliveryMethod->id;
        $this->mock(OrderCalculationService::class, function ($mock): void {
            $mock->shouldReceive('calculateFinalUnitPrice')->andReturn([
                'base_price' => 1000, 'sale_price' => 1000, 'final_price' => 1000, 'discounts' => [],
            ]);
        });
        $this->mock(PartnerDeliveryService::class, function ($mock) use ($deliveryMethod): void {
            $mock->shouldReceive('calculate')->andReturn([
                'method' => $deliveryMethod, 'amount' => 300.0, 'metadata' => ['type' => 'courier'],
            ]);
        });
        $this->mock(PartnerWebhookService::class, function ($mock): void {
            $mock->shouldReceive('queueOrderEvent')->andReturnNull();
        });
    }

    protected function tearDown(): void
    {
        foreach (['partner_api_request_logs', 'partner_commission_entries', 'partner_orders', 'shop_stock_reservations', 'shop_stocks', 'shop_orders', 'shop_delivery_methods', 'shop_goods', 'partner_api_credentials', 'partners'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_complete_partner_order_is_created_through_http_endpoint(): void
    {
        $response = $this->postSignedOrder($this->payload('EXT-HTTP-1'), 'idem-http-1', 'nonce-http-1');

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.external_order_id', 'EXT-HTTP-1')
            ->assertJsonPath('data.items_amount', 1000)
            ->assertJsonPath('data.delivery_amount', 300)
            ->assertJsonPath('data.total_amount', 1300)
            ->assertJsonPath('data.commission.amount', 100)
            ->assertJsonPath('meta.idempotent_replay', false);
        $this->assertDatabaseHas('partner_orders', ['external_order_id' => 'EXT-HTTP-1', 'status' => 'created']);
        $this->assertDatabaseHas('partner_commission_entries', ['status' => 'pending', 'amount' => 100]);
        $this->assertDatabaseHas('shop_stocks', ['good_id' => $this->goodId, 'reserved_quantity' => 1]);
        $this->assertDatabaseCount('shop_stock_reservations', 1);
    }

    public function test_identical_request_with_same_idempotency_key_returns_existing_order(): void
    {
        $payload = $this->payload('EXT-HTTP-REPLAY');
        $first = $this->postSignedOrder($payload, 'idem-http-replay', 'nonce-http-replay-1')->assertCreated();
        $second = $this->postSignedOrder($payload, 'idem-http-replay', 'nonce-http-replay-2')->assertOk();

        $second->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('meta.idempotent_replay', true);
        $this->assertDatabaseCount('partner_orders', 1);
        $this->assertDatabaseCount('shop_stock_reservations', 1);
    }

    public function test_same_idempotency_key_with_different_body_returns_conflict(): void
    {
        $this->postSignedOrder($this->payload('EXT-HTTP-CONFLICT'), 'idem-http-conflict', 'nonce-http-conflict-1')->assertCreated();
        $changed = $this->payload('EXT-HTTP-CONFLICT');
        $changed['comment'] = 'changed request body';

        $this->postSignedOrder($changed, 'idem-http-conflict', 'nonce-http-conflict-2')
            ->assertConflict()
            ->assertJsonPath('message', 'Idempotency key was already used with another request');
        $this->assertDatabaseCount('partner_orders', 1);
    }

    public function test_external_order_id_cannot_be_reused_for_same_partner(): void
    {
        $payload = $this->payload('EXT-HTTP-UNIQUE');
        $this->postSignedOrder($payload, 'idem-http-unique-1', 'nonce-http-unique-1')->assertCreated();

        $this->postSignedOrder($payload, 'idem-http-unique-2', 'nonce-http-unique-2')
            ->assertConflict()
            ->assertJsonPath('message', 'External order ID already exists');
        $this->assertDatabaseCount('partner_orders', 1);
    }

    private function postSignedOrder(array $payload, string $idempotencyKey, string $nonce)
    {
        $path = '/api/partner/v1/orders';
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->postJson($path, $payload, array_merge($this->signedHeaders('POST', $path, $body, $nonce), [
            'Idempotency-Key' => $idempotencyKey,
        ]));
    }

    private function signedHeaders(string $method, string $path, string $body, string $nonce): array
    {
        $timestamp = (string) now()->timestamp;
        $canonical = implode("\n", [$method, $path, '', hash('sha256', $body), $timestamp, $nonce]);

        return [
            'X-Partner-Key' => $this->credential->key_id,
            'X-Partner-Timestamp' => $timestamp,
            'X-Partner-Nonce' => $nonce,
            'X-Partner-Signature' => hash_hmac('sha256', $canonical, $this->secret),
        ];
    }

    private function payload(string $externalId): array
    {
        return [
            'external_order_id' => $externalId,
            'items' => [['good_id' => $this->goodId, 'quantity' => 1]],
            'customer' => ['name' => 'HTTP Customer', 'email' => 'customer@example.test'],
            'delivery' => ['method_id' => $this->deliveryMethodId, 'address' => 'Moscow'],
        ];
    }

    private function createTables(): void
    {
        Schema::create('partners', function (Blueprint $table): void { $table->id(); $table->uuid('public_id')->unique(); $table->string('code')->unique(); $table->string('name'); $table->boolean('is_active'); $table->decimal('commission_rate', 7, 4); $table->string('commission_status')->default('after_completed'); $table->text('webhook_url')->nullable(); $table->text('webhook_secret')->nullable(); $table->json('allowed_ips')->nullable(); $table->json('metadata')->nullable(); $table->timestamps(); });
        Schema::create('partner_api_credentials', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id'); $table->string('key_id')->unique(); $table->text('secret'); $table->json('scopes'); $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamp('revoked_at')->nullable(); $table->timestamps(); });
        Schema::create('partner_api_request_logs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id')->nullable(); $table->string('request_id')->unique(); $table->string('method'); $table->string('path'); $table->unsignedSmallInteger('response_status')->nullable(); $table->unsignedInteger('duration_ms')->nullable(); $table->string('ip_address')->nullable(); $table->char('request_hash', 64)->nullable(); $table->string('error_code')->nullable(); $table->timestamps(); });
        Schema::create('shop_goods', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('sku'); $table->decimal('price', 12, 2); $table->decimal('sale_price', 12, 2)->nullable(); $table->integer('stock_quantity'); $table->boolean('is_active'); $table->timestamps(); });
        Schema::create('shop_delivery_methods', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('type'); $table->boolean('is_active'); $table->decimal('cost', 12, 2); $table->decimal('free_from', 12, 2)->nullable(); $table->text('description')->nullable(); $table->json('settings')->nullable(); $table->integer('sort_order')->default(0); $table->boolean('is_default')->default(false); $table->timestamps(); });
        Schema::create('shop_orders', function (Blueprint $table): void { $table->id(); $table->string('order_number')->unique(); $table->unsignedBigInteger('status_id')->nullable(); $table->string('customer_name'); $table->string('customer_email')->nullable(); $table->string('customer_phone')->nullable(); $table->json('items'); $table->decimal('subtotal', 12, 2); $table->decimal('delivery_cost', 12, 2); $table->decimal('total_amount', 12, 2); $table->integer('total_quantity'); $table->string('payment_method')->nullable(); $table->unsignedBigInteger('payment_method_id')->nullable(); $table->text('payment_url')->nullable(); $table->string('shipping_method')->nullable(); $table->unsignedBigInteger('shipping_method_id')->nullable(); $table->text('shipping_address')->nullable(); $table->text('notes')->nullable(); $table->string('ip_address')->nullable(); $table->text('user_agent')->nullable(); $table->json('metadata')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); });
        Schema::create('shop_stocks', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable(); $table->unsignedBigInteger('warehouse_id'); $table->integer('quantity'); $table->integer('reserved_quantity')->default(0); $table->integer('min_quantity')->default(0); $table->timestamps(); });
        Schema::create('shop_stock_reservations', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable(); $table->unsignedBigInteger('warehouse_id'); $table->integer('quantity'); $table->timestamp('reserved_until'); $table->string('reservation_type'); $table->string('reference_id'); $table->unsignedBigInteger('user_id')->nullable(); $table->text('notes')->nullable(); $table->timestamps(); });
        Schema::create('partner_orders', function (Blueprint $table): void { $table->id(); $table->uuid('public_id'); $table->unsignedBigInteger('partner_id'); $table->unsignedBigInteger('shop_order_id'); $table->string('external_order_id'); $table->string('idempotency_key'); $table->char('request_hash', 64); $table->string('status'); $table->char('currency', 3)->default('RUB'); $table->decimal('items_amount', 12, 2); $table->decimal('discount_amount', 12, 2)->default(0); $table->decimal('delivery_amount', 12, 2); $table->decimal('total_amount', 12, 2); $table->decimal('commission_rate', 7, 4); $table->decimal('commission_base', 12, 2); $table->decimal('commission_amount', 12, 2); $table->string('commission_status'); $table->json('customer_reference')->nullable(); $table->json('attribution')->nullable(); $table->json('metadata')->nullable(); $table->timestamps(); $table->unique(['partner_id', 'external_order_id']); $table->unique(['partner_id', 'idempotency_key']); });
        Schema::create('partner_commission_entries', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id'); $table->unsignedBigInteger('partner_order_id'); $table->unsignedBigInteger('partner_payout_id')->nullable(); $table->string('type'); $table->string('status'); $table->decimal('amount', 12, 2); $table->char('currency', 3); $table->string('reason')->nullable(); $table->json('metadata')->nullable(); $table->timestamp('recognized_at')->nullable(); $table->timestamp('paid_at')->nullable(); $table->timestamps(); });
    }
}
