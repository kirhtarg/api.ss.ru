<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
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
                'birthday' => $user->birthday?->format('Y-m-d'),
                'avatar_url' => $user->avatar_url,
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
                'first_name' => 'nullable|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'birthday' => 'nullable|date|before:today',
                'avatar_url' => 'nullable|string|max:500',
            ], [
                'first_name.max' => 'Имя не должно превышать 255 символов',
                'last_name.max' => 'Фамилия не должна превышать 255 символов',
                'phone.max' => 'Телефон не должен превышать 20 символов',
                'birthday.date' => 'Дата рождения должна быть корректной датой',
                'birthday.before' => 'Дата рождения не может быть в будущем',
                'avatar_url.max' => 'URL аватара не должен превышать 500 символов',
            ]);

            // Если обновляется аватар и есть старый, удаляем его
            if (isset($validated['avatar_url']) && $user->avatar_url && $user->avatar_url !== $validated['avatar_url']) {
                $this->deleteOldAvatar($user->avatar_url);
            }

            // Обновляем данные пользователя
            /** @var \App\Models\User $user */
            $user->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Профиль успешно обновлен',
                'data' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $this->getFullName($user),
                    'phone' => $user->phone,
                    'birthday' => $user->birthday?->format('Y-m-d'),
                    'avatar_url' => $user->avatar_url,
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
            Log::info('User avatar in DB: ' . ($user->avatar ?? 'NULL'));

            // Проверяем, есть ли аватар для удаления
            // Аватар может существовать даже если в БД avatar = null
            // Поэтому мы не проверяем БД, а сразу пытаемся удалить файл

            // Сначала удаляем файл с диска
            $fileDeleted = $this->deleteAvatarFile($user);
            
            // Очищаем поле avatar в БД
            /** @var \App\Models\User $user */
            $user->update(['avatar' => null]);

            $message = $fileDeleted 
                ? 'Аватар успешно удален (файл и запись в БД)'
                : 'Аватар удален из БД, но файл не найден на диске';

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
            // Предполагаем, что есть таблица orders с полем user_id
            return \DB::table('orders')->where('user_id', $user->id)->count();
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
            // Предполагаем, что есть таблица favorites с полем user_id
            return \DB::table('favorites')->where('user_id', $user->id)->count();
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
            // Предполагаем, что есть таблица orders с полями user_id и total_amount
            return \DB::table('orders')
                ->where('user_id', $user->id)
                ->where('status', '!=', 'cancelled') // Исключаем отмененные заказы
                ->sum('total_amount') ?? 0;
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
}
