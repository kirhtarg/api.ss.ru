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
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => $this->getFullName($user),
                'email' => $user->email,
                'phone' => $user->phone,
                'birthday' => $user->birthday?->format('Y-m-d'),
                'avatar_url' => $user->avatar_url,
                'is_active' => $user->is_active,
                'role' => $roles[0] ?? 'admin', // Берем первую роль
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

    /**
     * Получить полное имя пользователя
     */
    private function getFullName($user): string
    {
        $firstName = $user->first_name ?? '';
        $lastName = $user->last_name ?? '';
        
        if ($firstName && $lastName) {
            return trim($firstName . ' ' . $lastName);
        }
        
        return $firstName ?: $user->name ?: 'Пользователь';
    }
}
