<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Log;

class VkAuthController extends Controller
{
    /**
     * Перенаправление на VK OAuth
     */
    public function redirectToVk()
    {
        try {
            // Проверяем, что сессии настроены
            if (!session()->isStarted()) {
                session()->start();
            }
            
            // Логируем конфигурацию VK
            Log::info('=== VK REDIRECT DEBUG ===');
            Log::info('VK Config:', [
                'client_id' => config('services.vkontakte.client_id'),
                'client_secret' => config('services.vkontakte.client_secret') ? 'настроен' : 'не настроен',
                'redirect_uri' => config('services.vkontakte.redirect'),
                'app_url' => config('app.url'),
                'frontend_url' => config('app.frontend_url')
            ]);
            
            // Генерируем URL для VK OAuth напрямую
            $clientId = config('services.vkontakte.client_id');
            $redirectUri = config('services.vkontakte.redirect');
            $scope = 'email';
            
            $url = "https://oauth.vk.com/authorize?" . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
                'response_type' => 'code',
                'v' => '5.199', // Обновленная версия API для OAuth 2.1
                'state' => Str::random(32) // Рекомендуется для OAuth 2.1
            ]);
            
            Log::info('Generated VK Auth URL:', ['url' => $url]);
            Log::info('=== END VK REDIRECT DEBUG ===');
            
            return redirect($url);
            
        } catch (\Exception $e) {
            Log::error('VK OAuth redirect error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка перенаправления на VK',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработка callback от VK
     */
    public function handleVkCallback(Request $request)
    {
        try {
            // Подробное логирование для отладки
            Log::info('=== VK CALLBACK DEBUG ===');
            Log::info('Request method:', ['method' => $request->method()]);
            Log::info('Request URL:', ['url' => $request->fullUrl()]);
            Log::info('Request headers:', $request->headers->all());
            Log::info('Request params:', $request->all());
            Log::info('Request query:', $request->query());
            Log::info('Request input:', $request->input());
            Log::info('Frontend URL:', ['frontend_url' => config('app.frontend_url')]);
            Log::info('App URL:', ['app_url' => config('app.url')]);
            
            // Получаем код авторизации
            $code = $request->get('code');
            
            if (!$code) {
                Log::error('VK Callback: No code received', [
                    'request_params' => $request->all(),
                    'frontend_url' => config('app.frontend_url')
                ]);
                
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/vk/callback?error=' . urlencode('Код авторизации не получен');
                return redirect($errorUrl);
            }
            
            Log::info('VK authorization code received:', ['code' => $code]);
            
            // Получаем токен доступа
            Log::info('Getting VK access token...', []);
            $tokenResponse = Http::get('https://oauth.vk.com/access_token', [
                'client_id' => config('services.vkontakte.client_id'),
                'client_secret' => config('services.vkontakte.client_secret'),
                'redirect_uri' => config('services.vkontakte.redirect'),
                'code' => $code,
            ]);
            
            $tokenData = $tokenResponse->json();
            Log::info('VK token response:', $tokenData);
            
            if (!isset($tokenData['access_token'])) {
                Log::error('VK Callback: No access token received', [
                    'token_response' => $tokenData,
                    'status' => $tokenResponse->status()
                ]);
                
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/vk/callback?error=' . urlencode('Не удалось получить токен доступа');
                return redirect($errorUrl);
            }
            
            // Получаем данные пользователя
            Log::info('Getting VK user data...', []);
            $userResponse = Http::get('https://api.vk.com/method/users.get', [
                'access_token' => $tokenData['access_token'],
                'fields' => 'email,first_name,last_name,photo',
                'v' => '5.131',
            ]);
            
            $userData = $userResponse->json();
            Log::info('VK user data response:', $userData);
            
            if (!isset($userData['response'][0])) {
                Log::error('VK Callback: No user data received', [
                    'user_response' => $userData,
                    'status' => $userResponse->status()
                ]);
                
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/vk/callback?error=' . urlencode('Не удалось получить данные пользователя');
                return redirect($errorUrl);
            }
            
            $vkUser = $userData['response'][0];
            $vkUser['email'] = $tokenData['email'] ?? null;
            
            // Логируем данные пользователя для отладки
            Log::info('VK User data:', [
                'id' => $vkUser['id'],
                'first_name' => $vkUser['first_name'] ?? null,
                'last_name' => $vkUser['last_name'] ?? null,
                'email' => $vkUser['email'],
                'photo' => $vkUser['photo'] ?? null,
                'raw_data' => $vkUser
            ]);
            
            // Проверяем, есть ли пользователь с таким VK ID
            $user = User::where('vk_id', $vkUser['id'])->first();
            
            if ($user) {
                // Пользователь уже существует, обновляем данные
                $user->update([
                    'name' => trim(($vkUser['first_name'] ?? '') . ' ' . ($vkUser['last_name'] ?? '')),
                    'email' => $vkUser['email'],
                    'avatar_url' => $vkUser['photo'] ?? null,
                    'email_verified_at' => $vkUser['email'] ? now() : null,
                    'last_login_at' => now(),
                ]);
            } else {
                // Проверяем, есть ли пользователь с таким email
                $existingUser = null;
                if ($vkUser['email']) {
                    $existingUser = User::where('email', $vkUser['email'])->first();
                }
                
                if ($existingUser) {
                    // Связываем существующего пользователя с VK
                    $existingUser->update([
                        'vk_id' => $vkUser['id'],
                        'avatar_url' => $vkUser['photo'] ?? null,
                        'email_verified_at' => $vkUser['email'] ? now() : $existingUser->email_verified_at,
                        'last_login_at' => now(),
                    ]);
                    $user = $existingUser;
                } else {
                    // Создаем нового пользователя
                    $user = User::create([
                        'name' => trim(($vkUser['first_name'] ?? '') . ' ' . ($vkUser['last_name'] ?? '')),
                        'email' => $vkUser['email'],
                        'vk_id' => $vkUser['id'],
                        'avatar_url' => $vkUser['photo'] ?? null,
                        'password' => Hash::make(Str::random(32)), // Случайный пароль
                        'email_verified_at' => $vkUser['email'] ? now() : null,
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
            $token = $user->createToken('vk-auth-token')->plainTextToken;
            
            // Получаем разрешения пользователя
            $permissions = $this->getUserPermissions($user);
            
            // Перенаправляем на фронтенд с токеном
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $redirectUrl = $frontendUrl . '/auth/vk/callback?token=' . $token . '&user=' . base64_encode(json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                'is_active' => true,
                'permissions' => $permissions,
            ]));
            
            Log::info('Redirecting to frontend:', [
                'frontend_url' => $frontendUrl,
                'redirect_url' => $redirectUrl,
                'user_id' => $user->id,
                'user_name' => $user->name
            ]);
            Log::info('=== END VK CALLBACK DEBUG ===');
            
            return redirect($redirectUrl);
            
        } catch (\Exception $e) {
            Log::error('=== VK CALLBACK ERROR ===');
            Log::error('VK OAuth callback error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
                'request_params' => $request->all(),
                'request_url' => $request->fullUrl()
            ]);
            Log::error('=== END VK CALLBACK ERROR ===');
            
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $errorUrl = $frontendUrl . '/auth/vk/callback?error=' . urlencode('Ошибка авторизации через VK');
            
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
     * API endpoint для получения URL авторизации VK
     */
    public function getVkAuthUrl()
    {
        try {
            // Проверяем, что сессии настроены
            if (!session()->isStarted()) {
                session()->start();
            }
            
            // Генерируем URL для VK OAuth напрямую
            $clientId = config('services.vkontakte.client_id');
            $redirectUri = config('services.vkontakte.redirect');
            $scope = 'email';
            
            $url = "https://oauth.vk.com/authorize?" . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
                'response_type' => 'code',
                'v' => '5.199', // Обновленная версия API для OAuth 2.1
                'state' => Str::random(32) // Рекомендуется для OAuth 2.1
            ]);
                
            return response()->json([
                'success' => true,
                'auth_url' => $url
            ]);
        } catch (\Exception $e) {
            Log::error('VK OAuth URL generation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения URL авторизации VK',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработка callback от VK ID SDK
     */
    public function handleVkSdkCallback(Request $request)
    {
        try {
            $data = $request->all();
            
            Log::info('=== VK SDK CALLBACK START ===');
            Log::info('VK SDK Callback data:', $data);
            Log::info('Request method:', ['method' => $request->method()]);
            Log::info('Request headers:', $request->headers->all());
            
            // Обрабатываем данные от VK ID SDK
            if (isset($data['access_token']) && !empty($data['access_token'])) {
                Log::info('Processing VK ID SDK access_token...');
                
                // Прямой access_token от VK ID SDK
                $accessToken = $data['access_token'];
                $email = $data['email'] ?? null;
                $userId = $data['user_id'] ?? null;
                
                Log::info('VK ID SDK access_token received:', [
                    'access_token' => substr($accessToken, 0, 20) . '...',
                    'email' => $email,
                    'user_id' => $userId,
                    'scope' => $data['scope'] ?? null
                ]);
                
                // Получаем данные пользователя через VK API
                $userResponse = Http::get('https://api.vk.com/method/users.get', [
                    'access_token' => $accessToken,
                    'fields' => 'email,first_name,last_name,photo',
                    'v' => '5.131',
                ]);
                
                $userData = $userResponse->json();
                Log::info('VK API response:', [
                    'status' => $userResponse->status(),
                    'data' => $userData
                ]);
                
                if (isset($userData['response'][0])) {
                    $vkUser = $userData['response'][0];
                    $vkUser['email'] = $email;
                    
                    // Создаем или обновляем пользователя
                    $user = $this->createOrUpdateVkUser($vkUser);
                    
                    // Создаем токен
                    $token = $user->createToken('vk-sdk-auth-token')->plainTextToken;
                    
                    // Получаем разрешения пользователя
                    $permissions = $this->getUserPermissions($user);
                    
                    Log::info('VK ID SDK: Success - user created from VK API data');
                    return response()->json([
                        'success' => true,
                        'token' => $token,
                        'user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'avatar_url' => $user->avatar_url,
                            'role' => $user->roles->first()?->name ?? 'user',
                            'is_active' => $user->is_active,
                            'permissions' => $permissions
                        ]
                    ]);
                } else {
                    Log::error('VK API: No user data in response', [
                        'user_response' => $userData,
                        'status' => $userResponse->status()
                    ]);
                    
                    // Если VK API не работает, попробуем использовать данные из токена
                    if ($userId) {
                        Log::info('Trying to create user from VK ID SDK data:', [
                            'user_id' => $userId,
                            'email' => $email
                        ]);
                        
                        // Создаем пользователя с минимальными данными
                        $user = $this->createUserFromVkSdkData($userId, $email, $data);
                        
                        if ($user) {
                            $token = $user->createToken('vk-sdk-auth-token')->plainTextToken;
                            $permissions = $this->getUserPermissions($user);
                            
                            Log::info('VK ID SDK: Success - user created from VK SDK data');
                            return response()->json([
                                'success' => true,
                                'token' => $token,
                                'user' => [
                                    'id' => $user->id,
                                    'name' => $user->name,
                                    'email' => $user->email,
                                    'avatar_url' => $user->avatar_url,
                                    'role' => $user->roles->first()?->name ?? 'user',
                                    'is_active' => $user->is_active,
                                    'permissions' => $permissions
                                ]
                            ]);
                        } else {
                            Log::error('VK ID SDK: Failed to create user from VK SDK data');
                        }
                    }
                }
                
                Log::info('VK ID SDK access_token processing completed');
            } elseif (isset($data['code']) && isset($data['device_id'])) {
                // Обмениваем код на токен через VK API
                Log::info('Exchanging VK code for token:', [
                    'code' => $data['code'],
                    'device_id' => $data['device_id'],
                    'client_id' => config('services.vkontakte.client_id'),
                    'redirect_uri' => 'https://ss75-api.kirhtarg.ru/api/auth/vk/sdk-callback'
                ]);
                
                $tokenResponse = Http::post('https://oauth.vk.com/access_token', [
                    'client_id' => config('services.vkontakte.client_id'),
                    'client_secret' => config('services.vkontakte.client_secret'),
                    'redirect_uri' => 'https://ss75-api.kirhtarg.ru/api/auth/vk/sdk-callback',
                    'code' => $data['code'],
                ]);
                
                $tokenData = $tokenResponse->json();
                Log::info('VK token exchange response:', [
                    'status' => $tokenResponse->status(),
                    'data' => $tokenData
                ]);
                
                if (isset($tokenData['access_token'])) {
                    $accessToken = $tokenData['access_token'];
                    $email = $tokenData['email'] ?? null;
                    
                    Log::info('VK access token received:', [
                        'access_token' => substr($accessToken, 0, 20) . '...',
                        'email' => $email
                    ]);
                    
                    // Получаем данные пользователя через VK API
                    $userResponse = Http::get('https://api.vk.com/method/users.get', [
                        'access_token' => $accessToken,
                        'fields' => 'email,first_name,last_name,photo',
                        'v' => '5.131',
                    ]);
                    
                    $userData = $userResponse->json();
                    Log::info('VK API response:', [
                        'status' => $userResponse->status(),
                        'data' => $userData
                    ]);
                    
                    if (isset($userData['response'][0])) {
                        $vkUser = $userData['response'][0];
                        $vkUser['email'] = $email;
                        
                        // Создаем или обновляем пользователя
                        $user = $this->createOrUpdateVkUser($vkUser);
                        
                        // Создаем токен
                        $token = $user->createToken('vk-sdk-auth-token')->plainTextToken;
                        
                        // Получаем разрешения пользователя
                        $permissions = $this->getUserPermissions($user);
                        
                        return response()->json([
                            'success' => true,
                            'token' => $token,
                            'user' => [
                                'id' => $user->id,
                                'name' => $user->name,
                                'email' => $user->email,
                                'avatar_url' => $user->avatar_url,
                                'role' => $user->roles->first()?->name ?? 'user',
                                'is_active' => $user->is_active,
                                'permissions' => $permissions
                            ]
                        ]);
                    } else {
                        Log::error('VK API: No user data in response', [
                            'user_response' => $userData,
                            'status' => $userResponse->status()
                        ]);
                    }
                } else {
                    Log::error('VK token exchange failed:', [
                        'token_response' => $tokenData,
                        'status' => $tokenResponse->status()
                    ]);
                }
            }
            
            Log::error('VK SDK: No valid data processed', [
                'received_data' => $data,
                'has_access_token' => isset($data['access_token']),
                'has_code' => isset($data['code']),
                'has_device_id' => isset($data['device_id']),
                'access_token_present' => !empty($data['access_token']),
                'user_id_present' => !empty($data['user_id'])
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Не удалось получить данные пользователя от VK'
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('=== VK SDK CALLBACK ERROR ===');
            Log::error('VK SDK callback error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            Log::error('=== END VK SDK CALLBACK ERROR ===');
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка авторизации через VK ID SDK',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Создание или обновление пользователя VK
     */
    private function createOrUpdateVkUser($vkUser)
    {
        // Проверяем, есть ли пользователь с таким VK ID
        $user = User::where('vk_id', $vkUser['id'])->first();
        
        if ($user) {
            // Пользователь уже существует, обновляем данные
            $user->update([
                'name' => trim(($vkUser['first_name'] ?? '') . ' ' . ($vkUser['last_name'] ?? '')),
                'email' => $vkUser['email'],
                'avatar_url' => $vkUser['photo'] ?? null,
                'email_verified_at' => $vkUser['email'] ? now() : null,
                'last_login_at' => now(),
            ]);
        } else {
            // Проверяем, есть ли пользователь с таким email
            $existingUser = null;
            if ($vkUser['email']) {
                $existingUser = User::where('email', $vkUser['email'])->first();
            }
            
            if ($existingUser) {
                // Связываем существующего пользователя с VK
                $existingUser->update([
                    'vk_id' => $vkUser['id'],
                    'avatar_url' => $vkUser['photo'] ?? null,
                    'email_verified_at' => $vkUser['email'] ? now() : $existingUser->email_verified_at,
                    'last_login_at' => now(),
                ]);
                $user = $existingUser;
            } else {
                // Создаем нового пользователя
                $user = User::create([
                    'name' => trim(($vkUser['first_name'] ?? '') . ' ' . ($vkUser['last_name'] ?? '')),
                    'email' => $vkUser['email'],
                    'vk_id' => $vkUser['id'],
                    'avatar_url' => $vkUser['photo'] ?? null,
                    'password' => Hash::make(Str::random(32)), // Случайный пароль
                    'email_verified_at' => $vkUser['email'] ? now() : null,
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
        
        return $user;
    }
    
    /**
     * Создание пользователя из данных VK ID SDK
     */
    private function createUserFromVkSdkData($vkId, $email, $data)
    {
        try {
            // Проверяем, есть ли пользователь с таким VK ID
            $user = User::where('vk_id', $vkId)->first();
            
            if ($user) {
                // Пользователь уже существует, обновляем данные
                $user->update([
                    'email' => $email,
                    'email_verified_at' => $email ? now() : null,
                    'last_login_at' => now(),
                ]);
            } else {
                // Проверяем, есть ли пользователь с таким email
                $existingUser = null;
                if ($email) {
                    $existingUser = User::where('email', $email)->first();
                }
                
                if ($existingUser) {
                    // Связываем существующего пользователя с VK
                    $existingUser->update([
                        'vk_id' => $vkId,
                        'email_verified_at' => $email ? now() : $existingUser->email_verified_at,
                        'last_login_at' => now(),
                    ]);
                    $user = $existingUser;
                } else {
                    // Создаем нового пользователя
                    $user = User::create([
                        'name' => 'VK User ' . $vkId, // Временное имя
                        'email' => $email,
                        'vk_id' => $vkId,
                        'password' => Hash::make(Str::random(32)), // Случайный пароль
                        'email_verified_at' => $email ? now() : null,
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
            
            return $user;
        } catch (\Exception $e) {
            Log::error('Error creating user from VK SDK data: ' . $e->getMessage());
            return null;
        }
    }
}
