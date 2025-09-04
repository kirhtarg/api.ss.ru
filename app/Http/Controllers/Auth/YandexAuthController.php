<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Log;

class YandexAuthController extends Controller
{
    /**
     * Перенаправление на Yandex OAuth
     */
    public function redirectToYandex()
    {
        try {
            // Проверяем, что сессии настроены
            if (!session()->isStarted()) {
                session()->start();
            }
            
            // Используем прямой URL для Yandex OAuth
            $clientId = config('services.yandex.client_id');
            $redirectUri = config('services.yandex.redirect');
            $scope = 'login:email login:info login:avatar';
            
            $url = "https://oauth.yandex.ru/authorize?" . http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
            ]);
            
            return redirect($url);
            
        } catch (\Exception $e) {
            Log::error('Yandex OAuth redirect error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка перенаправления на Yandex',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработка callback от Yandex
     */
    public function handleYandexCallback(Request $request)
    {
        try {
            $code = $request->get('code');
            
            if (!$code) {
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/yandex/callback?error=' . urlencode('Код авторизации не получен');
                return redirect($errorUrl);
            }
            
            // Получаем токен
            $tokenResponse = \Http::asForm()->post('https://oauth.yandex.ru/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => config('services.yandex.client_id'),
                'client_secret' => config('services.yandex.client_secret'),
            ]);
            
            $tokenData = $tokenResponse->json();
            
            if (!isset($tokenData['access_token'])) {
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/yandex/callback?error=' . urlencode('Не удалось получить токен доступа');
                return redirect($errorUrl);
            }
            
            // Получаем данные пользователя
            $userResponse = \Http::withHeaders([
                'Authorization' => 'OAuth ' . $tokenData['access_token']
            ])->get('https://login.yandex.ru/info');
            
            // Получаем расширенные данные пользователя (включая дату рождения и телефон)
            $extendedUserResponse = null;
            $yandexIdResponse = null;
            
            try {
                $extendedUserResponse = \Http::withHeaders([
                    'Authorization' => 'OAuth ' . $tokenData['access_token']
                ])->get('https://api-yaru.yandex.ru/me');
            } catch (\Exception $e) {
                Log::warning('Yandex extended API error:', ['error' => $e->getMessage()]);
            }
            
            try {
                // Пробуем также Yandex ID API для получения дополнительных данных
                $yandexIdResponse = \Http::withHeaders([
                    'Authorization' => 'OAuth ' . $tokenData['access_token']
                ])->get('https://id.yandex.ru/info');
            } catch (\Exception $e) {
                Log::warning('Yandex ID API error:', ['error' => $e->getMessage()]);
            }
            
            $yandexUser = $userResponse->json();
            $extendedUserData = $extendedUserResponse ? $extendedUserResponse->json() : null;
            $yandexIdData = $yandexIdResponse ? $yandexIdResponse->json() : null;
            
            // Объединяем данные от всех API
            if ($extendedUserData && !isset($extendedUserData['error'])) {
                $yandexUser = array_merge($yandexUser, $extendedUserData);
            }
            if ($yandexIdData && !isset($yandexIdData['error'])) {
                $yandexUser = array_merge($yandexUser, $yandexIdData);
            }
            
            // Логируем данные от Yandex для отладки
            Log::info('Yandex user data:', $yandexUser ?? []);
            Log::info('Yandex extended data:', $extendedUserData ?? []);
            Log::info('Yandex ID data:', $yandexIdData ?? []);
            
            // Проверяем все возможные поля для аватара
            $avatarFields = [
                'default_avatar_id',
                'avatar_id', 
                'avatar',
                'picture',
                'photo',
                'image'
            ];
            
            $avatarInfo = [];
            foreach ($avatarFields as $field) {
                if (isset($yandexUser[$field])) {
                    $avatarInfo[$field] = $yandexUser[$field];
                }
            }
            Log::info('Yandex avatar fields found:', $avatarInfo ?? []);
            
            if (!isset($yandexUser['id'])) {
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/yandex/callback?error=' . urlencode('Не удалось получить данные пользователя');
                return redirect($errorUrl);
            }
            
            // Получаем URL аватара
            $avatarUrl = $this->getYandexAvatarUrl($yandexUser);
            
            // Получаем дополнительные данные
            $additionalData = $this->getYandexAdditionalData($yandexUser);
            
            // Проверяем, есть ли пользователь с таким Yandex ID
            $user = User::where('yandex_id', $yandexUser['id'])->first();
            
            if ($user) {
                // Пользователь уже существует, обновляем данные
                Log::info('Yandex avatar URL:', ['avatar_url' => $avatarUrl]);
                
                $user->update([
                    'name' => $yandexUser['display_name'] ?? $yandexUser['real_name'] ?? 'Yandex User',
                    'first_name' => $additionalData['first_name'],
                    'last_name' => $additionalData['last_name'],
                    'email' => $yandexUser['default_email'] ?? null,
                    'avatar_url' => $avatarUrl,
                    'birthday' => $additionalData['birthday'],
                    'phone' => $additionalData['phone'],
                    'additional_info' => $additionalData['info'],
                    'email_verified_at' => $yandexUser['default_email'] ? now() : null,
                    'last_login_at' => now(),
                ]);
            } else {
                // Проверяем, есть ли пользователь с таким email
                $existingUser = null;
                if (isset($yandexUser['default_email'])) {
                    $existingUser = User::where('email', $yandexUser['default_email'])->first();
                }
                
                if ($existingUser) {
                    // Связываем существующего пользователя с Yandex
                    Log::info('Yandex existing user avatar URL:', ['avatar_url' => $avatarUrl]);
                    
                    $existingUser->update([
                        'yandex_id' => $yandexUser['id'],
                        'first_name' => $additionalData['first_name'],
                        'last_name' => $additionalData['last_name'],
                        'avatar_url' => $avatarUrl,
                        'birthday' => $additionalData['birthday'],
                        'phone' => $additionalData['phone'],
                        'additional_info' => $additionalData['info'],
                        'email_verified_at' => $yandexUser['default_email'] ? now() : $existingUser->email_verified_at,
                        'last_login_at' => now(),
                    ]);
                    $user = $existingUser;
                } else {
                    // Создаем нового пользователя
                    Log::info('Yandex new user avatar URL:', ['avatar_url' => $avatarUrl]);
                    
                    $user = User::create([
                        'name' => $yandexUser['display_name'] ?? $yandexUser['real_name'] ?? 'Yandex User',
                        'first_name' => $additionalData['first_name'],
                        'last_name' => $additionalData['last_name'],
                        'email' => $yandexUser['default_email'] ?? null,
                        'yandex_id' => $yandexUser['id'],
                        'avatar_url' => $avatarUrl,
                        'birthday' => $additionalData['birthday'],
                        'phone' => $additionalData['phone'],
                        'additional_info' => $additionalData['info'],
                        'password' => Hash::make(Str::random(32)), // Случайный пароль
                        'email_verified_at' => $yandexUser['default_email'] ? now() : null,
                        'is_active' => true,
                        'last_login_at' => now(),
                    ]);
                    
                    // Привязываем роль 'user' по умолчанию
                    $userRole = Role::where('name', 'user')->first();
                    if ($userRole) {
                        $user->roles()->attach($userRole->id);
                    }
                }
            }
            
            // Создаем токен для пользователя
            $token = $user->createToken('yandex-auth-token')->plainTextToken;
            
            // Получаем разрешения пользователя
            $permissions = $this->getUserPermissions($user);
            
            // Перенаправляем на фронтенд с токеном
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $redirectUrl = $frontendUrl . '/auth/yandex/callback?token=' . $token . '&user=' . base64_encode(json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                'is_active' => true,
                'permissions' => $permissions,
            ]));
            
            return redirect($redirectUrl);
            
        } catch (\Exception $e) {
            Log::error('Yandex OAuth callback error: ' . $e->getMessage());
            
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $errorUrl = $frontendUrl . '/auth/yandex/callback?error=' . urlencode('Ошибка авторизации через Yandex');
            
            return redirect($errorUrl);
        }
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
     * Получить URL аватара от Yandex
     */
    private function getYandexAvatarUrl($yandexUser)
    {
        // Проверяем разные возможные поля для аватара
        $avatarFields = [
            'default_avatar_id',
            'avatar_id', 
            'avatar',
            'picture',
            'photo',
            'image',
            'portrait',
            'is_avatar_empty'  // Поле для проверки наличия аватара
        ];
        
        foreach ($avatarFields as $field) {
            if (isset($yandexUser[$field]) && !empty($yandexUser[$field])) {
                $avatarId = $yandexUser[$field];
                
                // Если это ID аватара, формируем URL
                if (is_string($avatarId) && !filter_var($avatarId, FILTER_VALIDATE_URL)) {
                    return 'https://avatars.yandex.net/get-yapic/' . $avatarId . '/islands-200';
                }
                
                // Если это уже URL, возвращаем как есть
                if (filter_var($avatarId, FILTER_VALIDATE_URL)) {
                    return $avatarId;
                }
            }
        }
        
        return null;
    }

    /**
     * Получить дополнительные данные от Yandex
     */
    private function getYandexAdditionalData($yandexUser)
    {
        $data = [
            'first_name' => null,
            'last_name' => null,
            'birthday' => null,
            'phone' => null,
            'info' => []
        ];

        // Имя и фамилия (доступны с базовым scope)
        $data['first_name'] = $yandexUser['first_name'] ?? null;
        $data['last_name'] = $yandexUser['last_name'] ?? null;

        // Дата рождения (проверяем разные возможные поля)
        $birthdayFields = ['birthday', 'birth_date', 'date_of_birth'];
        foreach ($birthdayFields as $field) {
            if (isset($yandexUser[$field]) && !empty($yandexUser[$field])) {
                try {
                    // Пробуем разные форматы даты
                    $dateFormats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'Y-m-d H:i:s'];
                    foreach ($dateFormats as $format) {
                        try {
                            $data['birthday'] = \Carbon\Carbon::createFromFormat($format, $yandexUser[$field])->format('Y-m-d');
                            break;
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                    if ($data['birthday']) break;
                } catch (\Exception $e) {
                    Log::warning('Yandex birthday format error:', ['birthday' => $yandexUser[$field] ?? 'not_set', 'field' => $field]);
                }
            }
        }

        // Телефон (проверяем разные возможные поля)
        $phoneFields = ['default_phone', 'phone', 'mobile_phone', 'phone_number'];
        foreach ($phoneFields as $field) {
            if (isset($yandexUser[$field]) && !empty($yandexUser[$field])) {
                $data['phone'] = $yandexUser[$field];
                break;
            }
        }

        // Дополнительная информация (доступна с базовым scope)
        $infoFields = [
            'sex',
            'real_name',
            'display_name',
            'login',
            'psuid',
            'client_id',
            'emails'  // Список email адресов
        ];

        foreach ($infoFields as $field) {
            if (isset($yandexUser[$field])) {
                $data['info'][$field] = $yandexUser[$field];
            }
        }

        Log::info('Yandex additional data:', $data ?? []);

        return $data;
    }

    /**
     * API endpoint для получения URL авторизации Yandex
     */
    public function getYandexAuthUrl()
    {
        try {
            // Проверяем, что сессии настроены
            if (!session()->isStarted()) {
                session()->start();
            }
            
            // Используем прямой URL для Yandex OAuth
            $clientId = config('services.yandex.client_id');
            $redirectUri = config('services.yandex.redirect');
            $scope = 'login:email login:info login:avatar';
            
            $url = "https://oauth.yandex.ru/authorize?" . http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
            ]);
                
            return response()->json([
                'success' => true,
                'auth_url' => $url
            ]);
        } catch (\Exception $e) {
            Log::error('Yandex OAuth URL generation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения URL авторизации Yandex',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
