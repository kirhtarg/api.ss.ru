<?php

namespace App\Services\Partner;

use App\Exceptions\PartnerQuoteConflictException;
use App\Models\Partner;
use App\Models\PartnerCheckoutQuote;
use App\Models\PartnerOrder;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Services\OrderCalculationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PartnerCheckoutQuoteService
{
    public function __construct(
        private readonly OrderCalculationService $calculation,
        private readonly PartnerDeliveryService $delivery,
        private readonly PartnerStockAvailabilityService $availability,
        private readonly PartnerSignatureCanonicalizer $canonicalizer,
    ) {}

    public function create(Partner $partner, array $requestedItems, array $delivery): array
    {
        $requestPayload = ['items' => $requestedItems, 'delivery' => $delivery];
        $snapshot = $this->snapshot($requestedItems, $delivery);
        $expiresAt = now()->addMinutes(max(1, (int) config('partners.quote_ttl_minutes', 15)));
        $quote = PartnerCheckoutQuote::create([
            'public_id' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'request_hash' => $this->hash($requestPayload),
            'request_payload' => $requestPayload,
            'snapshot' => $snapshot,
            'expires_at' => $expiresAt,
        ]);

        Log::info('Partner checkout quote calculated', [
            'partner_id' => $partner->id,
            'quote_id' => $quote->public_id,
            'item_count' => count($snapshot['items']),
            'total' => $snapshot['total'],
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return array_merge(['id' => $quote->public_id], $snapshot, ['expires_at' => $expiresAt->toIso8601String()]);
    }

    public function preview(array $requestedItems, array $delivery): array
    {
        return $this->snapshot($requestedItems, $delivery);
    }

    public function validateForOrder(Partner $partner, string $quoteId, array $items, array $delivery): PartnerCheckoutQuote
    {
        $quote = PartnerCheckoutQuote::query()
            ->where('public_id', $quoteId)
            ->where('partner_id', $partner->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($quote->expires_at->isPast()) {
            throw new PartnerQuoteConflictException('quote_expired');
        }
        if ($quote->consumed_at) {
            throw new PartnerQuoteConflictException('quote_already_used');
        }
        if (! hash_equals($quote->request_hash, $this->hash(['items' => $items, 'delivery' => $delivery]))) {
            throw new PartnerQuoteConflictException('quote_payload_mismatch');
        }

        try {
            $current = $this->snapshot($items, $delivery);
        } catch (UnprocessableEntityHttpException $exception) {
            Log::warning('Partner checkout quote stock changed', [
                'partner_id' => $partner->id,
                'quote_id' => $quote->public_id,
            ]);
            throw new PartnerQuoteConflictException('quote_stock_changed');
        }

        if ($this->commercialHash($quote->snapshot) !== $this->commercialHash($current)) {
            Log::warning('Partner checkout quote commercial terms changed', [
                'partner_id' => $partner->id,
                'quote_id' => $quote->public_id,
                'old_total' => $quote->snapshot['total'] ?? null,
                'current_total' => $current['total'] ?? null,
            ]);
            throw new PartnerQuoteConflictException('quote_terms_changed', $current);
        }

        return $quote;
    }

    public function consume(PartnerCheckoutQuote $quote, PartnerOrder $order): void
    {
        $quote->update(['consumed_at' => now(), 'consumed_by_partner_order_id' => $order->id]);
    }

    public function releaseExpired(): int
    {
        return PartnerCheckoutQuote::query()
            ->where('expires_at', '<', now()->subDay())
            ->whereNull('consumed_at')
            ->delete();
    }

    private function snapshot(array $requestedItems, array $delivery): array
    {
        $items = $this->buildItems($requestedItems);
        $subtotal = round(array_sum(array_column($items, 'total')), 2);
        $discountAmount = round(array_sum(array_map(
            fn (array $item): float => max(0, ($item['base_price'] - $item['final_price']) * $item['quantity']),
            $items,
        )), 2);
        $deliveryQuote = $this->delivery->calculate($delivery, $items, $subtotal);

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'delivery_amount' => $deliveryQuote['amount'],
            'total' => round($subtotal + $deliveryQuote['amount'], 2),
            'currency' => 'RUB',
            'delivery' => [
                'method_id' => $deliveryQuote['method']->id,
                'method' => $deliveryQuote['method']->name,
                'selected' => $deliveryQuote['metadata'],
                'available_tariffs' => $deliveryQuote['available_tariffs'] ?? [],
                'period_min' => $deliveryQuote['metadata']['period_min'] ?? null,
                'period_max' => $deliveryQuote['metadata']['period_max'] ?? null,
            ],
        ];
    }

    private function buildItems(array $requestedItems): array
    {
        $items = [];
        foreach ($requestedItems as $requestedItem) {
            $good = ShopGood::query()->whereKey($requestedItem['good_id'])->where('is_active', true)->first();
            if (! $good) {
                throw new UnprocessableEntityHttpException('Product is unavailable: '.$requestedItem['good_id']);
            }
            $variation = empty($requestedItem['variation_id']) ? null : ShopGoodVariation::query()
                ->whereKey($requestedItem['variation_id'])->where('good_id', $good->id)->where('is_active', true)->first();
            if (! empty($requestedItem['variation_id']) && ! $variation) {
                throw new UnprocessableEntityHttpException('Product variation is unavailable: '.$requestedItem['variation_id']);
            }
            $quantity = (int) $requestedItem['quantity'];
            $available = $this->availability->quantity($good, $variation);
            if ($available < $quantity) {
                throw new UnprocessableEntityHttpException('Insufficient stock for product: '.$good->id);
            }
            $price = $this->calculation->calculateFinalUnitPrice($good, $variation);
            $unitPrice = round((float) $price['final_price'], 2);
            $items[] = [
                'good_id' => $good->id, 'good_name' => $good->name, 'good_sku' => $good->sku,
                'variation_id' => $variation?->id, 'variation_name' => $variation?->display_name,
                'variation_sku' => $variation?->sku, 'quantity' => $quantity,
                'available_quantity' => $available, 'is_available' => true,
                'price' => $unitPrice, 'base_price' => round((float) $price['base_price'], 2),
                'sale_price' => round((float) $price['sale_price'], 2), 'final_price' => $unitPrice,
                'discounts' => $price['discounts'], 'total' => round($unitPrice * $quantity, 2), 'currency' => 'RUB',
            ];
        }
        return $items;
    }

    private function hash(array $payload): string
    {
        return hash('sha256', $this->canonicalizer->payload($payload));
    }

    private function commercialHash(array $snapshot): string
    {
        return $this->hash([
            'items' => array_map(fn (array $item): array => [
                'good_id' => $item['good_id'], 'variation_id' => $item['variation_id'],
                'quantity' => $item['quantity'], 'available_quantity' => $item['available_quantity'],
                'final_price' => $item['final_price'], 'total' => $item['total'],
            ], $snapshot['items'] ?? []),
            'delivery_amount' => $snapshot['delivery_amount'] ?? null,
            'total' => $snapshot['total'] ?? null,
        ]);
    }
}
