<?php

namespace App\Http\Controllers\Api\Admin\PartnerApi;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverPartnerWebhookJob;
use App\Models\PartnerWebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'status' => ['nullable', Rule::in(['pending', 'processing', 'retrying', 'delivered', 'failed'])],
            'event' => ['nullable', 'string', 'max:80'],
            'response_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        Log::debug('Partner webhook journal requested', ['user_id' => $request->user()?->id, 'filters' => $validated]);
        $query = PartnerWebhookDelivery::query()
            ->with(['partner:id,public_id,code,name', 'partnerOrder:id,public_id,external_order_id,shop_order_id', 'partnerOrder.shopOrder:id,order_number'])
            ->when($validated['partner_id'] ?? null, fn ($builder, $id) => $builder->where('partner_id', $id))
            ->when($validated['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($validated['event'] ?? null, fn ($builder, $event) => $builder->where('event', 'like', "%{$event}%"))
            ->when($validated['response_status'] ?? null, fn ($builder, $status) => $builder->where('response_status', $status))
            ->when($validated['search'] ?? null, fn ($builder, $search) => $builder->where(fn ($nested) => $nested->where('public_id', 'like', "%{$search}%")->orWhere('last_error', 'like', "%{$search}%")))
            ->when($validated['date_from'] ?? null, fn ($builder, $date) => $builder->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($builder, $date) => $builder->whereDate('created_at', '<=', $date));
        $summary = (clone $query)->selectRaw('status, COUNT(*) as deliveries_count')->groupBy('status')->pluck('deliveries_count', 'status');

        return response()->json(['success' => true, 'data' => $query->latest('id')->paginate($validated['per_page'] ?? 25), 'meta' => ['summary' => $summary]]);
    }

    public function show(Request $request, PartnerWebhookDelivery $webhook): JsonResponse
    {
        Log::debug('Partner webhook delivery card requested', ['user_id' => $request->user()?->id, 'webhook_delivery_id' => $webhook->id]);
        $webhook->load(['partner:id,public_id,code,name,webhook_url', 'partnerOrder.shopOrder:id,order_number']);

        return response()->json(['success' => true, 'data' => [
            ...$webhook->toArray(),
            'request_preview' => [
                'method' => 'POST',
                'url' => $webhook->partner?->webhook_url,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Partner-Delivery' => $webhook->public_id,
                    'X-Partner-Event' => $webhook->event,
                    'X-Partner-Timestamp' => '<generated per attempt>',
                    'X-Partner-Signature' => '<redacted>',
                ],
                'body' => $webhook->payload,
            ],
            'response_preview' => [
                'status' => $webhook->response_status,
                'body' => $webhook->response_body,
                'error' => $webhook->last_error,
            ],
        ]]);
    }

    public function retry(Request $request, PartnerWebhookDelivery $webhook): JsonResponse
    {
        DB::transaction(function () use ($request, $webhook): void {
            $delivery = PartnerWebhookDelivery::query()->lockForUpdate()->findOrFail($webhook->id);
            if (in_array($delivery->status, ['pending', 'processing', 'retrying'], true)) {
                throw new ConflictHttpException('Webhook уже ожидает доставки или обрабатывается');
            }
            $previousStatus = $delivery->status;
            $delivery->update([
                'status' => 'pending', 'attempts' => 0, 'response_status' => null, 'response_body' => null,
                'last_error' => null, 'next_attempt_at' => now(), 'delivered_at' => null,
            ]);
            Log::warning('Partner webhook delivery retried manually', [
                'user_id' => $request->user()?->id, 'partner_id' => $delivery->partner_id,
                'webhook_delivery_id' => $delivery->id, 'previous_status' => $previousStatus,
            ]);
            DB::afterCommit(fn () => DeliverPartnerWebhookJob::dispatch($delivery->id));
        });

        return response()->json(['success' => true, 'message' => 'Webhook повторно поставлен в очередь', 'data' => $webhook->fresh()]);
    }
}
