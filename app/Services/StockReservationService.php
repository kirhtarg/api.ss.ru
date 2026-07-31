<?php

namespace App\Services;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopStock;
use App\Models\ShopStockReservation;
use App\Models\ShopWarehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class StockReservationService
{
    public function reserveForOrder(ShopOrder $order, array $items, int $ttlMinutes = 30): array
    {
        $this->releaseExpired();
        $reservationIds = [];

        Log::info('Stock reservation started', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'item_count' => count($items),
            'ttl_minutes' => $ttlMinutes,
        ]);

        foreach ($items as $item) {
            $quantityRemaining = (int) $item['quantity'];
            $stocks = ShopStock::query()
                ->where('good_id', $item['good_id'])
                ->when(
                    $item['variation_id'] ?? null,
                    fn ($query, $variationId) => $query->where('variation_id', $variationId),
                    fn ($query) => $query->whereNull('variation_id'),
                )
                ->orderBy('warehouse_id')
                ->lockForUpdate()
                ->get();

            if ($stocks->isEmpty()) {
                $stocks = collect([$this->initializeStock($item)]);
            }

            foreach ($stocks as $stock) {
                $available = max(0, $stock->quantity - $stock->reserved_quantity);
                $reserved = min($available, $quantityRemaining);
                if ($reserved <= 0) {
                    continue;
                }

                $stock->increment('reserved_quantity', $reserved);
                $reservation = ShopStockReservation::create([
                    'good_id' => $item['good_id'],
                    'variation_id' => $item['variation_id'] ?? null,
                    'warehouse_id' => $stock->warehouse_id,
                    'quantity' => $reserved,
                    'reserved_until' => now()->addMinutes(max(1, $ttlMinutes)),
                    'reservation_type' => 'order',
                    'reference_id' => (string) $order->id,
                    'notes' => 'Partner API order '.$order->order_number,
                ]);
                $reservationIds[] = $reservation->id;
                $quantityRemaining -= $reserved;

                if ($quantityRemaining === 0) {
                    break;
                }
            }

            if ($quantityRemaining > 0) {
                throw new UnprocessableEntityHttpException('Insufficient available stock for product: '.$item['good_id']);
            }
        }

        Log::info('Stock reservation completed', [
            'order_id' => $order->id,
            'reservation_ids' => $reservationIds,
        ]);

        return $reservationIds;
    }

    public function releaseForOrder(ShopOrder $order): int
    {
        return DB::transaction(
            fn (): int => $this->releaseReference((string) $order->id, false),
            3,
        );
    }

    public function commitForOrder(ShopOrder $order): int
    {
        return DB::transaction(
            fn (): int => $this->releaseReference((string) $order->id, true),
            3,
        );
    }

    public function releaseExpired(): int
    {
        return DB::transaction(function (): int {
            $references = ShopStockReservation::query()
                ->expired()
                ->where('reservation_type', 'order')
                ->where('notes', 'like', 'Partner API order %')
                ->lockForUpdate()
                ->distinct()
                ->pluck('reference_id');
            $released = 0;

            foreach ($references as $reference) {
                $released += $this->releaseReference((string) $reference, false);
            }

            return $released;
        }, 3);
    }

    private function releaseReference(string $referenceId, bool $commit): int
    {
        $reservations = ShopStockReservation::query()
            ->where('reservation_type', 'order')
            ->where('notes', 'like', 'Partner API order %')
            ->where('reference_id', $referenceId)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $stock = ShopStock::query()
                ->where('good_id', $reservation->good_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->when(
                    $reservation->variation_id,
                    fn ($query, $variationId) => $query->where('variation_id', $variationId),
                    fn ($query) => $query->whereNull('variation_id'),
                )
                ->lockForUpdate()
                ->first();

            if ($stock) {
                $stock->update([
                    'quantity' => $commit ? max(0, $stock->quantity - $reservation->quantity) : $stock->quantity,
                    'reserved_quantity' => max(0, $stock->reserved_quantity - $reservation->quantity),
                ]);
            }

            if ($commit) {
                $stockModel = $reservation->variation_id
                    ? ShopGoodVariation::query()->lockForUpdate()->find($reservation->variation_id)
                    : ShopGood::query()->lockForUpdate()->find($reservation->good_id);
                if ($stockModel) {
                    $stockModel->update(['stock_quantity' => max(0, $stockModel->stock_quantity - $reservation->quantity)]);
                }
            }

            $reservation->delete();
        }

        if ($reservations->isNotEmpty()) {
            Log::info($commit ? 'Stock reservation committed' : 'Stock reservation released', [
                'reference_id' => $referenceId,
                'reservation_count' => $reservations->count(),
            ]);
        }

        return $reservations->count();
    }

    private function initializeStock(array $item): ShopStock
    {
        $warehouse = ShopWarehouse::query()->active()->default()->first()
            ?? ShopWarehouse::query()->active()->ordered()->first();
        if (! $warehouse) {
            throw new UnprocessableEntityHttpException('No active warehouse is configured for stock reservation');
        }

        $stockQuantity = $item['variation_id']
            ? ShopGoodVariation::query()->lockForUpdate()->findOrFail($item['variation_id'])->stock_quantity
            : ShopGood::query()->lockForUpdate()->findOrFail($item['good_id'])->stock_quantity;

        return ShopStock::create([
            'good_id' => $item['good_id'],
            'variation_id' => $item['variation_id'] ?? null,
            'warehouse_id' => $warehouse->id,
            'quantity' => max(0, (int) $stockQuantity),
            'reserved_quantity' => 0,
        ]);
    }
}
