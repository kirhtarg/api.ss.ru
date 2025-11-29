<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class UserPreferencesController extends Controller
{
    /**
     * Получить настройки пользователя
     */
    public function getPreferences(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $additionalInfo = $user->additional_info ?? [];
            $preferences = $additionalInfo['goods_table_columns'] ?? null;

            return response()->json([
                'success' => true,
                'data' => [
                    'goods_table_columns' => $preferences
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Сохранить настройки пользователя
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'goods_table_columns' => 'nullable|array',
                'goods_table_columns.*' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $additionalInfo = $user->additional_info ?? [];
            
            if ($request->has('goods_table_columns')) {
                $additionalInfo['goods_table_columns'] = $request->input('goods_table_columns');
            }

            $user->additional_info = $additionalInfo;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Настройки успешно сохранены',
                'data' => [
                    'goods_table_columns' => $additionalInfo['goods_table_columns'] ?? null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка сохранения настроек: ' . $e->getMessage()
            ], 500);
        }
    }
}


