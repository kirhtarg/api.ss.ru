<?php

namespace App\Http\Controllers\Auth;


use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CallService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhoneAuthController extends Controller
{
    protected $callService;

    public function __construct(CallService $callService)
    {
        $this->callService = $callService;
    }

    /**
     * Отправить код подтверждения на телефон
     */
    public function sendPhoneCode(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone' => 'required|string|min:10|max:20',
            ]);

            $phone = $this->normalizePhone($request->phone);
            
            // Проверяем, не слишком ли часто запрашивается код
            $cacheKey = "phone_code_attempts_{$phone}";
            $attempts = Cache::get($cacheKey, 0);
            
            if ($attempts >= 5) {
                return response()->json([
                    'success' => false,
                    'message' => 'Слишком много попыток. Попробуйте позже',
                    'retry_after' => 300 // 5 минут
                ], 429);
            }

            // Увеличиваем счетчик попыток
            Cache::put($cacheKey, $attempts + 1, 300);

            // Отправляем звонок с кодом через CallService
            $result = $this->callService->sendCallCode($phone, '0000'); // Передаем заглушку, так как SMSProfi сам генерирует код
            
            if ($result['success'] && isset($result['data']['code'])) {
                // Используем код, который вернул SMSProfi
                $code = $result['data']['code'];
                
                // Логируем для отладки
                \Log::info('Phone auth debug', [
                    'original_phone' => $request->phone,
                    'normalized_phone' => $phone,
                    'smsprofi_code' => $code,
                    'cache_key' => "phone_code_{$phone}"
                ]);
                
                // Сохраняем код от SMSProfi в кеше на 5 минут
                Cache::put("phone_code_{$phone}", $code, 300);
            } else {
                // Если SMSProfi не вернул код, генерируем свой (fallback)
                $code = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                
                // Логируем для отладки
                \Log::info('Phone auth debug (fallback)', [
                    'original_phone' => $request->phone,
                    'normalized_phone' => $phone,
                    'generated_code' => $code,
                    'cache_key' => "phone_code_{$phone}"
                ]);
                
                // Сохраняем сгенерированный код в кеше на 5 минут
                Cache::put("phone_code_{$phone}", $code, 300);
            }
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Код отправлен на ваш телефон',
                    'phone' => $this->maskPhone($phone)
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка отправки кода: ' . $result['message']
                ], 500);
            }

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат номера телефона',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка отправки кода',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Подтвердить код и авторизовать пользователя
     */
    public function verifyPhoneCode(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone' => 'required|string|min:10|max:20',
                'code' => 'required|string|size:4',
            ]);

            $phone = $this->normalizePhone($request->phone);
            $code = $request->code;

            // Проверяем код
            $cachedCode = Cache::get("phone_code_{$phone}");
            
            // Логируем для отладки
            \Log::info('Verify code debug', [
                'original_phone' => $request->phone,
                'normalized_phone' => $phone,
                'provided_code' => $code,
                'cached_code' => $cachedCode,
                'cache_key' => "phone_code_{$phone}",
                'codes_match' => $cachedCode === $code
            ]);
            
            if (!$cachedCode || $cachedCode !== $code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный код подтверждения'
                ], 400);
            }

            // Ищем пользователя по телефону
            $user = User::where('phone', $phone)->first();

            if (!$user) {
                // Создаем нового пользователя
                $user = User::create([
                    'name' => $phone, // Используем номер телефона как имя
                    'email' => 'NO', // Для пользователей по телефону email = NO
                    'password' => Hash::make(Str::random(32)), // Случайный пароль для пользователей по телефону
                    'phone' => $phone,
                    'avatar_url' => '/ph.png', // Аватар по умолчанию
                    'phone_verified_at' => now(),
                    'email_verified_at' => now(), // Активируем пользователя
                ]);

                // Привязываем роль 'user' по умолчанию
                $userRole = \App\Models\Role::where('name', 'user')->first();
                if ($userRole) {
                    $user->roles()->attach($userRole->id, [
                        'is_active' => true,
                        'assigned_at' => now()
                    ]);
                }
            } else {
                // Обновляем время подтверждения телефона
                $user->update([
                    'phone_verified_at' => now(),
                    'last_login_at' => now()
                ]);
            }

            // Удаляем использованный код
            Cache::forget("phone_code_{$phone}");

            // Создаем токен
            $token = $user->createToken('phone-auth-token')->plainTextToken;

            // Получаем разрешения пользователя
            $permissions = $this->getUserPermissions($user);

            return response()->json([
                'success' => true,
                'message' => 'Успешная авторизация',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                    'avatar_url' => $user->avatar_url ?? null,
                    'email_verified_at' => $user->email_verified_at,
                    'permissions' => $permissions,
                    'last_login_at' => $user->last_login_at,
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
     * Проверить статус кода (для фронтенда)
     */
    public function checkCodeStatus(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone' => 'required|string|min:10|max:20',
            ]);

            $phone = $this->normalizePhone($request->phone);
            $hasCode = Cache::has("phone_code_{$phone}");
            
            // Логируем для отладки
            \Log::info('Check code status debug', [
                'original_phone' => $request->phone,
                'normalized_phone' => $phone,
                'cache_key' => "phone_code_{$phone}",
                'has_code' => $hasCode
            ]);
            
            return response()->json([
                'success' => true,
                'has_code' => $hasCode,
                'phone' => $this->maskPhone($phone)
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат номера телефона',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки статуса',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить разрешения пользователя
     */
    private function getUserPermissions(User $user): array
    {
        $permissions = [];
        
        foreach ($user->roles as $role) {
            $rolePermissions = $role->permissions ?? [];
            $permissions = array_merge($permissions, $rolePermissions);
        }
        
        return array_unique($permissions);
    }

    /**
     * Маскировать номер телефона для отображения
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) === 12 && str_starts_with($phone, '+7')) {
            return '+7 *** *** ' . substr($phone, -2);
        }
        return $phone;
    }

    /**
     * Нормализовать номер телефона
     */
    private function normalizePhone(string $phone): string
    {
        // Убираем все символы кроме цифр и +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Если номер начинается с 8, заменяем на +7
        if (str_starts_with($phone, '8')) {
            $phone = '+7' . substr($phone, 1);
        }
        
        // Если номер начинается с 7, добавляем +
        if (str_starts_with($phone, '7') && !str_starts_with($phone, '+7')) {
            $phone = '+' . $phone;
        }
        
        // Проверяем, что номер соответствует российскому формату
        if (!preg_match('/^\+7[0-9]{10}$/', $phone)) {
            throw new \InvalidArgumentException('Неверный формат номера телефона');
        }
        
        return $phone;
    }
}
