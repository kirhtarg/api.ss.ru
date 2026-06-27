<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Traits\AwardsWelcomeBonuses;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    use AwardsWelcomeBonuses;

    /**
     * Перенаправление на Google OAuth
     */
    public function redirectToGoogle(Request $request)
    {
        try {
            // Проверяем, что сессии настроены
            if (! session()->isStarted()) {
                session()->start();
            }

            if ($request->boolean('mobile')) { session(['mobile_oauth_google' => true]); }

            return Socialite::driver('google')
                ->scopes(['openid', 'profile', 'email'])
                ->redirect();
        } catch (\Exception $e) {
            Log::error('Google OAuth redirect error: '.$e->getMessage());

            // Если это ошибка сессии, возвращаем более понятное сообщение
            if (str_contains($e->getMessage(), 'Session store not set')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка настройки сессий. Обратитесь к администратору.',
                    'error' => 'Session configuration error',
                ], 500);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка перенаправления на Google',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обработка callback от Google
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            // Проверяем, есть ли пользователь с таким Google ID
            $user = User::where('google_id', $googleUser->getId())->first();
            $bonusAmount = 0; // По умолчанию бонусы не начислены

            if ($user) {
                // Проверяем, что пользователь не заблокирован
                if ($user->is_active === 0 || $user->is_active === false) {
                    $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                    $errorUrl = $frontendUrl.'/auth/google/callback?error='.urlencode('Ваш аккаунт заблокирован. Обратитесь к администратору.');

                    return redirect($errorUrl);
                }

                // Пользователь уже существует, обновляем данные
                $displayName = $googleUser->getName();
                // Добавляем источник регистрации в скобках
                if (! str_ends_with($displayName, ' (google)')) {
                    $displayName .= ' (google)';
                }

                $updateData = [
                    'name' => $displayName,
                    'avatar_url' => $googleUser->getAvatar(),
                    'last_login_at' => now(),
                ];

                // Обновляем email только если он пустой у пользователя
                // Если email уже есть, не обновляем его (пользователь мог изменить его)
                if (empty($user->email) || $user->email === 'NO' || $user->email === '') {
                    $updateData['email'] = $googleUser->getEmail();
                    $updateData['email_verified_at'] = now();
                }

                $user->update($updateData);
            } else {
                // Проверяем, есть ли пользователь с таким email
                $existingUser = User::where('email', $googleUser->getEmail())->first();

                if ($existingUser) {
                    // Проверяем, что пользователь не заблокирован
                    if ($existingUser->is_active === 0 || $existingUser->is_active === false) {
                        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                        $errorUrl = $frontendUrl.'/auth/google/callback?error='.urlencode('Ваш аккаунт заблокирован. Обратитесь к администратору.');

                        return redirect($errorUrl);
                    }

                    // Связываем существующего пользователя с Google
                    $updateExisting = [
                        'google_id' => $googleUser->getId(),
                        'avatar_url' => $googleUser->getAvatar(),
                        'last_login_at' => now(),
                    ];

                    // Обновляем email только если он пустой у пользователя
                    // Если email уже есть, не обновляем его (пользователь мог изменить его)
                    if (empty($existingUser->email) || $existingUser->email === 'NO' || $existingUser->email === '') {
                        $updateExisting['email'] = $googleUser->getEmail();
                        $updateExisting['email_verified_at'] = now();
                    }

                    $existingUser->update($updateExisting);
                    $user = $existingUser;
                } else {
                    // Создаем нового пользователя
                    $displayName = $googleUser->getName();
                    // Добавляем источник регистрации в скобках
                    if (! str_ends_with($displayName, ' (google)')) {
                        $displayName .= ' (google)';
                    }

                    $user = User::create([
                        'name' => $displayName,
                        'email' => $googleUser->getEmail(),
                        'google_id' => $googleUser->getId(),
                        'avatar_url' => $googleUser->getAvatar(),
                        'password' => Hash::make(Str::random(32)), // Случайный пароль
                        'email_verified_at' => now(),
                        'last_login_at' => now(),
                    ]);

                    // Привязываем роль 'user' по умолчанию
                    $userRole = Role::where('name', 'user')->first();
                    if ($userRole) {
                        $user->roles()->attach($userRole->id, [
                            'is_active' => true,
                            'assigned_at' => now(),
                        ]);
                    }

                    // Начисляем приветственные бонусы новому пользователю
                    $bonusAmount = $this->awardWelcomeBonuses($user);
                }
            }

            // Создаем токен для пользователя
            $token = $user->createToken('google-auth-token')->plainTextToken;

            // Получаем разрешения пользователя
            $permissions = $this->getUserPermissions($user);

            // Перенаправляем на фронтенд с токеном
            $isMobileOAuth = session()->pull('mobile_oauth_google');
            $frontendUrl = $isMobileOAuth ? 'skateandsnow://' : config('app.frontend_url', 'http://localhost:3000');
            $redirectPath = str_starts_with($frontendUrl, 'skateandsnow://') ? 'auth/google/callback' : '/auth/google/callback';
            $redirectUrl = (str_starts_with($frontendUrl, 'skateandsnow://') ? $frontendUrl : rtrim($frontendUrl, '/')).$redirectPath.'?token='.$token.'&user='.base64_encode(json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                'email_verified_at' => now(),
                'permissions' => $permissions,
            ])).'&bonus_amount='.$bonusAmount;

            if ($isMobileOAuth) {
                return $this->mobileOauthResponse($redirectUrl);
            }

            return redirect($redirectUrl);

        } catch (\Exception $e) {
            Log::error('Google OAuth callback error: '.$e->getMessage());

            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $errorUrl = $frontendUrl.'/auth/google/callback?error='.urlencode('Ошибка авторизации через Google');

            return redirect($errorUrl);
        }
    }

    private function mobileOauthResponse(string $redirectUrl)
    {
        $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
        $encodedUrl = json_encode($redirectUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response('<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Возврат в приложение</title></head><body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f3f4f6;font-family:Arial,sans-serif;color:#111827"><main style="max-width:320px;padding:24px;text-align:center"><h1 style="font-size:20px;margin:0 0 12px">Возвращаемся в приложение</h1><p style="font-size:14px;line-height:1.5;margin:0 0 20px;color:#4b5563">Если приложение не открылось автоматически, нажмите кнопку ниже.</p><a href="'.$escapedUrl.'" style="display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:14px;background:#111827;color:#fff;text-decoration:none;font-weight:700">Открыть приложение</a><script>window.location.replace('.$encodedUrl.');</script></main></body></html>', 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Получить разрешения пользователя
     */
    private function getUserPermissions($user)
    {
        $permissions = [];

        if ($user->roles) {
            foreach ($user->roles as $role) {
                if ($role->permissions) {
                    foreach ($role->permissions as $permission) {
                        $permissions[] = $permission->name;
                    }
                }
            }
        }

        return array_unique($permissions);
    }

    /**
     * API endpoint для получения URL авторизации Google
     */
    public function getGoogleAuthUrl()
    {
        try {
            // Проверяем, что сессии настроены
            if (! session()->isStarted()) {
                session()->start();
            }

            $url = Socialite::driver('google')
                ->scopes(['openid', 'profile', 'email'])
                ->redirect()
                ->getTargetUrl();

            return response()->json([
                'success' => true,
                'auth_url' => $url,
            ]);
        } catch (\Exception $e) {
            Log::error('Google OAuth URL generation error: '.$e->getMessage());

            // Если это ошибка сессии, возвращаем более понятное сообщение
            if (str_contains($e->getMessage(), 'Session store not set')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка настройки сессий. Обратитесь к администратору.',
                    'error' => 'Session configuration error',
                ], 500);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения URL авторизации Google',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}





