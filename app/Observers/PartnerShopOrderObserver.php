<?php

namespace App\Observers;

use App\Models\PartnerOrder;
use App\Models\ShopOrder;
use App\Services\Partner\PartnerWebhookService;
use App\Services\StockReservationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PartnerShopOrderObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(ShopOrder $shopOrder): void
    {
        if (($shopOrder->metadata['source'] ?? null) !== 'partner_api') {
            return;
        }

        if (! $shopOrder->wasChanged(['status_id', 'payment_status_id', 'delivery_status_id', 'payed'])) {
            return;
        }

        $partnerOrder = PartnerOrder::query()->where('shop_order_id', $shopOrder->id)->first();
        if (! $partnerOrder) {
            return;
        }

        $shopOrder->loadMissing(['status', 'paymentStatus', 'deliveryStatus']);
        $nextStatus = $this->resolveStatus($shopOrder);
        $events = ['order.updated']; // Legacy v1 event remains for backward compatibility.
        if ($shopOrder->wasChanged('status_id')) {
            $events[] = $nextStatus === 'cancelled' ? 'order.cancelled' : 'order.status_changed';
        }
        if ($shopOrder->wasChanged(['payment_status_id', 'payed'])) {
            $events[] = $this->paymentEvent($shopOrder);
        }
        if ($shopOrder->wasChanged('delivery_status_id')) {
            $events[] = 'delivery.status_changed';
        }

        DB::transaction(function () use ($partnerOrder, $shopOrder, $nextStatus, $events): void {
            $previousStatus = $partnerOrder->status;
            $updates = ['status' => $nextStatus];

            if ($nextStatus === 'completed') {
                app(StockReservationService::class)->commitForOrder($shopOrder);
                $updates['commission_status'] = 'recognized';
                $partnerOrder->commissions()->where('status', 'pending')->update([
                    'status' => 'recognized',
                    'recognized_at' => now(),
                    'reason' => 'Store order completed',
                ]);
            } elseif ($nextStatus === 'cancelled') {
                app(StockReservationService::class)->releaseForOrder($shopOrder);
                $updates['commission_status'] = 'cancelled';
                $partnerOrder->commissions()->whereIn('status', ['pending', 'recognized'])->update([
                    'status' => 'cancelled',
                    'reason' => 'Store order cancelled',
                ]);
            }

            $partnerOrder->update($updates);
            $partnerOrder->setRelation('shopOrder', $shopOrder);

            Log::info('Partner order synchronized from store order', [
                'partner_order_id' => $partnerOrder->id,
                'shop_order_id' => $shopOrder->id,
                'previous_status' => $previousStatus,
                'status' => $nextStatus,
                'store_status_id' => $shopOrder->status_id,
                'payment_status_id' => $shopOrder->payment_status_id,
                'delivery_status_id' => $shopOrder->delivery_status_id,
            ]);

            foreach (array_unique($events) as $event) {
                app(PartnerWebhookService::class)->queueOrderEvent($partnerOrder, $event);
            }
            if (in_array($nextStatus, ['completed', 'cancelled'], true)) {
                $commissionEvent = $nextStatus === 'completed' ? 'commission.approved' : 'commission.reversed';
                $partnerOrder->commissions()->get()->each(
                    fn ($commission) => app(PartnerWebhookService::class)->queueCommissionEvent($commission, $commissionEvent),
                );
            }
        });
    }

    private function resolveStatus(ShopOrder $order): string
    {
        if ($order->status?->is_cancelled) {
            return 'cancelled';
        }

        if ($order->status?->is_finished) {
            return 'completed';
        }

        if ($order->payed || $this->statusContains($order->paymentStatus?->name, ['paid', 'оплачен'])) {
            return 'paid';
        }

        if ($order->status?->is_taken_to_work) {
            return 'processing';
        }

        return 'created';
    }

    private function statusContains(?string $status, array $needles): bool
    {
        $status = mb_strtolower(trim((string) $status));

        return collect($needles)->contains(fn (string $needle) => str_contains($status, $needle));
    }

    private function paymentEvent(ShopOrder $order): string
    {
        if ($order->payed || $this->statusContains($order->paymentStatus?->name, ['paid', 'оплачен', 'успеш'])) {
            return 'payment.succeeded';
        }
        if ($this->statusContains($order->paymentStatus?->name, ['failed', 'ошиб', 'отклон', 'неуспеш'])) {
            return 'payment.failed';
        }

        return 'payment.status_changed';
    }
}
