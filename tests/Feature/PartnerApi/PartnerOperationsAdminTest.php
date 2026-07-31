<?php

namespace Tests\Feature\PartnerApi;

use App\Http\Controllers\Api\Admin\PartnerApi\CommissionController;
use App\Http\Controllers\Api\Admin\PartnerApi\OrderController;
use App\Http\Controllers\Api\Admin\PartnerApi\PayoutController;
use App\Http\Controllers\Api\Admin\PartnerApi\RequestLogController;
use App\Http\Controllers\Api\Admin\PartnerApi\WebhookController;
use App\Jobs\DeliverPartnerWebhookJob;
use App\Models\Partner;
use App\Models\PartnerApiRequestLog;
use App\Models\PartnerCommissionEntry;
use App\Models\PartnerOrder;
use App\Models\PartnerPayout;
use App\Models\PartnerWebhookDelivery;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PartnerOperationsAdminTest extends TestCase
{
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->partner = Partner::create([
            'public_id' => (string) Str::uuid(),
            'code' => 'operations-partner',
            'name' => 'Operations Partner',
            'is_active' => true,
            'commission_rate' => 0.1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['partner_webhook_deliveries', 'partner_api_request_logs', 'partner_commission_entries', 'partner_payouts', 'partner_orders', 'shop_orders', 'partners'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/admin/partner-api/orders')->assertUnauthorized();
        $this->getJson('/api/admin/partner-api/request-logs')->assertUnauthorized();
    }

    public function test_authenticated_user_without_admin_role_cannot_access_partner_api_admin_endpoints(): void
    {
        $user = new User(['name' => 'Operator', 'email' => 'operator@example.test']);
        $user->id = 999999;
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/partner-api/orders')
            ->assertForbidden()
            ->assertJsonPath('message', 'Access denied. Insufficient permissions.');
        $this->postJson('/api/admin/partner-api/payouts', ['partner_id' => $this->partner->id])
            ->assertForbidden();
    }

    public function test_order_list_filters_and_card_hide_signing_fields(): void
    {
        $matching = $this->createOrder('EXT-MATCH', 'created');
        $this->createOrder('EXT-OTHER', 'completed');

        $request = Request::create('/api/admin/partner-api/orders', 'GET', [
            'partner_id' => $this->partner->id,
            'status' => 'created',
            'search' => 'MATCH',
        ]);
        $payload = app(OrderController::class)->index($request)->getData(true);

        $this->assertSame(1, $payload['data']['total']);
        $this->assertSame($matching->public_id, $payload['data']['data'][0]['public_id']);

        $card = app(OrderController::class)->show(Request::create('/', 'GET'), $matching)->getData(true)['data'];
        $this->assertArrayNotHasKey('idempotency_key', $card);
        $this->assertArrayNotHasKey('request_hash', $card);
    }

    public function test_commission_status_change_updates_partner_order(): void
    {
        $order = $this->createOrder('EXT-COMMISSION', 'completed');
        $entry = $this->createCommission($order, 'pending', 125.50);

        $response = app(CommissionController::class)->updateStatus(
            Request::create('/', 'PATCH', ['status' => 'recognized', 'reason' => 'Заказ завершён']),
            $entry
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('recognized', $entry->fresh()->status);
        $this->assertNotNull($entry->fresh()->recognized_at);
        $this->assertSame('recognized', $order->fresh()->commission_status);
    }

    public function test_payout_is_formed_from_recognized_entries_and_marks_them_paid(): void
    {
        $firstOrder = $this->createOrder('EXT-PAYOUT-1', 'completed');
        $secondOrder = $this->createOrder('EXT-PAYOUT-2', 'completed');
        $first = $this->createCommission($firstOrder, 'recognized', 100);
        $second = $this->createCommission($secondOrder, 'recognized', 250);
        $this->createCommission($this->createOrder('EXT-PENDING', 'created'), 'pending', 999);

        $controller = app(PayoutController::class);
        $created = $controller->store(Request::create('/', 'POST', [
            'partner_id' => $this->partner->id,
            'commission_ids' => [$first->id, $second->id],
        ]));
        $payout = \App\Models\PartnerPayout::findOrFail($created->getData(true)['data']['id']);

        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame('350.00', $payout->amount);
        $this->assertSame(2, $payout->entries_count);
        $this->assertSame($payout->id, $first->fresh()->partner_payout_id);

        $controller->markPaid(Request::create('/', 'POST', ['payment_reference' => 'BANK-42']), $payout);

        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame('BANK-42', $payout->fresh()->payment_reference);
        $this->assertSame('paid', $first->fresh()->status);
        $this->assertSame('paid', $secondOrder->fresh()->commission_status);
    }

    public function test_commission_cannot_be_changed_after_it_is_in_formed_payout(): void
    {
        [$payout, $entries] = $this->createPayoutWithEntries([125.50]);

        $this->assertConflict(
            fn () => app(CommissionController::class)->updateStatus(Request::create('/', 'PATCH', ['status' => 'pending']), $entries[0]),
            'Сначала отмените выплату'
        );

        $this->assertSame('recognized', $entries[0]->fresh()->status);
        $this->assertSame($payout->id, $entries[0]->fresh()->partner_payout_id);
    }

    public function test_commission_from_paid_payout_cannot_be_changed(): void
    {
        [$payout, $entries] = $this->createPayoutWithEntries([125.50]);
        app(PayoutController::class)->markPaid(Request::create('/', 'POST', ['payment_reference' => 'PAYMENT-1']), $payout);

        $this->assertConflict(
            fn () => app(CommissionController::class)->updateStatus(Request::create('/', 'PATCH', ['status' => 'cancelled']), $entries[0]),
            'оплаченной выплаты'
        );
        $this->assertSame('paid', $entries[0]->fresh()->status);
    }

    public function test_payout_is_not_paid_when_amount_does_not_match(): void
    {
        [$payout, $entries] = $this->createPayoutWithEntries([100, 250]);
        $payout->update(['amount' => 999]);

        $this->assertPayoutIntegrityConflict($payout);
        $this->assertSame('formed', $payout->fresh()->status);
        $this->assertSame('recognized', $entries[0]->fresh()->status);
    }

    public function test_payout_is_not_paid_when_entries_count_does_not_match(): void
    {
        [$payout] = $this->createPayoutWithEntries([100, 250]);
        $payout->update(['entries_count' => 3]);

        $this->assertPayoutIntegrityConflict($payout);
        $this->assertSame('formed', $payout->fresh()->status);
    }

    public function test_payout_is_not_paid_when_it_contains_another_partners_commission(): void
    {
        [$payout, $entries] = $this->createPayoutWithEntries([100, 250]);
        $otherPartner = Partner::create([
            'public_id' => (string) Str::uuid(),
            'code' => 'other-partner',
            'name' => 'Other Partner',
            'is_active' => true,
            'commission_rate' => 0.1,
        ]);
        $entries[1]->update(['partner_id' => $otherPartner->id]);

        $this->assertPayoutIntegrityConflict($payout);
        $this->assertSame('formed', $payout->fresh()->status);
    }

    public function test_cancelling_payout_releases_all_commissions_without_changing_status(): void
    {
        [$payout, $entries] = $this->createPayoutWithEntries([100, 250]);

        app(PayoutController::class)->cancel(Request::create('/', 'POST', ['reason' => 'Ошибка реквизитов']), $payout);

        $this->assertSame('cancelled', $payout->fresh()->status);
        foreach ($entries as $entry) {
            $this->assertNull($entry->fresh()->partner_payout_id);
            $this->assertSame('recognized', $entry->fresh()->status);
        }
    }

    public function test_paid_payout_cannot_be_cancelled(): void
    {
        [$payout] = $this->createPayoutWithEntries([100]);
        app(PayoutController::class)->markPaid(Request::create('/', 'POST', ['payment_reference' => 'PAYMENT-2']), $payout);

        $this->assertConflict(
            fn () => app(PayoutController::class)->cancel(Request::create('/', 'POST', ['reason' => 'Повтор']), $payout),
            'сформированную выплату'
        );
        $this->assertSame('paid', $payout->fresh()->status);
    }

    public function test_webhook_card_redacts_signature_and_manual_retry_resets_delivery(): void
    {
        Queue::fake();
        $delivery = PartnerWebhookDelivery::create([
            'public_id' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'event' => 'order.updated',
            'payload' => ['order_id' => 'EXT-1'],
            'status' => 'failed',
            'attempts' => 4,
            'response_status' => 500,
            'response_body' => 'failure',
            'last_error' => 'endpoint failed',
        ]);

        $controller = app(WebhookController::class);
        $card = $controller->show(Request::create('/', 'GET'), $delivery)->getData(true)['data'];
        $this->assertSame('<redacted>', $card['request_preview']['headers']['X-Partner-Signature']);
        $this->assertSame(['order_id' => 'EXT-1'], $card['request_preview']['body']);

        $controller->retry(Request::create('/', 'POST'), $delivery);

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertNull($delivery->response_body);
        Queue::assertPushed(DeliverPartnerWebhookJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_webhook_contains_valid_hmac_signature(): void
    {
        $secret = 'test-webhook-secret';
        $this->partner->update(['webhook_url' => 'https://8.8.8.8/webhook', 'webhook_secret' => $secret]);
        $delivery = $this->createWebhookDelivery();

        Http::fake(function ($request) use ($secret) {
            $timestamp = $request->header('X-Partner-Timestamp')[0];
            $this->assertSame(hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret), $request->header('X-Partner-Signature')[0]);

            return Http::response(['accepted' => true], 200);
        });

        (new DeliverPartnerWebhookJob($delivery->id))->handle(app(\App\Services\Partner\PartnerWebhookUrlGuard::class));

        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_webhook_retries_after_temporary_endpoint_error(): void
    {
        $this->partner->update(['webhook_url' => 'https://8.8.8.8/webhook', 'webhook_secret' => 'secret']);
        $delivery = $this->createWebhookDelivery();
        Http::fakeSequence()->push('temporary', 503)->push('accepted', 200);
        $job = new DeliverPartnerWebhookJob($delivery->id);

        try {
            $job->handle(app(\App\Services\Partner\PartnerWebhookUrlGuard::class));
            $this->fail('Temporary webhook error must release the job for retry.');
        } catch (RuntimeException) {
            $this->assertSame('retrying', $delivery->fresh()->status);
            $this->assertNotNull($delivery->fresh()->next_attempt_at);
        }

        $job->handle(app(\App\Services\Partner\PartnerWebhookUrlGuard::class));
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        Http::assertSentCount(2);
    }

    public function test_webhook_redirect_is_not_followed_and_is_treated_as_failure(): void
    {
        $this->partner->update(['webhook_url' => 'https://8.8.8.8/webhook', 'webhook_secret' => 'secret']);
        $delivery = $this->createWebhookDelivery();
        Http::fake([ '*' => Http::response('', 302, ['Location' => 'https://127.0.0.1/private']) ]);

        try {
            (new DeliverPartnerWebhookJob($delivery->id))->handle(app(\App\Services\Partner\PartnerWebhookUrlGuard::class));
            $this->fail('Redirect response must not be accepted.');
        } catch (RuntimeException) {
            $this->assertSame(302, $delivery->fresh()->response_status);
            $this->assertSame('retrying', $delivery->fresh()->status);
        }
        Http::assertSentCount(1);
    }

    public function test_request_log_combines_partner_endpoint_status_and_request_id_filters(): void
    {
        PartnerApiRequestLog::create([
            'partner_id' => $this->partner->id,
            'request_id' => 'req-target-123',
            'method' => 'POST',
            'path' => '/api/partner/v1/orders',
            'response_status' => 422,
            'duration_ms' => 40,
        ]);
        PartnerApiRequestLog::create([
            'partner_id' => $this->partner->id,
            'request_id' => 'req-other-456',
            'method' => 'GET',
            'path' => '/api/partner/v1/catalog/products',
            'response_status' => 200,
            'duration_ms' => 10,
        ]);

        $request = Request::create('/', 'GET', [
            'partner_id' => $this->partner->id,
            'endpoint' => '/orders',
            'status_group' => '4xx',
            'request_id' => 'target',
            'method' => 'POST',
        ]);
        $payload = app(RequestLogController::class)->index($request)->getData(true);

        $this->assertSame(1, $payload['data']['total']);
        $this->assertSame('req-target-123', $payload['data']['data'][0]['request_id']);
        $this->assertSame(1, $payload['meta']['summary']['client_errors']);
        $this->assertSame(40, $payload['meta']['summary']['average_duration_ms']);
    }

    private function createOrder(string $externalId, string $status): PartnerOrder
    {
        return PartnerOrder::create([
            'public_id' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'external_order_id' => $externalId,
            'idempotency_key' => 'idem-'.$externalId,
            'request_hash' => hash('sha256', $externalId),
            'status' => $status,
            'total_amount' => 1000,
            'commission_rate' => 0.1,
            'commission_base' => 1000,
            'commission_amount' => 100,
            'commission_status' => 'pending',
        ]);
    }

    private function createCommission(PartnerOrder $order, string $status, float $amount): PartnerCommissionEntry
    {
        return PartnerCommissionEntry::create([
            'partner_id' => $this->partner->id,
            'partner_order_id' => $order->id,
            'type' => 'accrual',
            'status' => $status,
            'amount' => $amount,
            'recognized_at' => $status === 'recognized' ? now() : null,
        ]);
    }

    /**
     * @param  list<float|int>  $amounts
     * @return array{PartnerPayout, list<PartnerCommissionEntry>}
     */
    private function createPayoutWithEntries(array $amounts): array
    {
        $entries = [];
        foreach ($amounts as $index => $amount) {
            $entries[] = $this->createCommission($this->createOrder('EXT-PAYOUT-CHECK-'.$index.'-'.Str::random(6), 'completed'), 'recognized', (float) $amount);
        }

        $response = app(PayoutController::class)->store(Request::create('/', 'POST', [
            'partner_id' => $this->partner->id,
            'commission_ids' => array_map(fn (PartnerCommissionEntry $entry): int => $entry->id, $entries),
        ]));

        return [PartnerPayout::findOrFail($response->getData(true)['data']['id']), $entries];
    }

    private function createWebhookDelivery(): PartnerWebhookDelivery
    {
        return PartnerWebhookDelivery::create([
            'public_id' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'event' => 'order.created',
            'payload' => ['id' => 'order-public-id', 'total' => 1250],
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    private function assertPayoutIntegrityConflict(PartnerPayout $payout): void
    {
        $this->assertConflict(
            fn () => app(PayoutController::class)->markPaid(Request::create('/', 'POST', ['payment_reference' => 'PAYMENT-CONFLICT']), $payout),
            'Состав выплаты изменён'
        );
    }

    private function assertConflict(callable $callback, string $messageFragment): void
    {
        try {
            $callback();
            $this->fail('Expected HTTP 409 conflict was not thrown.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString($messageFragment, $exception->getMessage());
        }
    }

    private function createTables(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->decimal('commission_rate', 7, 4)->default(0);
            $table->string('commission_status')->default('after_completed');
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('shop_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number');
            $table->timestamps();
        });
        Schema::create('partner_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('shop_order_id')->nullable();
            $table->string('external_order_id');
            $table->string('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('status');
            $table->char('currency', 3)->default('RUB');
            $table->decimal('items_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('delivery_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('commission_rate', 7, 4)->default(0);
            $table->decimal('commission_base', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->string('commission_status')->default('pending');
            $table->json('customer_reference')->nullable();
            $table->json('attribution')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('partner_payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->string('number');
            $table->unsignedBigInteger('partner_id');
            $table->string('status');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
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
        });
        Schema::create('partner_commission_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('partner_order_id');
            $table->unsignedBigInteger('partner_payout_id')->nullable();
            $table->string('type');
            $table->string('status');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('RUB');
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recognized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('partner_api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('request_id');
            $table->string('method');
            $table->string('path');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip_address')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
        });
        Schema::create('partner_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('partner_order_id')->nullable();
            $table->string('event');
            $table->json('payload');
            $table->string('status');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }
}
