<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Авторизация пользователя
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|string',
                'password' => 'required|string',
            ]);

            $loginField = $request->email;

            // Ищем пользователя по email или по имени (для Admin)
            $user = User::where('email', $loginField)
                ->orWhere('name', $loginField)
                ->with('roles')
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден',
                    'debug' => 'Пользователь с email/именем "' . $loginField . '" не найден'
                ], 401);
            }

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный пароль',
                    'debug' => 'Пароль не совпадает для пользователя ' . $user->name
                ], 401);
            }

            // Создаем токен
            $token = $user->createToken('auth-token')->plainTextToken;

            // Получаем разрешения пользователя
            $permissions = $this->getUserPermissions($user);

            return response()->json([
                'success' => true,
                'message' => 'Успешная авторизация',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                    'avatar_url' => null,
                    'is_active' => true,
                    'permissions' => $permissions,
                    'last_login_at' => null,
                ],
                'token' => $token
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка авторизации',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Выход пользователя
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Успешный выход'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при выходе',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить информацию о текущем пользователе
     */
    public function user(Request $request): JsonResponse
    {
        try {
            $user = $request->user()->load('roles');
            $permissions = $this->getUserPermissions($user);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                    'avatar_url' => null,
                    'is_active' => true,
                    'permissions' => $permissions,
                    'last_login_at' => null,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения данных пользователя',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить статус авторизации
     */
    public function check(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'authenticated' => true
        ]);
    }

    /**
     * Получить разрешения пользователя
     */
    private function getUserPermissions(User $user): array
    {
        $permissions = [];

        foreach ($user->roles as $role) {
            if ($role->permissions && is_array($role->permissions)) {
                $permissions = array_merge($permissions, $role->permissions);
            }
        }

        return array_unique($permissions);
    }
}
