<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopBonusSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopBonusSettingsController extends Controller
{
    /**
     * Получить все настройки бонусов
     */
    public function index(): JsonResponse
    {
        try {
            $settings = ShopBonusSettings::orderBy('created_at', 'desc')->get();

            // Add image_url to each setting if file exists
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
            Log::error('ShopBonusSettingsController: Error getting settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек бонусов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить конкретную настройку
     */
    public function show($id): JsonResponse
    {
        try {
            $setting = ShopBonusSettings::findOrFail($id);

            // Add image_url if file exists
            $imageUrl = '/images/bsys/bsys_'.$setting->id.'.jpg';
            $imagePath = frontend_public_path(ltrim($imageUrl, '/'));

            if (file_exists($imagePath)) {
                $setting->image_url = $imageUrl;
            } else {
                $setting->image_url = null;
            }

            return response()->json([
                'success' => true,
                'data' => $setting,
            ]);

        } catch (\Exception $e) {
            Log::error('ShopBonusSettingsController: Error getting setting', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Настройка не найдена',
            ], 404);
        }
    }

    /**
     * Создать новую настройку
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:shop_bonus_settings,name',
                'regular_price_percentage' => 'required|numeric|min:0|max:100',
                'sale_price_percentage' => 'required|numeric|min:0|max:100',
                'max_usage_percentage' => 'required|numeric|min:0|max:100',
                'is_active' => 'boolean',
                'min_order_amount' => 'integer|min:0',
                'min_purchase_amount' => 'nullable|numeric|min:0', // Accept min_purchase_amount from frontend
                'min_bonus_amount' => 'integer|min:1',
                'max_bonus_amount' => 'nullable|integer|min:1',
                'bonus_expiry_days' => 'integer|min:1|max:3650',
                'metadata' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $setting = ShopBonusSettings::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Настройка бонусов создана',
                'data' => $setting,
            ], 201);

        } catch (\Exception $e) {
            Log::error('ShopBonusSettingsController: Error creating setting', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания настройки: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить настройку
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $setting = ShopBonusSettings::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:shop_bonus_settings,name,'.$id,
                'regular_price_percentage' => 'required|numeric|min:0|max:100',
                'sale_price_percentage' => 'required|numeric|min:0|max:100',
                'max_usage_percentage' => 'required|numeric|min:0|max:100',
                'is_active' => 'boolean',
                'min_order_amount' => 'integer|min:0',
                'min_purchase_amount' => 'nullable|numeric|min:0', // Accept min_purchase_amount from frontend
                'min_bonus_amount' => 'integer|min:1',
                'max_bonus_amount' => 'nullable|integer|min:1',
                'bonus_expiry_days' => 'integer|min:1|max:3650',
                'metadata' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $setting->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Настройка обновлена',
                'data' => $setting,
            ]);

        } catch (\Exception $e) {
            Log::error('ShopBonusSettingsController: Error updating setting', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления настройки: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить настройку
     */
    public function destroy($id): JsonResponse
    {
        try {
            $setting = ShopBonusSettings::findOrFail($id);
            $setting->delete();

            return response()->json([
                'success' => true,
                'message' => 'Настройка удалена',
            ]);

        } catch (\Exception $e) {
            Log::error('ShopBonusSettingsController: Error deleting setting', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления настройки: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Переключить активность настройки
     */
    public function toggleActive($id): JsonResponse
    {
        try {
            $setting = ShopBonusSettings::findOrFail($id);
            $setting->is_active = ! $setting->is_active;
            $setting->save();

            return response()->json([
                'success' => true,
                'message' => $setting->is_active ? 'Настройка активирована' : 'Настройка деактивирована',
                'data' => $setting,
            ]);

        } catch (\Exception $e) {
            Log::error('ShopBonusSettingsController: Error toggling setting', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка переключения настройки: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить активные настройки
     */
    public function getActive(): JsonResponse
    {
        try {
            $setting = ShopBonusSettings::getActiveSettings();

            if (! $setting) {
                // Создаем настройки по умолчанию
                $setting = ShopBonusSettings::getDefaultSettings();
            }

            return response()->json([
                'success' => true,
                'data' => $setting,
            ]);

        } catch (\Exception $e) {
            Log::error('ShopBonusSettingsController: Error getting active setting', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения активных настроек: '.$e->getMessage(),
            ], 500);
        }
    }
}
