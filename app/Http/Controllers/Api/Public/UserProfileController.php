<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use App\Services\CallService;
use App\Models\User;

class UserProfileController extends Controller
{
    protected $callService;

    public function __construct(CallService $callService)
    {
        $this->callService = $callService;
    }
    /**
     * Получить профиль текущего пользователя
     */
    public function getProfile(Request $request): JsonResponse
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
                'phone_verified_at' => $user->phone_verified_at, // Для определения телефонных пользователей
                'birthday' => $user->birthday?->format('Y-m-d'),
                'avatar_url' => $user->avatar_url, // URL аватара от OAuth провайдеров
                'google_id' => $user->google_id, // Для определения соц-аккаунта
                'yandex_id' => $user->yandex_id, // Для определения соц-аккаунта
                'vk_id' => $user->vk_id, // Для определения соц-аккаунта
                'is_active' => $user->is_active,
                'role' => $roles[0] ?? 'user',
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at
            ];
            
            return response()->json([
                'success' => true,
                'data' => $profileData
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения профиля пользователя: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения профиля',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Обновить профиль пользователя
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            // Валидация данных
            $validated = $request->validate([
                'name' => 'nullable|string|max:255',
                'first_name' => 'nullable|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'birthday' => 'nullable|date|before:today',
            ], [
                'name.max' => 'Имя на сайте не должно превышать 255 символов',
                'first_name.max' => 'Имя не должно превышать 255 символов',
                'last_name.max' => 'Фамилия не должна превышать 255 символов',
                'phone.max' => 'Телефон не должен превышать 20 символов',
                'birthday.date' => 'Дата рождения должна быть корректной датой',
                'birthday.before' => 'Дата рождения не может быть в будущем',
            ]);

            // Обновляем данные пользователя
            /** @var \App\Models\User $user */
            $user->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Профиль успешно обновлен',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $this->getFullName($user),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'birthday' => $user->birthday?->format('Y-m-d'),
                    'avatar_url' => $user->avatar_url, // URL аватара от OAuth провайдеров
                    'google_id' => $user->google_id, // Для определения соц-аккаунта
                    'yandex_id' => $user->yandex_id, // Для определения соц-аккаунта
                    'vk_id' => $user->vk_id, // Для определения соц-аккаунта
                    'updated_at' => $user->updated_at,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации данных',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Ошибка обновления профиля пользователя: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления профиля',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Изменить пароль пользователя
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            // Валидация данных
            $validated = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ], [
                'current_password.required' => 'Текущий пароль обязателен',
                'new_password.required' => 'Новый пароль обязателен',
                'new_password.min' => 'Новый пароль должен содержать минимум 8 символов',
                'new_password.confirmed' => 'Подтверждение пароля не совпадает',
            ]);

            // Проверяем текущий пароль
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Текущий пароль неверен'
                ], 400);
            }

            // Обновляем пароль
            /** @var \App\Models\User $user */
            $user->update([
                'password' => Hash::make($validated['new_password'])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Пароль успешно изменен'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации данных',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Ошибка смены пароля пользователя: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка смены пароля',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Удалить аватар пользователя
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        try {
            Log::info('=== DELETE AVATAR METHOD CALLED ===');
            $user = Auth::user();
            
            if (!$user) {
                Log::error('User not authenticated');
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }
            
            Log::info('User ID: ' . $user->id);

            // Удаляем файл с диска
            // Поля avatar и avatar_url больше не используются в БД
            $fileDeleted = $this->deleteAvatarFile($user);

            $message = $fileDeleted 
                ? 'Аватар успешно удален'
                : 'Файл аватара не найден';

            Log::info('Avatar deletion completed. File deleted: ' . ($fileDeleted ? 'YES' : 'NO'));
            Log::info('=== DELETE AVATAR METHOD END ===');

            return response()->json([
                'success' => true,
                'message' => $message,
                'file_deleted' => $fileDeleted
            ], 200);

        } catch (\Exception $e) {
            Log::error('Ошибка удаления аватара пользователя: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления аватара',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Получить статистику пользователя
     */
    public function getStatistics(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            // Получаем реальную статистику
            $statistics = [
                'ordersCount' => $this->getOrdersCount($user),
                'favoritesCount' => $this->getFavoritesCount($user),
                'totalSpent' => $this->getTotalSpent($user),
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics
            ], 200);

        } catch (\Exception $e) {
            Log::error('Ошибка получения статистики пользователя: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
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

    /**
     * Получить количество заказов пользователя
     */
    private function getOrdersCount($user): int
    {
        try {
            return \DB::table('shop_orders')->where('user_id', $user->id)->count();
        } catch (\Exception $e) {
            Log::error('Ошибка подсчета заказов: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Получить количество избранных товаров пользователя
     */
    private function getFavoritesCount($user): int
    {
        try {
            return \DB::table('shop_favorites')->where('user_id', $user->id)->count();
        } catch (\Exception $e) {
            Log::error('Ошибка подсчета избранного: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Получить общую сумму потраченных денег
     */
    private function getTotalSpent($user): int
    {
        try {
            // Получаем ID статуса "отменен" если он есть
            $cancelledStatusId = \DB::table('shop_order_statuses')
                ->where(function($q) {
                    $q->where('name', 'cancelled')
                      ->orWhere('name', 'отменен');
                })
                ->value('id');
            
            $query = \DB::table('shop_orders')
                ->where('user_id', $user->id);
            
            // Исключаем отмененные заказы
            if ($cancelledStatusId) {
                $query->where('status_id', '!=', $cancelledStatusId);
            }
            
            return $query->sum('total_amount') ?? 0;
        } catch (\Exception $e) {
            Log::error('Ошибка подсчета потраченной суммы: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Удалить файл аватара пользователя
     */
    private function deleteAvatarFile($user): bool
    {
        try {
            if (!$user) {
                Log::error('User not provided');
                return false;
            }

            // Путь к папке на фронтенде
            $frontendPath = dirname(base_path()) . '/' . ltrim(env('FRONTEND_PATH', 'admin.skateandsnow.ru'), './') . '/public/images/users/';
            
            // Всегда используем стандартное имя файла user_{id}.jpg
            // Не проверяем БД - просто удаляем файл, если он есть
            $filename = 'user_' . $user->id . '.jpg';
            $fullPath = $frontendPath . $filename;
            
            
            // Проверяем существование файла
            if (file_exists($fullPath)) {
                // Удаляем файл
                if (unlink($fullPath)) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return true; // Файл не найден - это нормально, считаем что удаление успешно
            }

        } catch (\Exception $e) {
            Log::error('Ошибка удаления файла аватара: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Удалить старый аватар (старый метод для совместимости)
     */
    private function deleteOldAvatar(string $avatarUrl): void
    {
        try {
            // Убираем /storage/ префикс для работы с Storage
            $filePath = str_replace('/storage/', '', $avatarUrl);
            
            // Если это локальный файл, удаляем его
            if (!str_starts_with($filePath, 'http')) {
                if (Storage::disk('public')->exists($filePath)) {
                    Storage::disk('public')->delete($filePath);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Не удалось удалить старый аватар: ' . $e->getMessage());
        }
    }

    /**
     * Отправить код подтверждения для изменения телефона
     */
    public function sendPhoneChangeCode(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $validated = $request->validate([
                'phone' => 'required|string|min:10|max:20',
            ], [
                'phone.required' => 'Номер телефона обязателен',
                'phone.min' => 'Номер телефона слишком короткий',
                'phone.max' => 'Номер телефона слишком длинный',
            ]);

            $phone = $this->normalizePhone($validated['phone']);
            
            // Проверяем, что новый телефон отличается от текущего
            if ($phone === $user->phone) {
                return response()->json([
                    'success' => false,
                    'message' => 'Новый телефон должен отличаться от текущего',
                    'errors' => ['phone' => ['Новый телефон должен отличаться от текущего']]
                ], 422);
            }
            
            // Проверяем уникальность телефона
            $existingUser = User::where('phone', $phone)
                ->where('id', '!=', $user->id)
                ->first();
            
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Этот номер телефона уже используется другим пользователем',
                    'errors' => ['phone' => ['Этот номер телефона уже используется другим пользователем']]
                ], 422);
            }
            
            // Проверяем, не слишком ли часто запрашивается код
            $cacheKey = "phone_change_code_attempts_{$phone}";
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
            $result = $this->callService->sendCallCode($phone, '0000');
            
            if ($result['success'] && isset($result['data']['code'])) {
                $code = $result['data']['code'];
                Cache::put("phone_change_code_{$phone}_{$user->id}", $code, 300);
            } else {
                // Fallback - генерируем код сами
                $code = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                Cache::put("phone_change_code_{$phone}_{$user->id}", $code, 300);
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
                    'message' => 'Ошибка отправки кода: ' . ($result['message'] ?? 'Неизвестная ошибка')
                ], 500);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка отправки кода для смены телефона: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка отправки кода',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Подтвердить код и обновить телефон пользователя
     */
    public function verifyPhoneChangeCode(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $validated = $request->validate([
                'phone' => 'required|string|min:10|max:20',
                'code' => 'required|string|size:4',
            ], [
                'phone.required' => 'Номер телефона обязателен',
                'code.required' => 'Код подтверждения обязателен',
                'code.size' => 'Код должен содержать 4 цифры',
            ]);

            $phone = $this->normalizePhone($validated['phone']);
            $code = $validated['code'];

            // Проверяем код
            $cacheKey = "phone_change_code_{$phone}_{$user->id}";
            $cachedCode = Cache::get($cacheKey);
            
            if (!$cachedCode || $cachedCode !== $code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный код подтверждения',
                    'errors' => ['code' => ['Неверный код подтверждения']]
                ], 400);
            }

            // Проверяем уникальность телефона еще раз (на случай, если кто-то зарегистрировался между запросами)
            $existingUser = User::where('phone', $phone)
                ->where('id', '!=', $user->id)
                ->first();
            
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Этот номер телефона уже используется другим пользователем',
                    'errors' => ['phone' => ['Этот номер телефона уже используется другим пользователем']]
                ], 422);
            }

            // Обновляем телефон пользователя
            $user->update([
                'phone' => $phone,
                'phone_verified_at' => now()
            ]);

            // Удаляем использованный код
            Cache::forget($cacheKey);

            return response()->json([
                'success' => true,
                'message' => 'Телефон успешно изменен',
                'data' => [
                    'id' => $user->id,
                    'phone' => $user->phone,
                    'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка подтверждения кода для смены телефона: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка подтверждения кода',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
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
}
