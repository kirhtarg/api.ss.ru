<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ContactAddress;
use App\Models\ShopDeliveryMethod;
use Illuminate\Http\JsonResponse;

class ShopDeliveryController extends Controller
{
    /**
     * Получить список активных способов доставки
     */
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $deliveryMethods = ShopDeliveryMethod::active()
                ->ordered()
                ->get();

            $orderWeight = (float) $request->query('weight', 0);
            if ($orderWeight > 0) {
                $deliveryMethods = $deliveryMethods->reject(function ($method) use ($orderWeight) {
                    $maxWeight = (float) ($method->settings['max_weight_kg'] ?? 0);
                    return $maxWeight > 0 && $orderWeight > $maxWeight;
                })->values();
            }

            // Проверяем наличие адресов для самовывоза
            $hasPickupAddresses = ContactAddress::where('is_delivery', true)->exists();

            // Фильтруем способы доставки: если нет адресов для самовывоза, скрываем самовывоз
            if (! $hasPickupAddresses) {
                $deliveryMethods = $deliveryMethods->filter(function ($method) {
                    // Проверяем по типу и названию
                    $isPickup = $method->type === 'pickup' ||
                                stripos($method->name, 'самовывоз') !== false ||
                                stripos($method->name, 'pickup') !== false;

                    return ! $isPickup;
                })->values();
            }

            return response()->json([
                'success' => true,
                'data' => $deliveryMethods,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения способов доставки',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
