<?php

namespace App\Services\Partner;

use App\Jobs\DeliverPartnerWebhookJob;
use App\Models\Partner;
use App\Models\PartnerOrder;
use App\Models\PartnerWebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PartnerWebhookService
{
    public function queueTestEvent(Partner $partner): PartnerWebhookDelivery
    {
        $delivery = PartnerWebhookDelivery::create([
            'public_id' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'event' => 'test',
            'payload' => [
                'id' => (string) Str::uuid(),
                'event' => 'test',
                'created_at' => now()->toIso8601String(),
                'data' => ['message' => 'Partner API webhook connection test'],
            ],
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);

        Log::info('Partner test webhook queued', [
            'partner_id' => $partner->id,
            'delivery_id' => $delivery->public_id,
        ]);
        DB::afterCommit(fn () => DeliverPartnerWebhookJob::dispatch($delivery->id));

        return $delivery;
    }

    public function queueOrderEvent(PartnerOrder $order, string $event): ?PartnerWebhookDelivery
    {
        $order->loadMissing(['partner', 'shopOrder.status', 'shopOrder.paymentStatus', 'shopOrder.deliveryStatus']);
        if (! $order->partner?->webhook_url) {
            Log::debug('Partner webhook skipped because URL is not configured', [
                'partner_id' => $order->partner_id,
                'partner_order_id' => $order->id,
                'event' => $event,
            ]);

            return null;
        }

        $delivery = PartnerWebhookDelivery::create([
            'public_id' => (string) Str::uuid(),
            'partner_id' => $order->partner_id,
            'partner_order_id' => $order->id,
            'event' => $event,
            'payload' => $this->orderPayload($order, $event),
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);

        Log::info('Partner webhook queued', [
            'partner_id' => $order->partner_id,
            'partner_order_id' => $order->id,
            'delivery_id' => $delivery->public_id,
            'event' => $event,
        ]);

        DB::afterCommit(fn () => DeliverPartnerWebhookJob::dispatch($delivery->id));

        return $delivery;
    }

    private function orderPayload(PartnerOrder $order, string $event): array
    {
        return [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => [
                'order' => [
                    'id' => $order->public_id,
                    'external_order_id' => $order->external_order_id,
                    'order_number' => $order->shopOrder?->order_number,
                    'status' => $order->status,
                    'currency' => $order->currency,
                    'items_amount' => (float) $order->items_amount,
                    'delivery_amount' => (float) $order->delivery_amount,
                    'total_amount' => (float) $order->total_amount,
                    'commission' => [
                        'rate' => (float) $order->commission_rate,
                        'amount' => (float) $order->commission_amount,
                        'status' => $order->commission_status,
                    ],
                    'store_status' => [
                        'order' => $order->shopOrder?->status?->name,
                        'payment' => $order->shopOrder?->paymentStatus?->name,
                        'delivery' => $order->shopOrder?->deliveryStatus?->name,
                    ],
                    'updated_at' => $order->updated_at?->toIso8601String(),
                ],
            ],
        ];
    }
}
