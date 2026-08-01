<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use App\Services\Partner\PartnerDeliveryService;
use App\Services\Partner\PartnerCheckoutQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function __construct(
        private readonly PartnerDeliveryService $delivery,
        private readonly PartnerCheckoutQuoteService $quotes,
    ) {}

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        return response()->json(['success' => true, 'data' => $this->delivery->cities($data['q'])]);
    }

    public function pickupPoints(Request $request): JsonResponse
    {
        $data = $request->validate(['city_code' => ['required', 'integer', 'min:1']]);

        return response()->json(['success' => true, 'data' => $this->delivery->pickupPoints((int) $data['city_code'])]);
    }

    public function validatePickupPoint(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_code' => ['required', 'integer', 'min:1'],
            'pvz_code' => ['required', 'string', 'max:100'],
        ]);
        $point = $this->delivery->validatePickupPoint((int) $data['city_code'], $data['pvz_code']);

        return response()->json(['success' => true, 'data' => ['is_valid' => $point !== null, 'pickup_point' => $point]]);
    }

    public function tariffs(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.good_id' => ['required', 'integer'],
            'items.*.variation_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'delivery' => ['required', 'array'],
            'delivery.method_id' => ['nullable', 'integer'],
            'delivery.city_code' => ['required', 'integer', 'min:1'],
        ]);
        $quote = $this->quotes->preview($payload['items'], $payload['delivery']);

        return response()->json(['success' => true, 'data' => $quote['delivery']['available_tariffs']]);
    }
}
