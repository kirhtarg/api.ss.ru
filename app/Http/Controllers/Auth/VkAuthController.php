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
            
            // Генерируем URL для VK OAuth
            $url = Socialite::driver('vkontakte')
                ->scopes(['email'])
                ->redirect()
                ->getTargetUrl();
            
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
            
            // Получаем пользователя через Socialite
            Log::info('Getting VK user via Socialite...');
            $vkUser = Socialite::driver('vkontakte')->user();
            
            // Логируем данные пользователя для отладки
            Log::info('VK User data:', [
                'id' => $vkUser->getId(),
                'name' => $vkUser->getName(),
                'email' => $vkUser->getEmail(),
                'avatar' => $vkUser->getAvatar(),
                'nickname' => $vkUser->getNickname(),
                'raw' => $vkUser->getRaw()
            ]);
            
            // Проверяем, есть ли пользователь с таким VK ID
            $user = User::where('vk_id', $vkUser->getId())->first();
            
            if ($user) {
                // Пользователь уже существует, обновляем данные
                $user->update([
                    'name' => $vkUser->getName(),
                    'email' => $vkUser->getEmail(),
                    'avatar_url' => $vkUser->getAvatar(),
                    'email_verified_at' => $vkUser->getEmail() ? now() : null,
                    'last_login_at' => now(),
                ]);
            } else {
                // Проверяем, есть ли пользователь с таким email
                $existingUser = null;
                if ($vkUser->getEmail()) {
                    $existingUser = User::where('email', $vkUser->getEmail())->first();
                }
                
                if ($existingUser) {
                    // Связываем существующего пользователя с VK
                    $existingUser->update([
                        'vk_id' => $vkUser->getId(),
                        'avatar_url' => $vkUser->getAvatar(),
                        'email_verified_at' => $vkUser->getEmail() ? now() : $existingUser->email_verified_at,
                        'last_login_at' => now(),
                    ]);
                    $user = $existingUser;
                } else {
                    // Создаем нового пользователя
                    $user = User::create([
                        'name' => $vkUser->getName(),
                        'email' => $vkUser->getEmail(),
                        'vk_id' => $vkUser->getId(),
                        'avatar_url' => $vkUser->getAvatar(),
                        'password' => Hash::make(Str::random(32)), // Случайный пароль
                        'email_verified_at' => $vkUser->getEmail() ? now() : null,
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
            
            // Используем Socialite для генерации URL
            $url = Socialite::driver('vkontakte')
                ->scopes(['email'])
                ->redirect()
                ->getTargetUrl();
                
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
            // Логирование для отладки
            Log::info('VK SDK Callback received', [
                'method' => $request->method(),
                'all_params' => $request->all(),
                'query_params' => $request->query(),
                'post_params' => $request->post()
            ]);
            
            // Для GET запросов возвращаем HTML страницу с JavaScript
            if ($request->isMethod('GET')) {
                $code = $request->query('code');
                $deviceId = $request->query('device_id');
                
                if (!$code) {
                    return response()->view('auth.vk-sdk-callback', [
                        'error' => 'Код авторизации не получен',
                        'success' => false
                    ]);
                }
                
                // Возвращаем HTML страницу, которая отправит POST запрос
                return response()->view('auth.vk-sdk-callback', [
                    'code' => $code,
                    'deviceId' => $deviceId,
                    'success' => true
                ]);
            }
            
            $code = $request->input('code');
            $deviceId = $request->input('device_id');
            
            if (!$code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Код авторизации не получен'
                ], 400);
            }
            
            // Обмениваем код на токен через VK API
            $tokenResponse = \Http::post('https://oauth.vk.com/access_token', [
                'client_id' => config('services.vkontakte.client_id'),
                'client_secret' => config('services.vkontakte.client_secret'),
                'redirect_uri' => config('services.vkontakte.redirect'),
                'code' => $code,
            ]);
            
            $tokenData = $tokenResponse->json();
            
            if (!isset($tokenData['access_token'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить токен доступа от VK',
                    'error' => $tokenData['error_description'] ?? 'Unknown error'
                ], 400);
            }
            
            // Получаем данные пользователя
            $userResponse = \Http::get('https://api.vk.com/method/users.get', [
                'access_token' => $tokenData['access_token'],
                'fields' => 'email,first_name,last_name,photo',
                'v' => '5.131',
            ]);
            
            $userData = $userResponse->json();
            
            if (!isset($userData['response'][0])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить данные пользователя от VK',
                    'error' => $userData['error']['error_msg'] ?? 'Unknown error'
                ], 400);
            }
            
            $vkUser = $userData['response'][0];
            $vkUser['email'] = $tokenData['email'] ?? null;
            
            // Проверяем, есть ли пользователь с таким VK ID
            $user = User::where('vk_id', $vkUser['id'])->first();
            
            if ($user) {
                // Пользователь уже существует, обновляем данные
                $user->update([
                    'name' => trim($vkUser['first_name'] . ' ' . $vkUser['last_name']),
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
                        'name' => trim($vkUser['first_name'] . ' ' . $vkUser['last_name']),
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
            $token = $user->createToken('vk-sdk-auth-token')->plainTextToken;
            
            // Получаем разрешения пользователя
            $permissions = $this->getUserPermissions($user);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar_url' => $user->avatar_url,
                        'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                        'is_active' => true,
                        'permissions' => $permissions,
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('VK SDK callback error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка авторизации через VK ID SDK',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
