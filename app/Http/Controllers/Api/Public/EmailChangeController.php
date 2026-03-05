<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SiteInfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class EmailChangeController extends Controller
{
    /**
     * Отправить код подтверждения на новый email
     */
    public function sendVerificationCode(Request $request): JsonResponse
    {
        try {
            // Отладочная информация
            file_put_contents(
                storage_path('logs/email_change_debug.log'),
                date('Y-m-d H:i:s')." - Email change request received\n",
                FILE_APPEND | LOCK_EX
            );

            $user = Auth::user();
            if (! $user) {
                file_put_contents(
                    storage_path('logs/email_change_debug.log'),
                    date('Y-m-d H:i:s')." - User not authenticated\n",
                    FILE_APPEND | LOCK_EX
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован',
                ], 401);
            }

            file_put_contents(
                storage_path('logs/email_change_debug.log'),
                date('Y-m-d H:i:s')." - User authenticated: {$user->id}\n",
                FILE_APPEND | LOCK_EX
            );

            // Используем тот же подход, что и в регистрации
            $request->validate([
                'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            ], [
                'email.required' => 'Email обязателен для заполнения',
                'email.email' => 'Введите корректный email адрес',
                'email.max' => 'Email не должен превышать 255 символов',
                'email.unique' => 'Этот email уже используется другим пользователем',
            ]);

            $newEmail = $request->email;

            // Генерируем 4-значный код для подтверждения email (как в регистрации)
            $verificationCode = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

            // Сохраняем код в кеше на 15 минут (используем тот же формат, что и в регистрации)
            Cache::put(
                'email_verification_code_'.$newEmail,
                $verificationCode,
                now()->addMinutes(15)
            );

            // Отправляем email с кодом подтверждения
            try {
                $this->sendEmailChangeVerificationCode($user, $newEmail, $verificationCode);
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем процесс
                \Log::error('Email sending failed for email change: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Код подтверждения отправлен на новый email',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error sending email change verification code', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка отправки кода подтверждения: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Подтвердить смену email по коду
     */
    public function verifyCode(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован',
                ], 401);
            }

            // Используем тот же подход, что и в регистрации
            $request->validate([
                'email' => 'required|email|max:255',
                'code' => 'required|string|size:4',
            ], [
                'email.required' => 'Email обязателен для заполнения',
                'email.email' => 'Введите корректный email адрес',
                'email.max' => 'Email не должен превышать 255 символов',
                'code.required' => 'Код подтверждения обязателен',
                'code.string' => 'Код подтверждения должен быть строкой',
                'code.size' => 'Код подтверждения должен содержать 4 цифры',
            ]);

            $email = $request->email;
            $code = $request->code;

            // Получаем код из кеша (используем тот же формат, что и в регистрации)
            $cachedCode = Cache::get('email_verification_code_'.$email);

            if (! $cachedCode || $cachedCode !== $code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный или истекший код подтверждения',
                ], 400);
            }

            // Обновляем email пользователя
            $oldEmail = $user->email;
            $user->update(['email' => $email]);

            // Удаляем код из кеша
            Cache::forget('email_verification_code_'.$email);

            return response()->json([
                'success' => true,
                'message' => 'Email успешно изменен',
                'data' => [
                    'email' => $email,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error verifying email change code', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка подтверждения кода: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Отправить email с кодом подтверждения смены email
     */
    private function sendEmailChangeVerificationCode(User $user, string $newEmail, string $code): void
    {
        // Получаем информацию о сайте
        $siteInfo = SiteInfoService::getSiteInfoForEmail();

        // Отправляем простое письмо с кодом
        Mail::send('emails.email-change-verification', [
            'user' => $user,
            'newEmail' => $newEmail,
            'code' => $code,
            'siteInfo' => $siteInfo,
        ], function ($message) use ($user, $newEmail, $siteInfo) {
            $message->to($newEmail, $user->name)
                ->subject('Код подтверждения смены email - '.($siteInfo['site_name'] ?? 'Skate & Snow'));
        });
    }
}
