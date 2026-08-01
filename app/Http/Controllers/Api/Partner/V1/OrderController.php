<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Exceptions\PartnerQuoteConflictException;
use App\Http\Controllers\Controller;
use App\Models\PartnerOrder;
use App\Services\Partner\PartnerOrderService;
use App\Services\Partner\PartnerPaymentService;
use App\Services\Partner\PartnerSyncCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly PartnerOrderService $orders,
        private readonly PartnerPaymentService $payments,
        private readonly PartnerSyncCursor $cursor,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'invalid_idempotency_key', 'message' => 'A valid Idempotency-Key header is required'],
            ], 422);
        }

        $payload = $request->validate([
            'external_order_id' => ['required', 'string', 'max:128'],
            'quote_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.good_id' => ['required', 'integer'],
            'items.*.variation_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:20'],
            'delivery' => ['nullable', 'array'],
            'delivery.method_id' => ['nullable', 'integer'],
            'delivery.address' => ['nullable', 'string', 'max:2000'],
            'delivery.city_code' => ['nullable', 'integer', 'min:1'],
            'delivery.tariff_code' => ['nullable', 'string', 'max:30'],
            'delivery.pvz_code' => ['nullable', 'string', 'max:100'],
            'payment_method_id' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string', 'max:4000'],
            'customer_reference' => ['nullable', 'array'],
            'attribution' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $result = $this->orders->create(
                $request->attributes->get('partner'),
                $payload,
                $idempotencyKey,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (PartnerQuoteConflictException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => 'Checkout quote is no longer valid',
                    'current_quote' => $exception->currentQuote,
                ],
            ], 409);
        }
        $payment = null;
        if (! $result['replayed'] && array_key_exists('payment_method_id', $payload)) {
            $payment = $this->payments->create(
                $request->attributes->get('partner'),
                $result['order'],
                $payload['payment_method_id'],
                'order-create:'.hash('sha256', $idempotencyKey),
            );
        } elseif ($result['replayed']) {
            $payment = $this->payments->latestState($result['order']);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($this->serialize($result['order']), ['payment' => $payment ? [
                'status' => $payment['status'],
                'method' => $payment['method'],
                'payment_url' => $payment['payment_url'],
                'transaction_id' => $payment['transaction_id'],
                'error' => $payment['error'] ?? null,
            ] : null]),
            'meta' => ['idempotent_replay' => $result['replayed']],
        ], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, string $externalOrderId): JsonResponse
    {
        $order = PartnerOrder::query()
            ->with('shopOrder')
            ->where('partner_id', $request->attributes->get('partner')->id)
            ->where('external_order_id', $externalOrderId)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->serialize($order)]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PartnerOrder::query()
            ->where('partner_id', $request->attributes->get('partner')->id)
            ->with(['shopOrder.status', 'shopOrder.paymentStatus', 'shopOrder.deliveryStatus']);
        $page = $this->cursor->apply($query, $filters['cursor'] ?? null, $filters['updated_since'] ?? null)
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (PartnerOrder $order) => $this->serialize($order))->values(),
            'meta' => [
                'next_cursor' => $page->hasMorePages() ? $this->cursor->encode($page->getCollection()->last()) : null,
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
                'sort' => ['updated_at', 'id'],
            ],
        ]);
    }

    public function cancel(Request $request, string $externalOrderId): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $order = $this->orders->cancel(
            $request->attributes->get('partner'),
            $externalOrderId,
            (string) $request->input('reason'),
        );

        return response()->json(['success' => true, 'data' => $this->serialize($order)]);
    }

    private function serialize(PartnerOrder $order): array
    {
        return [
            'id' => $order->public_id,
            'external_order_id' => $order->external_order_id,
            'order_number' => $order->shopOrder?->order_number,
            'status' => $order->status,
            'currency' => $order->currency,
            'items_amount' => (float) $order->items_amount,
            'delivery_amount' => (float) $order->delivery_amount,
            'total_amount' => (float) $order->total_amount,
            'delivery' => [
                'method_id' => $order->shopOrder?->shipping_method_id,
                'method' => $order->shopOrder?->shipping_method,
                'address' => $order->shopOrder?->shipping_address,
                'details' => $order->shopOrder?->metadata['delivery'] ?? null,
            ],
            'payment' => [
                'method_id' => $order->shopOrder?->payment_method_id,
                'method' => $order->shopOrder?->payment_method,
                'payment_url' => $order->shopOrder?->payment_url,
                'status' => $order->shopOrder?->paymentStatus?->name,
            ],
            'store_status' => [
                'order' => $order->shopOrder?->status?->name,
                'payment' => $order->shopOrder?->paymentStatus?->name,
                'delivery' => $order->shopOrder?->deliveryStatus?->name,
            ],
            'commission' => [
                'rate' => (float) $order->commission_rate,
                'amount' => (float) $order->commission_amount,
                'status' => $order->commission_status,
            ],
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }
}
