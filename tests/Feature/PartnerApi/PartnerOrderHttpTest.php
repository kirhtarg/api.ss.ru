<?php

namespace Tests\Feature\PartnerApi;

use App\Models\Partner;
use App\Models\PartnerApiCredential;
use App\Models\ShopDeliveryMethod;
use App\Services\OrderCalculationService;
use App\Services\Partner\PartnerDeliveryService;
use App\Services\Partner\PartnerWebhookService;
use App\Services\Partner\PartnerTbankGateway;
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
            'key_id' => 'pk_order_http', 'secret' => $this->secret, 'scopes' => ['orders:write', 'orders:read', 'checkout:read', 'commissions:read', 'payments:write'],
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
            $mock->shouldReceive('calculateFinalUnitPrice')->andReturnUsing(fn ($good) => [
                'base_price' => (float) $good->price, 'sale_price' => (float) $good->price,
                'final_price' => (float) $good->price, 'discounts' => [],
            ]);
        });
        $this->mock(PartnerDeliveryService::class, function ($mock) use ($deliveryMethod): void {
            $mock->shouldReceive('calculate')->andReturn([
                'method' => $deliveryMethod, 'amount' => 300.0, 'metadata' => ['type' => 'courier'],
            ]);
        });
        $this->mock(PartnerWebhookService::class, function ($mock): void {
            $mock->shouldReceive('queueOrderEvent')->andReturnNull();
            $mock->shouldReceive('queueCommissionEvent')->andReturnNull();
        });
    }

    protected function tearDown(): void
    {
        foreach (['partner_api_request_logs', 'partner_payment_idempotencies', 'partner_checkout_quotes', 'partner_commission_entries', 'partner_orders', 'shop_payment_transactions', 'shop_payment_methods', 'shop_stock_reservations', 'shop_stocks', 'shop_orders', 'shop_delivery_statuses', 'shop_payment_statuses', 'shop_order_statuses', 'shop_delivery_methods', 'shop_goods', 'partner_api_credentials', 'partners'] as $table) {
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

    public function test_checkout_quote_does_not_create_order_reservation_or_payment(): void
    {
        $path = '/api/partner/v1/checkout/quote';
        $payload = [
            'items' => [['good_id' => $this->goodId, 'quantity' => 1]],
            'delivery' => ['method_id' => $this->deliveryMethodId],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postJson($path, $payload, $this->signedHeaders('POST', $path, $body, 'nonce-quote'))
            ->assertOk()
            ->assertJsonPath('data.subtotal', 1000)
            ->assertJsonPath('data.delivery_amount', 300)
            ->assertJsonPath('data.total', 1300)
            ->assertJsonPath('data.items.0.available_quantity', 2);

        $this->assertDatabaseCount('partner_orders', 0);
        $this->assertDatabaseCount('shop_orders', 0);
        $this->assertDatabaseCount('shop_stock_reservations', 0);
        $this->assertDatabaseHas('shop_stocks', ['good_id' => $this->goodId, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('partner_checkout_quotes', 1);
    }

    public function test_quote_applies_verified_customer_context_and_returns_promotion_breakdown(): void
    {
        $path = '/api/partner/v1/checkout/quote';
        $payload = ['items' => [['good_id' => $this->goodId, 'quantity' => 1]], 'delivery' => ['method_id' => $this->deliveryMethodId],
            'promotion' => ['customer_reference' => ['external_id' => 'sr-user-10', 'registration_status' => 'registered', 'birthday_status' => 'not_today'], 'registration_discount_percent' => 7, 'partner_bonus_spend' => 50]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postJson($path, $payload, $this->signedHeaders('POST', $path, $body, 'nonce-promotion'))
            ->assertOk()->assertJsonPath('data.subtotal_before_discounts', 1000)->assertJsonPath('data.subtotal', 930)
            ->assertJsonPath('data.discount_amount', 70)->assertJsonPath('data.total', 1230)
            ->assertJsonPath('data.promotion.decisions.0.code', 'registered_customer_discount')
            ->assertJsonPath('data.promotion.bonus.spend_applied', 0)
            ->assertJsonPath('data.promotion.bonus.spend_reason', 'verified_partner_bonus_account_required');
    }

    public function test_order_rejects_promotion_without_authoritative_quote(): void
    {
        $payload = $this->payload('EXT-PROMOTION-WITHOUT-QUOTE');
        $payload['promotion'] = ['promo_code' => 'TEST'];
        $this->postSignedOrder($payload, 'idem-promotion-no-quote', 'promotion-no-quote')
            ->assertUnprocessable()->assertJsonPath('error.code', 'promotion_quote_required');
        $this->assertDatabaseCount('partner_orders', 0);
    }

    public function test_order_can_be_created_from_owned_unexpired_quote_and_replay_reuses_it(): void
    {
        $quotePayload = ['items' => [['good_id' => $this->goodId, 'quantity' => 1]], 'delivery' => ['method_id' => $this->deliveryMethodId, 'address' => 'Moscow']];
        $quotePath = '/api/partner/v1/checkout/quote';
        $body = json_encode($quotePayload, JSON_THROW_ON_ERROR);
        $quoteId = $this->postJson($quotePath, $quotePayload, $this->signedHeaders('POST', $quotePath, $body, 'quote-for-order'))
            ->assertOk()->json('data.id');
        $payload = $this->payload('EXT-QUOTE-ORDER');
        $payload['quote_id'] = $quoteId;

        $this->postSignedOrder($payload, 'idem-quote-order', 'quote-order-first')->assertCreated();
        $this->postSignedOrder($payload, 'idem-quote-order', 'quote-order-replay')->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);
        $this->assertDatabaseCount('partner_orders', 1);
        $this->assertDatabaseHas('partner_checkout_quotes', ['public_id' => $quoteId, 'consumed_by_partner_order_id' => 1]);
    }

    public function test_payment_endpoint_is_idempotent_and_gateway_is_called_once(): void
    {
        DB::table('shop_payment_methods')->insert([
            'id' => 1, 'name' => 'T-Bank', 'type' => 'tbank_eacq', 'is_active' => true,
            'settings' => '{}', 'sort_order' => 1, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shop_payment_methods')->insert([
            'id' => 2, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true,
            'settings' => '{}', 'sort_order' => 2, 'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gateway = $this->mock(PartnerTbankGateway::class);
        $gateway->shouldReceive('initiate')->once()->andReturn([
            'success' => true, 'transaction_id' => 'gateway-1', 'payment_url' => 'https://pay.example.test/1',
        ]);
        $this->postSignedOrder($this->payload('EXT-PAYMENT-IDEM'), 'idem-create-payment', 'payment-create')->assertCreated();
        $path = '/api/partner/v1/orders/EXT-PAYMENT-IDEM/payment';
        $payload = ['payment_method_id' => 1];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = array_merge($this->signedHeaders('POST', $path, $body, 'payment-first'), ['Idempotency-Key' => 'payment-key-1']);
        $first = $this->postJson($path, $payload, $headers)->assertCreated();
        $headers = array_merge($this->signedHeaders('POST', $path, $body, 'payment-replay'), ['Idempotency-Key' => 'payment-key-1']);
        $second = $this->postJson($path, $payload, $headers)->assertOk();

        $this->assertSame($first->json('data.transaction_id'), $second->json('data.transaction_id'));
        $this->assertDatabaseCount('shop_payment_transactions', 1);
        $this->assertDatabaseCount('partner_payment_idempotencies', 1);

        $changed = ['payment_method_id' => 2];
        $changedBody = json_encode($changed, JSON_THROW_ON_ERROR);
        $headers = array_merge($this->signedHeaders('POST', $path, $changedBody, 'payment-conflict'), ['Idempotency-Key' => 'payment-key-1']);
        $this->postJson($path, $changed, $headers)->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertDatabaseCount('shop_payment_transactions', 1);
    }

    public function test_payment_idempotency_key_cannot_be_reused_for_another_order_of_same_partner(): void
    {
        DB::table('shop_payment_methods')->insert([
            'id' => 1, 'name' => 'T-Bank', 'type' => 'tbank_eacq', 'is_active' => true,
            'settings' => '{}', 'sort_order' => 1, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gateway = $this->mock(PartnerTbankGateway::class);
        $gateway->shouldReceive('initiate')->once()->andReturn([
            'success' => true, 'transaction_id' => 'gateway-order-a', 'payment_url' => 'https://pay.example.test/order-a',
        ]);

        $this->postSignedOrder($this->payload('EXT-PAYMENT-ORDER-A'), 'idem-payment-order-a', 'payment-order-a-create')->assertCreated();
        $this->postSignedOrder($this->payload('EXT-PAYMENT-ORDER-B'), 'idem-payment-order-b', 'payment-order-b-create')->assertCreated();

        $this->postSignedPayment('EXT-PAYMENT-ORDER-A', 1, 'shared-payment-order-key', 'payment-order-a')->assertCreated();
        $this->postSignedPayment('EXT-PAYMENT-ORDER-B', 1, 'shared-payment-order-key', 'payment-order-b')
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $firstOrderId = DB::table('partner_orders')->where('external_order_id', 'EXT-PAYMENT-ORDER-A')->value('id');
        $this->assertDatabaseCount('partner_payment_idempotencies', 1);
        $this->assertDatabaseHas('partner_payment_idempotencies', [
            'partner_order_id' => $firstOrderId,
            'idempotency_key' => 'shared-payment-order-key',
        ]);
        $this->assertDatabaseCount('shop_payment_transactions', 1);
    }

    public function test_paid_and_cancelled_orders_do_not_receive_new_payment(): void
    {
        DB::table('shop_payment_methods')->insert([
            'id' => 1, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true,
            'settings' => '{}', 'sort_order' => 1, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['paid', 'cancelled'] as $index => $status) {
            $external = 'EXT-NOT-PAYABLE-'.strtoupper($status);
            $this->postSignedOrder($this->payload($external), 'idem-not-payable-'.$index, 'not-payable-create-'.$index)->assertCreated();
            DB::table('partner_orders')->where('external_order_id', $external)->update(['status' => $status]);
            if ($status === 'paid') {
                DB::table('shop_orders')->where('id', $index + 1)->update(['payed' => true]);
            }
            $path = '/api/partner/v1/orders/'.$external.'/payment';
            $payload = ['payment_method_id' => 1]; $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers = array_merge($this->signedHeaders('POST', $path, $body, 'not-payable-payment-'.$index), ['Idempotency-Key' => 'not-payable-key-'.$index]);
            $this->postJson($path, $payload, $headers)->assertConflict();
        }
        $this->assertDatabaseCount('shop_payment_transactions', 0);
    }

    public function test_same_payment_idempotency_key_is_isolated_between_partners(): void
    {
        DB::table('shop_payment_methods')->insert([
            'id' => 1, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true,
            'settings' => '{}', 'sort_order' => 1, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postSignedOrder($this->payload('EXT-SHARED-KEY-1'), 'shared-order-key', 'shared-order-1')->assertCreated();
        $this->postSignedPayment('EXT-SHARED-KEY-1', 1, 'shared-payment-key', 'shared-payment-1')->assertCreated();

        $partner = Partner::create([
            'public_id' => (string) Str::uuid(), 'code' => 'order-http-second', 'name' => 'Second Partner',
            'is_active' => true, 'commission_rate' => 0.1,
        ]);
        $this->secret = 'partner-order-http-second-secret';
        $this->credential = $partner->credentials()->create([
            'key_id' => 'pk_order_http_second', 'secret' => $this->secret,
            'scopes' => ['orders:write', 'payments:write'],
        ]);
        $this->postSignedOrder($this->payload('EXT-SHARED-KEY-2'), 'shared-order-key', 'shared-order-2')->assertCreated();
        $this->postSignedPayment('EXT-SHARED-KEY-2', 1, 'shared-payment-key', 'shared-payment-2')->assertCreated();

        $this->assertDatabaseCount('partner_payment_idempotencies', 2);
        $this->assertSame(2, DB::table('partner_payment_idempotencies')->distinct()->count('partner_id'));
    }

    public function test_expired_foreign_changed_and_reused_quotes_are_rejected(): void
    {
        $expired = $this->createQuote('quote-expired');
        DB::table('partner_checkout_quotes')->where('public_id', $expired)->update(['expires_at' => now()->subMinute()]);
        $payload = $this->payload('EXT-QUOTE-EXPIRED'); $payload['quote_id'] = $expired;
        $this->postSignedOrder($payload, 'idem-quote-expired', 'quote-expired-order')->assertConflict()
            ->assertJsonPath('error.code', 'quote_expired');

        $foreign = $this->createQuote('quote-foreign');
        DB::table('partner_checkout_quotes')->where('public_id', $foreign)->update(['partner_id' => 999]);
        $payload = $this->payload('EXT-QUOTE-FOREIGN'); $payload['quote_id'] = $foreign;
        $this->postSignedOrder($payload, 'idem-quote-foreign', 'quote-foreign-order')->assertNotFound();

        $changed = $this->createQuote('quote-changed');
        $payload = $this->payload('EXT-QUOTE-CHANGED'); $payload['quote_id'] = $changed; $payload['items'][0]['quantity'] = 2;
        $this->postSignedOrder($payload, 'idem-quote-changed', 'quote-changed-order')->assertConflict();

        $used = $this->createQuote('quote-used');
        $payload = $this->payload('EXT-QUOTE-USED-1'); $payload['quote_id'] = $used;
        $this->postSignedOrder($payload, 'idem-quote-used-1', 'quote-used-first')->assertCreated();
        $payload['external_order_id'] = 'EXT-QUOTE-USED-2';
        $this->postSignedOrder($payload, 'idem-quote-used-2', 'quote-used-second')->assertConflict();
    }

    public function test_quote_detects_price_and_available_stock_changes(): void
    {
        $priceQuote = $this->createQuote('quote-price');
        DB::table('shop_goods')->where('id', $this->goodId)->update(['price' => 1200]);
        $payload = $this->payload('EXT-QUOTE-PRICE'); $payload['quote_id'] = $priceQuote;
        $this->postSignedOrder($payload, 'idem-quote-price', 'quote-price-order')->assertConflict()
            ->assertJsonPath('error.code', 'quote_terms_changed')
            ->assertJsonPath('error.current_quote.total', 1500);

        DB::table('shop_goods')->where('id', $this->goodId)->update(['price' => 1000]);
        $stockQuote = $this->createQuote('quote-stock');
        DB::table('shop_stocks')->where('good_id', $this->goodId)->update(['reserved_quantity' => 2]);
        $payload = $this->payload('EXT-QUOTE-STOCK'); $payload['quote_id'] = $stockQuote;
        $this->postSignedOrder($payload, 'idem-quote-stock', 'quote-stock-order')->assertConflict();
    }

    public function test_failed_gateway_keeps_order_and_new_payment_key_can_retry(): void
    {
        DB::table('shop_payment_methods')->insert([
            'id' => 1, 'name' => 'T-Bank', 'type' => 'tbank_eacq', 'is_active' => true,
            'settings' => '{}', 'sort_order' => 1, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gateway = $this->mock(PartnerTbankGateway::class);
        $gateway->shouldReceive('initiate')->twice()->andReturn(
            ['success' => false, 'message' => 'Temporary failure'],
            ['success' => true, 'transaction_id' => 'gateway-retry', 'payment_url' => 'https://pay.example.test/retry'],
        );
        $this->postSignedOrder($this->payload('EXT-PAYMENT-RETRY'), 'idem-payment-retry-order', 'payment-retry-order')->assertCreated();
        $path = '/api/partner/v1/orders/EXT-PAYMENT-RETRY/payment';
        $payload = ['payment_method_id' => 1]; $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->postJson($path, $payload, array_merge($this->signedHeaders('POST', $path, $body, 'payment-retry-fail'), ['Idempotency-Key' => 'payment-retry-1']))
            ->assertCreated()->assertJsonPath('data.status', 'failed');
        $this->postJson($path, $payload, array_merge($this->signedHeaders('POST', $path, $body, 'payment-retry-success'), ['Idempotency-Key' => 'payment-retry-2']))
            ->assertCreated()->assertJsonPath('data.transaction_id', 'gateway-retry');
        $this->assertDatabaseCount('partner_orders', 1);
        $this->assertDatabaseCount('shop_payment_transactions', 2);
    }

    public function test_order_and_commission_reconciliation_use_stable_contract(): void
    {
        $this->postSignedOrder($this->payload('EXT-HTTP-SYNC'), 'idem-http-sync', 'nonce-http-sync-create')->assertCreated();

        $ordersPath = '/api/partner/v1/orders';
        $this->withHeaders($this->signedHeaders('GET', $ordersPath, '', 'nonce-http-sync-orders'))->get($ordersPath)
            ->assertOk()->assertJsonPath('data.0.external_order_id', 'EXT-HTTP-SYNC')
            ->assertJsonPath('meta.sort.0', 'updated_at');

        $commissionsPath = '/api/partner/v1/commissions';
        $this->withHeaders($this->signedHeaders('GET', $commissionsPath, '', 'nonce-http-sync-commissions'))->get($commissionsPath)
            ->assertOk()->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.amount', 100);
    }

    public function test_unpaid_partner_order_cancellation_is_idempotent_and_releases_reservation(): void
    {
        $this->postSignedOrder($this->payload('EXT-HTTP-CANCEL'), 'idem-http-cancel', 'nonce-http-cancel-create')->assertCreated();
        $path = '/api/partner/v1/orders/EXT-HTTP-CANCEL/cancel';
        $payload = ['reason' => 'Customer changed mind'];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postJson($path, $payload, $this->signedHeaders('POST', $path, $body, 'nonce-http-cancel-1'))
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($path, $payload, $this->signedHeaders('POST', $path, $body, 'nonce-http-cancel-2'))
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('shop_stocks', ['good_id' => $this->goodId, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('shop_stock_reservations', 0);
        $this->assertDatabaseHas('partner_commission_entries', ['status' => 'cancelled']);
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

    private function createQuote(string $nonce): string
    {
        $path = '/api/partner/v1/checkout/quote';
        $payload = [
            'items' => [['good_id' => $this->goodId, 'quantity' => 1]],
            'delivery' => ['method_id' => $this->deliveryMethodId, 'address' => 'Moscow'],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->postJson($path, $payload, $this->signedHeaders('POST', $path, $body, $nonce))
            ->assertOk()->json('data.id');
    }

    private function postSignedPayment(string $externalId, int $methodId, string $idempotencyKey, string $nonce)
    {
        $path = '/api/partner/v1/orders/'.$externalId.'/payment';
        $payload = ['payment_method_id' => $methodId];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->postJson($path, $payload, array_merge(
            $this->signedHeaders('POST', $path, $body, $nonce),
            ['Idempotency-Key' => $idempotencyKey],
        ));
    }

    private function createTables(): void
    {
        Schema::create('partners', function (Blueprint $table): void { $table->id(); $table->uuid('public_id')->unique(); $table->string('code')->unique(); $table->string('name'); $table->boolean('is_active'); $table->decimal('commission_rate', 7, 4); $table->string('commission_status')->default('after_completed'); $table->text('webhook_url')->nullable(); $table->text('webhook_secret')->nullable(); $table->json('allowed_ips')->nullable(); $table->json('metadata')->nullable(); $table->timestamps(); });
        Schema::create('partner_api_credentials', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id'); $table->string('key_id')->unique(); $table->text('secret'); $table->json('scopes'); $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamp('revoked_at')->nullable(); $table->timestamps(); });
        Schema::create('partner_api_request_logs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id')->nullable(); $table->string('request_id')->unique(); $table->string('method'); $table->string('path'); $table->unsignedSmallInteger('response_status')->nullable(); $table->unsignedInteger('duration_ms')->nullable(); $table->string('ip_address')->nullable(); $table->char('request_hash', 64)->nullable(); $table->string('error_code')->nullable(); $table->timestamps(); });
        Schema::create('shop_goods', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('sku'); $table->decimal('price', 12, 2); $table->decimal('sale_price', 12, 2)->nullable(); $table->integer('stock_quantity'); $table->boolean('is_active'); $table->timestamps(); });
        Schema::create('shop_delivery_methods', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('type'); $table->boolean('is_active'); $table->decimal('cost', 12, 2); $table->decimal('free_from', 12, 2)->nullable(); $table->text('description')->nullable(); $table->json('settings')->nullable(); $table->integer('sort_order')->default(0); $table->boolean('is_default')->default(false); $table->timestamps(); });
        Schema::create('shop_payment_methods', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('type'); $table->boolean('is_active'); $table->text('description')->nullable(); $table->json('settings')->nullable(); $table->integer('sort_order')->default(0); $table->boolean('is_default')->default(false); $table->timestamps(); });
        Schema::create('shop_order_statuses', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->boolean('is_active')->default(true); $table->boolean('is_finished')->default(false); $table->boolean('is_cancelled')->default(false); $table->boolean('is_taken_to_work')->default(false); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_payment_statuses', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->timestamps(); });
        Schema::create('shop_delivery_statuses', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->timestamps(); });
        DB::table('shop_order_statuses')->insert([
            ['id' => 1, 'name' => 'New', 'is_active' => true, 'is_finished' => false, 'is_cancelled' => false, 'is_taken_to_work' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Cancelled', 'is_active' => true, 'is_finished' => false, 'is_cancelled' => true, 'is_taken_to_work' => false, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Schema::create('shop_orders', function (Blueprint $table): void { $table->id(); $table->string('order_number')->unique(); $table->unsignedBigInteger('status_id')->nullable(); $table->unsignedBigInteger('payment_status_id')->nullable(); $table->unsignedBigInteger('delivery_status_id')->nullable(); $table->boolean('payed')->default(false); $table->string('customer_name'); $table->string('customer_email')->nullable(); $table->string('customer_phone')->nullable(); $table->json('items'); $table->decimal('subtotal', 12, 2); $table->decimal('delivery_cost', 12, 2); $table->decimal('total_amount', 12, 2); $table->integer('total_quantity'); $table->string('payment_method')->nullable(); $table->unsignedBigInteger('payment_method_id')->nullable(); $table->text('payment_url')->nullable(); $table->string('shipping_method')->nullable(); $table->unsignedBigInteger('shipping_method_id')->nullable(); $table->text('shipping_address')->nullable(); $table->text('notes')->nullable(); $table->string('ip_address')->nullable(); $table->text('user_agent')->nullable(); $table->json('metadata')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); });
        Schema::create('shop_payment_transactions', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('order_id'); $table->unsignedBigInteger('payment_method_id'); $table->string('status'); $table->decimal('amount', 12, 2); $table->string('transaction_id')->nullable(); $table->json('request_data')->nullable(); $table->json('response_data')->nullable(); $table->text('error_message')->nullable(); $table->timestamp('processed_at')->nullable(); $table->timestamps(); });
        Schema::create('shop_stocks', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable(); $table->unsignedBigInteger('warehouse_id'); $table->integer('quantity'); $table->integer('reserved_quantity')->default(0); $table->integer('min_quantity')->default(0); $table->timestamps(); });
        Schema::create('shop_stock_reservations', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable(); $table->unsignedBigInteger('warehouse_id'); $table->integer('quantity'); $table->timestamp('reserved_until'); $table->string('reservation_type'); $table->string('reference_id'); $table->unsignedBigInteger('user_id')->nullable(); $table->text('notes')->nullable(); $table->timestamps(); });
        Schema::create('partner_orders', function (Blueprint $table): void { $table->id(); $table->uuid('public_id'); $table->unsignedBigInteger('partner_id'); $table->unsignedBigInteger('shop_order_id'); $table->string('external_order_id'); $table->string('idempotency_key'); $table->char('request_hash', 64); $table->string('status'); $table->char('currency', 3)->default('RUB'); $table->decimal('items_amount', 12, 2); $table->decimal('discount_amount', 12, 2)->default(0); $table->decimal('delivery_amount', 12, 2); $table->decimal('total_amount', 12, 2); $table->decimal('commission_rate', 7, 4); $table->decimal('commission_base', 12, 2); $table->decimal('commission_amount', 12, 2); $table->string('commission_status'); $table->json('customer_reference')->nullable(); $table->json('attribution')->nullable(); $table->json('metadata')->nullable(); $table->timestamps(); $table->unique(['partner_id', 'external_order_id']); $table->unique(['partner_id', 'idempotency_key']); });
        Schema::create('partner_checkout_quotes', function (Blueprint $table): void { $table->id(); $table->uuid('public_id')->unique(); $table->unsignedBigInteger('partner_id'); $table->char('request_hash', 64); $table->json('request_payload'); $table->json('snapshot'); $table->timestamp('expires_at'); $table->timestamp('consumed_at')->nullable(); $table->unsignedBigInteger('consumed_by_partner_order_id')->nullable(); $table->timestamps(); });
        Schema::create('partner_payment_idempotencies', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id'); $table->unsignedBigInteger('partner_order_id'); $table->string('idempotency_key'); $table->char('request_hash', 64); $table->string('status'); $table->unsignedBigInteger('payment_transaction_id')->nullable(); $table->json('result')->nullable(); $table->timestamps(); $table->unique(['partner_id', 'idempotency_key']); });
        Schema::create('partner_commission_entries', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id'); $table->unsignedBigInteger('partner_order_id'); $table->unsignedBigInteger('partner_payout_id')->nullable(); $table->string('type'); $table->string('status'); $table->decimal('amount', 12, 2); $table->char('currency', 3); $table->string('reason')->nullable(); $table->json('metadata')->nullable(); $table->timestamp('recognized_at')->nullable(); $table->timestamp('paid_at')->nullable(); $table->timestamps(); });
    }
}
