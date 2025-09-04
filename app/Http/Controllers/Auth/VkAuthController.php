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
            
            // Используем прямой URL для VK OAuth
            $clientId = config('services.vkontakte.client_id');
            $redirectUri = config('services.vkontakte.redirect');
            $scope = 'email';
            
            $url = "https://oauth.vk.com/authorize?" . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
                'response_type' => 'code',
                'v' => '5.131'
            ]);
            
            return redirect($url);
            
        } catch (\Exception $e) {
            Log::error('VK OAuth redirect error: ' . $e->getMessage());
            
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
            $code = $request->get('code');
            
            if (!$code) {
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/vk/callback?error=' . urlencode('Код авторизации не получен');
                return redirect($errorUrl);
            }
            
            // Получаем токен
            $tokenResponse = \Http::get('https://oauth.vk.com/access_token', [
                'client_id' => config('services.vkontakte.client_id'),
                'client_secret' => config('services.vkontakte.client_secret'),
                'redirect_uri' => config('services.vkontakte.redirect'),
                'code' => $code,
            ]);
            
            $tokenData = $tokenResponse->json();
            
            if (!isset($tokenData['access_token'])) {
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/vk/callback?error=' . urlencode('Не удалось получить токен доступа');
                return redirect($errorUrl);
            }
            
            // Получаем данные пользователя
            $userResponse = \Http::get('https://api.vk.com/method/users.get', [
                'access_token' => $tokenData['access_token'],
                'fields' => 'email,first_name,last_name,photo',
                'v' => '5.131',
            ]);
            
            $userData = $userResponse->json();
            
            if (!isset($userData['response'][0])) {
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                $errorUrl = $frontendUrl . '/auth/vk/callback?error=' . urlencode('Не удалось получить данные пользователя');
                return redirect($errorUrl);
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
            
            return redirect($redirectUrl);
            
        } catch (\Exception $e) {
            Log::error('VK OAuth callback error: ' . $e->getMessage());
            
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
        
        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[] = $permission->name;
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
            
            // Используем прямой URL для VK OAuth
            $clientId = config('services.vkontakte.client_id');
            $redirectUri = config('services.vkontakte.redirect');
            $scope = 'email';
            
            $url = "https://oauth.vk.com/authorize?" . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
                'response_type' => 'code',
                'v' => '5.131'
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
}
