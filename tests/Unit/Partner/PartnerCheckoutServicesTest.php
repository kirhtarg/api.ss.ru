<?php

namespace Tests\Unit\Partner;

use App\Models\ShopDeliveryMethod;
use App\Models\ShopOrder;
use App\Models\ShopPaymentMethod;
use App\Models\ShopPaymentTransaction;
use App\Services\CdekService;
use App\Services\DeliveryPackageService;
use App\Services\Partner\PartnerDeliveryService;
use App\Services\Partner\PartnerPaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class PartnerCheckoutServicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('shop_delivery_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->boolean('is_active')->default(true);
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('free_from', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->json('settings')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('shop_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->json('settings')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('can_disable_default')->default(true);
            $table->timestamps();
        });
        Schema::create('shop_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number');
            $table->boolean('is_active')->default(true);
            $table->decimal('total_amount', 12, 2);
            $table->json('items')->nullable();
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->text('payment_url')->nullable();
            $table->timestamps();
        });
        Schema::create('shop_payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_method_id');
            $table->string('status');
            $table->decimal('amount', 12, 2);
            $table->string('transaction_id')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shop_payment_transactions');
        Schema::dropIfExists('shop_orders');
        Schema::dropIfExists('shop_payment_methods');
        Schema::dropIfExists('shop_delivery_methods');
        Mockery::close();
        parent::tearDown();
    }

    public function test_fixed_delivery_cost_is_calculated_on_server_and_respects_free_threshold(): void
    {
        $method = ShopDeliveryMethod::create([
            'name' => 'Courier', 'type' => 'courier', 'cost' => 500, 'free_from' => 5000, 'is_active' => true, 'is_default' => true,
        ]);
        $service = new PartnerDeliveryService(
            Mockery::mock(CdekService::class),
            Mockery::mock(DeliveryPackageService::class),
        );

        $paid = $service->calculate(['method_id' => $method->id, 'amount' => 1], [], 4999);
        $free = $service->calculate(['method_id' => $method->id, 'amount' => 9999], [], 5000);

        $this->assertSame(500.0, $paid['amount']);
        $this->assertSame(0.0, $free['amount']);
    }

    public function test_cash_payment_updates_existing_order_without_external_transaction(): void
    {
        $method = ShopPaymentMethod::create(['name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'is_default' => true]);
        $order = ShopOrder::withoutEvents(fn () => ShopOrder::create([
            'order_number' => 'SS-P-TEST', 'total_amount' => 1500, 'items' => [],
        ]));

        $result = app(PartnerPaymentService::class)->createForIsolatedTest($order, $method->id);

        $this->assertSame('pending', $result['status']);
        $this->assertNull($result['payment_url']);
        $this->assertSame($method->id, $order->fresh()->payment_method_id);
        $this->assertDatabaseCount('shop_payment_transactions', 0);
    }

    public function test_failed_online_payment_is_recorded_without_damaging_order(): void
    {
        $method = ShopPaymentMethod::create([
            'name' => 'Online by instalments',
            'type' => 'tbank_dolyame',
            'is_active' => true,
            'settings' => ['dolyame_provider' => 'partner'],
        ]);
        $order = ShopOrder::withoutEvents(fn () => ShopOrder::create([
            'order_number' => 'SS-P-FAILED-PAYMENT',
            'total_amount' => 1500,
            'items' => [],
        ]));

        try {
            $result = app(PartnerPaymentService::class)->createForIsolatedTest($order, $method->id);
            $this->assertSame('failed', $result['status']);
        } catch (UnprocessableEntityHttpException $exception) {
            $this->fail('Gateway failure must be represented as a retryable payment state.');
        }

        $transaction = ShopPaymentTransaction::firstOrFail();
        $this->assertSame('failed', $transaction->status);
        $this->assertSame($order->id, $transaction->order_id);
        $this->assertSame('1500.00', $transaction->amount);
        $this->assertSame('1500.00', $order->fresh()->total_amount);
        $this->assertNull($order->fresh()->payment_url);
        $this->assertSame($method->id, $order->fresh()->payment_method_id);
    }
}
