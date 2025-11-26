<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * Получить профиль текущего администратора
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Используем $request->user() вместо Auth::user() для более надежной работы
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            // Получаем роли пользователя - используем прямой запрос для надежности
            $roles = [];
            try {
                // Сначала пытаемся прямой запрос (более надежно)
                $roles = DB::table('user_roles')
                    ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                    ->where('user_roles.user_id', $user->id)
                    ->where(function($query) {
                        $query->where('user_roles.is_active', '=', 1)
                              ->orWhere('user_roles.is_active', '=', true);
                    })
                    ->pluck('roles.name')
                    ->toArray();
                    
                // Если прямой запрос не вернул роли, пробуем через связь
                if (empty($roles)) {
                    try {
                        $user->load('roles');
                        if ($user->roles && $user->roles->count() > 0) {
                            $roles = $user->roles->pluck('name')->toArray();
                        }
                    } catch (\Exception $e) {
                        // Игнорируем ошибку связи, используем пустой массив
                    }
                }
            } catch (\Exception $rolesError) {
                Log::error('Ошибка получения ролей: ' . $rolesError->getMessage(), [
                    'user_id' => $user->id,
                    'trace' => $rolesError->getTraceAsString(),
                    'file' => $rolesError->getFile(),
                    'line' => $rolesError->getLine()
                ]);
                $roles = [];
            }
            
            // Безопасное форматирование даты
            $birthday = null;
            if ($user->birthday) {
                try {
                    $birthday = $user->birthday->format('Y-m-d');
                } catch (\Exception $e) {
                    Log::warning('Ошибка форматирования даты рождения: ' . $e->getMessage());
                }
            }
            
            // Формируем данные профиля с безопасной обработкой всех полей
            // is_active преобразуется в boolean через cast, но нам нужно вернуть 1/0
            // Преобразуем boolean обратно в 1/0 для фронтенда
            $isActiveValue = $user->is_active ?? true;
            $isActive = $isActiveValue ? 1 : 0; // Преобразуем boolean в 1/0
            
            $profileData = [
                'id' => $user->id ?? null,
                'name' => $user->name ?? '',
                'first_name' => $user->first_name ?? null,
                'last_name' => $user->last_name ?? null,
                'full_name' => $this->getFullName($user),
                'email' => $user->email ?? '',
                'phone' => $user->phone ?? null,
                'birthday' => $birthday,
                'avatar_url' => $user->avatar_url ?? null,
                'is_active' => $isActive, // Возвращаем 1 или 0
                'role' => !empty($roles) ? $roles[0] : 'admin', // Берем первую роль или admin по умолчанию
                'created_at' => $user->created_at ? $user->created_at->toDateTimeString() : null,
                'updated_at' => $user->updated_at ? $user->updated_at->toDateTimeString() : null
            ];
            
            return response()->json([
                'success' => true,
                'data' => $profileData
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения профиля: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id
            ]);
            
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
