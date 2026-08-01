<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Exceptions\PartnerPaymentConflictException;
use App\Http\Controllers\Controller;
use App\Models\PartnerOrder;
use App\Models\ShopDeliveryMethod;
use App\Services\Partner\PartnerPaymentService;
use App\Services\Partner\PartnerCheckoutQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly PartnerPaymentService $payments,
        private readonly PartnerCheckoutQuoteService $quotes,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'delivery_methods' => ShopDeliveryMethod::query()->active()->ordered()
                ->get(['id', 'name', 'type', 'description', 'cost', 'free_from', 'is_default']),
            'payment_methods' => $this->payments->options(),
        ]]);
    }

    public function quote(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.good_id' => ['required', 'integer'],
            'items.*.variation_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'delivery' => ['nullable', 'array'],
            'delivery.method_id' => ['nullable', 'integer'],
            'delivery.destination' => ['nullable', 'string', 'max:500'],
            'delivery.address' => ['nullable', 'string', 'max:2000'],
            'delivery.city_code' => ['nullable', 'integer', 'min:1'],
            'delivery.tariff_code' => ['nullable', 'string', 'max:30'],
            'delivery.pvz_code' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->quotes->create(
                $request->attributes->get('partner'),
                $payload['items'],
                $payload['delivery'] ?? [],
            ),
        ]);
    }

    public function payment(Request $request, string $externalOrderId): JsonResponse
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'invalid_idempotency_key', 'message' => 'Idempotency-Key must contain 8-128 safe ASCII characters'],
            ], 422);
        }
        $payload = $request->validate(['payment_method_id' => ['nullable', 'integer']]);
        $partner = $request->attributes->get('partner');
        $partnerOrder = PartnerOrder::query()->with('shopOrder')
            ->where('partner_id', $partner->id)
            ->where('external_order_id', $externalOrderId)->firstOrFail();
        try {
            $payment = $this->payments->create($partner, $partnerOrder, $payload['payment_method_id'] ?? null, $idempotencyKey);
        } catch (PartnerPaymentConflictException $exception) {
            return response()->json([
                'success' => false,
                'error' => ['code' => $exception->errorCode, 'message' => 'Payment request conflicts with the current order state'],
            ], 409);
        }

        return response()->json(['success' => true, 'data' => [
            'status' => $payment['status'],
            'method' => $payment['method'],
            'payment_url' => $payment['payment_url'],
            'transaction_id' => $payment['transaction_id'],
            'error' => $payment['error'] ?? null,
        ], 'meta' => ['idempotent_replay' => $payment['idempotent_replay']]], $payment['idempotent_replay'] ? 200 : 201);
    }
}
