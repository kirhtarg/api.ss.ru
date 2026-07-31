<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use App\Models\PartnerOrder;
use App\Models\ShopDeliveryMethod;
use App\Services\Partner\PartnerPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(private readonly PartnerPaymentService $payments) {}

    public function options(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'delivery_methods' => ShopDeliveryMethod::query()->active()->ordered()
                ->get(['id', 'name', 'type', 'description', 'cost', 'free_from', 'is_default']),
            'payment_methods' => $this->payments->options(),
        ]]);
    }

    public function payment(Request $request, string $externalOrderId): JsonResponse
    {
        $payload = $request->validate(['payment_method_id' => ['nullable', 'integer']]);
        $partnerOrder = PartnerOrder::query()->with('shopOrder')
            ->where('partner_id', $request->attributes->get('partner')->id)
            ->where('external_order_id', $externalOrderId)->firstOrFail();
        $payment = $this->payments->create($partnerOrder->shopOrder, $payload['payment_method_id'] ?? null);

        return response()->json(['success' => true, 'data' => [
            'status' => $payment['status'],
            'method' => ['id' => $payment['method']->id, 'name' => $payment['method']->name, 'type' => $payment['method']->type],
            'payment_url' => $payment['payment_url'],
            'transaction_id' => $payment['transaction_id'],
        ]], 201);
    }
}
