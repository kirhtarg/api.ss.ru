<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationMail;
use App\Models\User;
use App\Services\SiteInfoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Регистрация пользователя
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // Создаем пользователя
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'email_verified_at' => null, // Пользователь не подтвержден
            ]);

            // Привязываем роль 'user' по умолчанию
            $userRole = \App\Models\Role::where('name', 'user')->first();
            if ($userRole) {
                $user->roles()->attach($userRole->id, [
                    'email_verified_at' => now(),
                    'assigned_at' => now()
                ]);
            }

            // Генерируем токен для подтверждения email
            $verificationToken = Str::random(64);
            
            // Сохраняем токен в базе данных (можно использовать кеш или отдельную таблицу)
            \Illuminate\Support\Facades\Cache::put(
                'email_verification_' . $verificationToken,
                $user->id,
                now()->addHours(24) // Токен действителен 24 часа
            );

            // Отправляем email с подтверждением
            try {
                $this->sendVerificationEmail($user, $verificationToken);
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем регистрацию
                \Log::error('Email sending failed: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Регистрация успешна! Проверьте email для подтверждения аккаунта.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка регистрации',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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

            // Проверяем подтверждение email
            if (!$user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email не подтвержден',
                    'error' => 'email_not_verified',
                    'user_email' => $user->email
                ], 403);
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
                    'email_verified_at' => now(),
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
                    'avatar_url' => $user->avatar_url,
                    'email_verified_at' => now(),
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
     * Подтверждение email
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'token' => 'required|string',
            ]);

            $token = $request->token;
            $userId = \Illuminate\Support\Facades\Cache::get('email_verification_' . $token);

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный или истекший токен подтверждения'
                ], 400);
            }

            $user = User::find($userId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден'
                ], 404);
            }

            if ($user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email уже подтвержден'
                ], 400);
            }

            // Подтверждаем email
            $user->update(['email_verified_at' => now()]);

            // Удаляем токен из кеша
            \Illuminate\Support\Facades\Cache::forget('email_verification_' . $token);

            return response()->json([
                'success' => true,
                'message' => 'Email успешно подтвержден! Теперь вы можете войти в систему.'
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
                'message' => 'Ошибка подтверждения email',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Повторная отправка email подтверждения
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            $user = User::where('email', $request->email)->first();

            if ($user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email уже подтвержден'
                ], 400);
            }

            // Генерируем новый токен
            $verificationToken = Str::random(64);
            
            // Сохраняем токен в кеше
            \Illuminate\Support\Facades\Cache::put(
                'email_verification_' . $verificationToken,
                $user->id,
                now()->addHours(24)
            );

            // Отправляем email
            $this->sendVerificationEmail($user, $verificationToken);

            return response()->json([
                'success' => true,
                'message' => 'Письмо с подтверждением отправлено повторно'
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
                'message' => 'Ошибка отправки письма',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить существование email
     */
    public function checkEmail(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
            ]);

            $email = $request->email;
            $exists = User::where('email', $email)->exists();

            return response()->json([
                'success' => true,
                'exists' => $exists,
                'message' => $exists ? 'Email найден' : 'Email свободен'
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
                'message' => 'Ошибка проверки email',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Отправить email с подтверждением
     */
    private function sendVerificationEmail(User $user, string $token): void
    {
        $verificationUrl = config('app.frontend_url') . '/verify-email?token=' . $token;
        
        // Получаем информацию о сайте
        $siteInfo = SiteInfoService::getSiteInfoForEmail();
        
        // Отправляем красивое HTML письмо
        Mail::send(new EmailVerificationMail($user, $verificationUrl, $siteInfo));
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
