<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Получить профиль текущего администратора
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            // Получаем роли пользователя
            $roles = $user->roles->pluck('name')->toArray();
            
            // Формируем данные профиля
            $profileData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $roles[0] ?? 'admin', // Берем первую роль
                'avatar' => $user->avatar ? Storage::url($user->avatar) : null, // Возвращаем avatar (полный URL)
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at
            ];
            
            return response()->json([
                'success' => true,
                'data' => $profileData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения профиля: ' . $e->getMessage()
            ], 500);
        }
    }
}
