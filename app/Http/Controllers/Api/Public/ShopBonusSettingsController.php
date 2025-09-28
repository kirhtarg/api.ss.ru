<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopBonusSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopBonusSettingsController extends Controller
{
    /**
     * Получить активные настройки бонусов
     */
    public function getActive(): JsonResponse
    {
        try {
            $settings = ShopBonusSettings::getActiveSettings();

            if (!$settings) {
                // Создаем настройки по умолчанию
                $settings = ShopBonusSettings::getDefaultSettings();
            }

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);

        } catch (\Exception $e) {
            Log::error('Public ShopBonusSettingsController: Error getting active settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек бонусов'
            ], 500);
        }
    }

    /**
     * Рассчитать бонусы для заказа
     */
    public function calculateBonus(Request $request): JsonResponse
    {
        try {
            $orderAmount = $request->input('order_amount', 0);
            $isSalePrice = $request->input('is_sale_price', false);

            $settings = ShopBonusSettings::getActiveSettings();
            
            if (!$settings) {
                $settings = ShopBonusSettings::getDefaultSettings();
            }

            $bonusAmount = $settings->calculateOrderBonus($orderAmount, $isSalePrice);
            $maxUsage = $settings->calculateMaxBonusUsage($orderAmount);

            return response()->json([
                'success' => true,
                'data' => [
                    'bonus_amount' => $bonusAmount,
                    'max_usage' => $maxUsage,
                    'can_use_bonuses' => $settings->canUseBonuses($orderAmount, $maxUsage),
                    'settings' => $settings
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Public ShopBonusSettingsController: Error calculating bonus', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка расчета бонусов'
            ], 500);
        }
    }
}
