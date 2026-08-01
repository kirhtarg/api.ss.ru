<?php

namespace App\Services\Partner;

use App\Models\Partner;
use App\Models\PartnerCommissionEntry;
use App\Models\PartnerOrder;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopOrderStatus;
use App\Services\OrderCalculationService;
use App\Services\StockReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PartnerOrderService
{
    public function __construct(
        private readonly OrderCalculationService $calculation,
        private readonly StockReservationService $reservations,
        private readonly PartnerDeliveryService $delivery,
        private readonly PartnerStockAvailabilityService $availability,
        private readonly PartnerCheckoutQuoteService $quotes,
    ) {}

    public function create(Partner $partner, array $payload, string $idempotencyKey, ?string $ipAddress, ?string $userAgent): array
    {
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Log::info('Partner order creation started', [
            'partner_id' => $partner->id,
            'external_order_id' => $payload['external_order_id'],
            'idempotency_key_hash' => hash('sha256', $idempotencyKey),
            'item_count' => count($payload['items']),
        ]);

        return DB::transaction(function () use ($partner, $payload, $idempotencyKey, $requestHash, $ipAddress, $userAgent): array {
            $existing = PartnerOrder::query()
                ->where('partner_id', $partner->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    Log::warning('Partner idempotency conflict', [
                        'partner_id' => $partner->id,
                        'partner_order_id' => $existing->id,
                    ]);
                    throw new ConflictHttpException('Idempotency key was already used with another request');
                }

                return ['order' => $existing->load('shopOrder'), 'replayed' => true];
            }

            $externalOrderExists = PartnerOrder::query()
                ->where('partner_id', $partner->id)
                ->where('external_order_id', $payload['external_order_id'])
                ->lockForUpdate()
                ->exists();
            if ($externalOrderExists) {
                throw new ConflictHttpException('External order ID already exists');
            }

            $quote = ! empty($payload['quote_id'])
                ? $this->quotes->validateForOrder($partner, $payload['quote_id'], $payload['items'], $payload['delivery'] ?? [])
                : null;
            $items = $this->buildOrderItems($payload['items']);
            $itemsAmount = round(array_sum(array_column($items, 'total')), 2);
            $delivery = $this->delivery->calculate($payload['delivery'] ?? [], $items, $itemsAmount);
            $deliveryAmount = $delivery['amount'];
            $totalAmount = round($itemsAmount + $deliveryAmount, 2);
            $commissionRate = (float) $partner->commission_rate;
            $commissionAmount = round($itemsAmount * $commissionRate, 2);

            $shopOrder = ShopOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'status_id' => 1,
                'customer_name' => $payload['customer']['name'],
                'customer_email' => $payload['customer']['email'] ?? null,
                'customer_phone' => $payload['customer']['phone'] ?? null,
                'items' => $items,
                'subtotal' => $itemsAmount,
                'delivery_cost' => $deliveryAmount,
                'total_amount' => $totalAmount,
                'total_quantity' => array_sum(array_column($items, 'quantity')),
                'payment_method' => 'partner',
                'shipping_method' => $delivery['method']->name,
                'shipping_method_id' => $delivery['method']->id,
                'shipping_address' => $payload['delivery']['address'] ?? null,
                'notes' => $payload['comment'] ?? null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => ['source' => 'partner_api', 'partner_id' => $partner->public_id, 'delivery' => $delivery['metadata']],
            ]);
            $reservationIds = $this->reservations->reserveForOrder(
                $shopOrder,
                $items,
                config('partners.reservation_ttl_minutes', 30),
            );

            $partnerOrder = PartnerOrder::create([
                'public_id' => (string) Str::uuid(),
                'partner_id' => $partner->id,
                'shop_order_id' => $shopOrder->id,
                'external_order_id' => $payload['external_order_id'],
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => 'created',
                'items_amount' => $itemsAmount,
                'delivery_amount' => $deliveryAmount,
                'total_amount' => $totalAmount,
                'commission_rate' => $commissionRate,
                'commission_base' => $itemsAmount,
                'commission_amount' => $commissionAmount,
                'commission_status' => 'pending',
                'customer_reference' => $payload['customer_reference'] ?? null,
                'attribution' => $payload['attribution'] ?? null,
                'metadata' => array_merge($payload['metadata'] ?? [], ['stock_reservation_ids' => $reservationIds]),
            ]);
            if ($quote) {
                $this->quotes->consume($quote, $partnerOrder);
            }

            PartnerCommissionEntry::create([
                'partner_id' => $partner->id,
                'partner_order_id' => $partnerOrder->id,
                'type' => 'accrual',
                'status' => 'pending',
                'amount' => $commissionAmount,
                'currency' => 'RUB',
                'reason' => 'Partner order created',
            ]);

            app(PartnerWebhookService::class)->queueOrderEvent($partnerOrder, 'order.created');

            Log::info('Partner order creation completed', [
                'partner_id' => $partner->id,
                'partner_order_id' => $partnerOrder->id,
                'shop_order_id' => $shopOrder->id,
                'total_amount' => $totalAmount,
                'commission_amount' => $commissionAmount,
            ]);

            return ['order' => $partnerOrder->load('shopOrder'), 'replayed' => false];
        }, 3);
    }

    public function cancel(Partner $partner, string $externalOrderId, string $reason): PartnerOrder
    {
        return DB::transaction(function () use ($partner, $externalOrderId, $reason): PartnerOrder {
            $order = PartnerOrder::query()->with('shopOrder.status')
                ->where('partner_id', $partner->id)
                ->where('external_order_id', $externalOrderId)
                ->lockForUpdate()
                ->firstOrFail();
            $shopOrder = ShopOrder::query()->lockForUpdate()->findOrFail($order->shop_order_id);

            if ($order->status === 'cancelled') {
                return $order->fresh(['shopOrder.status', 'shopOrder.paymentStatus', 'shopOrder.deliveryStatus']);
            }
            if (in_array($order->status, ['paid', 'completed'], true) || $shopOrder->payed) {
                throw new ConflictHttpException('Paid or completed partner order cannot be cancelled through Partner API');
            }

            $cancelledStatus = ShopOrderStatus::query()->where('is_cancelled', true)->where('is_active', true)->orderBy('sort_order')->first();
            if (! $cancelledStatus) {
                throw new ConflictHttpException('Store cancellation status is not configured');
            }

            $metadata = $shopOrder->metadata ?? [];
            $metadata['partner_cancellation'] = [
                'reason' => $reason,
                'requested_at' => now()->toIso8601String(),
            ];
            $shopOrder->update(['status_id' => $cancelledStatus->id, 'metadata' => $metadata]);
            $this->reservations->releaseForOrder($shopOrder);
            $order->commissions()->whereIn('status', ['pending', 'recognized'])->update([
                'status' => 'cancelled',
                'reason' => 'Partner order cancelled',
            ]);
            $order->update(['status' => 'cancelled', 'commission_status' => 'cancelled']);

            Log::info('Partner order cancellation accepted', [
                'partner_id' => $partner->id,
                'partner_order_id' => $order->id,
                'shop_order_id' => $shopOrder->id,
            ]);

            return $order->fresh(['shopOrder.status', 'shopOrder.paymentStatus', 'shopOrder.deliveryStatus']);
        }, 3);
    }

    private function buildOrderItems(array $requestedItems): array
    {
        $items = [];

        foreach ($requestedItems as $requestedItem) {
            $good = ShopGood::query()->whereKey($requestedItem['good_id'])->where('is_active', true)->first();
            if (! $good) {
                throw new UnprocessableEntityHttpException('Product is unavailable: '.$requestedItem['good_id']);
            }

            $variation = null;
            if (! empty($requestedItem['variation_id'])) {
                $variation = ShopGoodVariation::query()
                    ->whereKey($requestedItem['variation_id'])
                    ->where('good_id', $good->id)
                    ->where('is_active', true)
                    ->first();
                if (! $variation) {
                    throw new UnprocessableEntityHttpException('Product variation is unavailable: '.$requestedItem['variation_id']);
                }
            }

            $quantity = (int) $requestedItem['quantity'];
            $stockQuantity = $this->availability->quantity($good, $variation);
            if ($stockQuantity < $quantity) {
                throw new UnprocessableEntityHttpException('Insufficient stock for product: '.$good->id);
            }

            $calculation = $this->calculation->calculateFinalUnitPrice($good, $variation);
            $unitPrice = round((float) $calculation['final_price'], 2);
            $items[] = [
                'good_id' => $good->id,
                'good_name' => $good->name,
                'good_sku' => $good->sku,
                'variation_id' => $variation?->id,
                'variation_name' => $variation?->display_name,
                'variation_sku' => $variation?->sku,
                'quantity' => $quantity,
                'price' => $unitPrice,
                'base_price' => round((float) $calculation['base_price'], 2),
                'sale_price' => round((float) $calculation['sale_price'], 2),
                'final_price' => $unitPrice,
                'discounts' => $calculation['discounts'],
                'total' => round($unitPrice * $quantity, 2),
            ];
        }

        return $items;
    }

    private function generateOrderNumber(): string
    {
        do {
            $number = 'SS-P-'.now()->format('Ymd').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (ShopOrder::query()->where('order_number', $number)->exists());

        return $number;
    }
}
