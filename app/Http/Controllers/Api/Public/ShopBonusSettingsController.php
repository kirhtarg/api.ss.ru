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
     * Возвращает массив всех настроек с image_url (как админский контроллер)
     */
    public function getActive(): JsonResponse
    {
        try {
            // Получаем все настройки, отсортированные по дате создания
            $settings = ShopBonusSettings::orderBy('created_at', 'desc')->get();

            // Добавляем image_url к каждой настройке, если файл существует
            $settings = $settings->map(function ($setting) {
                $imageUrl = '/images/bsys/bsys_'.$setting->id.'.jpg';
                $imagePath = frontend_public_path(ltrim($imageUrl, '/'));

                if (file_exists($imagePath)) {
                    $setting->image_url = $imageUrl;
                } else {
                    $setting->image_url = null;
                }

                return $setting;
            });

            return response()->json([
                'success' => true,
                'data' => $settings,
            ]);

        } catch (\Exception $e) {
            Log::error('Public ShopBonusSettingsController: Error getting active settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек бонусов',
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

            if (! $settings) {
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
                    'settings' => $settings,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Public ShopBonusSettingsController: Error calculating bonus', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка расчета бонусов',
            ], 500);
        }
    }
}
