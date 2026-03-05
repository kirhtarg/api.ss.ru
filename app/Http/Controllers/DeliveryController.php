<?php

namespace App\Http\Controllers;

use App\Models\ShopCdekSettings;

class DeliveryController extends Controller
{
    /**
     * Получение публичных настроек СДЭК (AJAX, для витрины).
     */
    public function getCdekSettings()
    {
        try {
            // Получаем активные настройки СДЭК; если нет активных — используем первую запись как фолбэк
            $settings = ShopCdekSettings::getActive() ?? ShopCdekSettings::first();

            if (! $settings) {
                return response()->json([
                    'success' => false,
                    'message' => 'Активные настройки СДЭК не найдены',
                ], 404);
            }

            // Возвращаем настройки, включая наш новый параметр cash_on_delivery_enabled
            return response()->json([
                'success' => true,
                'data' => [
                    'cash_on_delivery_enabled' => $settings->cash_on_delivery_enabled ?? false,
                    'customer_pays_delivery' => $settings->customer_pays_delivery ?? false,
                    'disable_order_creation' => $settings->disable_order_creation ?? false,
                    // Можно добавить другие публичные настройки в будущем
                ],
            ]);

        } catch (\Throwable $e) {
            \Log::error('Error getting CDEK settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении настроек СДЭК',
            ], 500);
        }
    }
}
