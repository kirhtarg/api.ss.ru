<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopSettingsController extends Controller
{
    /**
     * Получение настроек магазина
     */
    public function getShopSettings()
    {
        try {
            $settings = DB::table('settings')
                ->where('group', 'shop')
                ->pluck('value', 'key')
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка получения настроек магазина: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек'
            ], 500);
        }
    }

    /**
     * Получение конкретной настройки магазина
     */
    public function getShopSetting($key)
    {
        try {
            $setting = DB::table('settings')
                ->where('group', 'shop')
                ->where('key', $key)
                ->value('value');

            if ($setting === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройка не найдена'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $key,
                    'value' => $setting
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка получения настройки магазина: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настройки'
            ], 500);
        }
    }
}
