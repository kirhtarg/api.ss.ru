<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopCarrierDeliverySettings;
use App\Services\OzonDeliveryService;
use App\Services\ShopDeliveryActivitySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopOzonDeliveryController extends Controller
{
    public function pickupPoints(Request $request, OzonDeliveryService $ozon): JsonResponse
    {
        $validated = $request->validate([
            'city' => 'required|string|min:2|max:255',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'zoom' => 'nullable|integer|between:1,20',
        ]);
        try {
            if (isset($validated['latitude'], $validated['longitude'])) {
                $lat = (float) $validated['latitude']; $lon = (float) $validated['longitude'];
                $zoom = (int) ($validated['zoom'] ?? 12);
                $delta = $zoom >= 14 ? 0.12 : 0.25;
                return response()->json(['success' => true, 'data' => $ozon->getPickupPointsByViewport([
                    'left_bottom' => ['lat' => $lat - $delta, 'long' => $lon - $delta],
                    'right_top' => ['lat' => $lat + $delta, 'long' => $lon + $delta],
                ], $zoom)]);
            }
            return response()->json(['success' => true, 'data' => $ozon->getPickupPoints($validated['city'])]);
        } catch (Throwable $e) {
            Log::warning('Ozon Delivery pickup points request failed', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Не удалось загрузить пункты выдачи Ozon: '.$e->getMessage(), 'data' => []], 422);
        }
    }

    public function checkout(Request $request, OzonDeliveryService $ozon): JsonResponse
    {
        $validated = $request->validate([
            'delivery_point_id' => 'required|integer|min:1',
            'shipment_method_id' => 'required|integer|min:1',
            'phone' => 'required|string|max:40',
            'subtotal' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1|max:100',
            'items.*.good_id' => 'required|integer|min:1',
            'items.*.variation_id' => 'nullable|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'items.*.price' => 'nullable|numeric|min:0',
            'items.*.final_price' => 'nullable|numeric|min:0',
        ]);

        try {
            $settings = app(ShopDeliveryActivitySyncService::class)->getMethodActive('ozon') === false
                ? null
                : ShopCarrierDeliverySettings::getActive('ozon');
            if (! $settings) {
                return response()->json(['success' => false, 'message' => 'Ozon Доставка сейчас недоступна'], 422);
            }

            return response()->json(['success' => true, 'data' => $ozon->calculateCheckout($settings, $validated)]);
        } catch (Throwable $e) {
            Log::warning('Ozon Delivery checkout calculation failed', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Не удалось рассчитать доставку Ozon: '.$e->getMessage()], 422);
        }
    }
}
