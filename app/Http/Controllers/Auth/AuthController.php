<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationMail;
use App\Models\User;
use App\Services\SiteInfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Регистрация пользователя с кодами (для checkout)
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // НЕ создаем пользователя сразу - сохраняем данные во временном хранилище
            $tempUserData = [
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'created_at' => now(),
            ];

            // Генерируем 4-значный код для подтверждения email
            $verificationCode = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

            // Сохраняем данные пользователя и код в кеше
            \Illuminate\Support\Facades\Cache::put(
                'temp_user_data_'.$request->email,
                $tempUserData,
                now()->addMinutes(15) // Данные действительны 15 минут
            );

            \Illuminate\Support\Facades\Cache::put(
                'email_verification_code_'.$request->email,
                $verificationCode,
                now()->addMinutes(15) // Код действителен 15 минут
            );

            // Создаем временный объект пользователя для отправки email
            $tempUser = new User($tempUserData);

            // Отправляем email с кодом подтверждения
            try {
                $this->sendVerificationCodeEmail($tempUser, $verificationCode);
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем регистрацию
                Log::error('Email sending failed: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Регистрация успешна! Проверьте email для подтверждения аккаунта.',
                'user' => [
                    'name' => $tempUserData['name'],
                    'email' => $tempUserData['email'],
                    'email_verified_at' => null,
                ],
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка регистрации',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Регистрация пользователя с автоматической генерацией логина и пароля (для формы "Присоединяйтесь к нам!")
     */
    public function registerWithCode(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'nullable|string|min:8|confirmed', // Пароль опционален (для быстрой регистрации)
                'phone' => 'nullable|string|max:20', // Телефон опционален
            ]);

            // Если пароль передан, используем его (для регистрации в чекауте)
            // Если пароль не передан, генерируем логин и пароль (для быстрой регистрации)
            $hasPassword = $request->has('password') && ! empty($request->password);

            if ($hasPassword) {
                // Регистрация в чекауте - используем пароль пользователя
                $tempUserData = [
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'phone' => $request->phone ?? null, // Сохраняем телефон если указан
                    'created_at' => now(),
                ];
            } else {
                // Быстрая регистрация - генерируем логин и пароль
                $login = $this->generateLogin($request->name, $request->email);
                $password = $this->generatePassword();

                $tempUserData = [
                    'name' => $request->name,
                    'email' => $request->email,
                    'login' => $login,
                    'password' => Hash::make($password),
                    'original_password' => $password, // Сохраняем оригинальный пароль для письма
                    'phone' => $request->phone ?? null, // Сохраняем телефон если указан
                    'created_at' => now(),
                ];
            }

            // Генерируем 4-значный код для подтверждения email
            $verificationCode = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

            // Сохраняем данные пользователя и код в кеше
            \Illuminate\Support\Facades\Cache::put(
                'temp_user_data_'.$request->email,
                $tempUserData,
                now()->addMinutes(15) // Данные действительны 15 минут
            );

            \Illuminate\Support\Facades\Cache::put(
                'email_verification_code_'.$request->email,
                $verificationCode,
                now()->addMinutes(15) // Код действителен 15 минут
            );

            // Создаем временный объект пользователя для отправки email
            $tempUser = new User($tempUserData);

            // Отправляем email с кодом подтверждения
            try {
                $this->sendVerificationCodeEmail($tempUser, $verificationCode);
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем регистрацию
                Log::error('Email sending failed: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Регистрация успешна! Проверьте email для подтверждения аккаунта.',
                'user' => [
                    'name' => $tempUserData['name'],
                    'email' => $tempUserData['email'],
                    'email_verified_at' => null,
                ],
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка регистрации',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Регистрация пользователя с токенами (для обычной регистрации)
     */
    public function registerWithToken(Request $request): JsonResponse
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
                    'is_active' => true,
                    'assigned_at' => now(),
                ]);
            }

            // Генерируем токен для подтверждения email
            $verificationToken = Str::random(64);

            // Сохраняем токен в базе данных (можно использовать кеш или отдельную таблицу)
            \Illuminate\Support\Facades\Cache::put(
                'email_verification_'.$verificationToken,
                $user->id,
                now()->addHours(24) // Токен действителен 24 часа
            );

            // Отправляем email с подтверждением
            try {
                $this->sendVerificationEmail($user, $verificationToken);
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем регистрацию
                Log::error('Email sending failed: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Регистрация успешна! Проверьте email для подтверждения аккаунта.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                ],
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка регистрации',
                'error' => $e->getMessage(),
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

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден',
                    'debug' => 'Пользователь с email/именем "'.$loginField.'" не найден',
                ], 401);
            }

            if (! Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный пароль',
                    'debug' => 'Пароль не совпадает для пользователя '.$user->name,
                ], 401);
            }

            // Проверяем, что пользователь не заблокирован
            if ($user->is_active === 0 || $user->is_active === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ваш аккаунт заблокирован. Обратитесь к администратору.',
                    'error' => 'account_blocked',
                ], 403);
            }

            // Проверяем подтверждение email
            if (! $user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email не подтвержден',
                    'error' => 'email_not_verified',
                    'user_email' => $user->email,
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
                    'avatar_url' => $user->avatar_url,
                    'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                    'email_verified_at' => now(),
                    'permissions' => $permissions,
                    'last_login_at' => null,
                ],
                'token' => $token,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка авторизации',
                'error' => $e->getMessage(),
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
                'message' => 'Успешный выход',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при выходе',
                'error' => $e->getMessage(),
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
                    'avatar_url' => $user->avatar_url,
                    'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                    'email_verified_at' => now(),
                    'permissions' => $permissions,
                    'last_login_at' => null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения данных пользователя',
                'error' => $e->getMessage(),
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
            'authenticated' => true,
        ]);
    }

    /**
     * Подтверждение email
     * Поддерживает два формата:
     * 1. Старый формат: token (для registerWithToken)
     * 2. Новый формат: email + code (для registerWithCode)
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        try {
            // Определяем, какой формат используется
            // Приоритет: сначала проверяем новый формат (email + code), так как он используется чаще
            if ($request->has('email') && $request->has('code')) {
                // Новый формат: верификация по email и коду
                return $this->verifyEmailByCode($request);
            } elseif ($request->has('token')) {
                // Старый формат: верификация по токену
                return $this->verifyEmailByToken($request);
            } else {
                // Не указан ни один из форматов
                return response()->json([
                    'success' => false,
                    'message' => 'Необходимо указать либо email и код, либо токен',
                    'errors' => [
                        'email' => ['Email обязателен для подтверждения'],
                        'code' => ['Код обязателен для подтверждения'],
                        'token' => ['Токен обязателен для подтверждения'],
                    ],
                ], 422);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка подтверждения email',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Верификация по токену (старый формат)
     */
    private function verifyEmailByToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $token = $request->token;

        // Получаем ID пользователя из кеша по токену
        $userId = \Illuminate\Support\Facades\Cache::get('email_verification_'.$token);

        if (! $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный или истекший токен подтверждения',
            ], 400);
        }

        // Находим пользователя
        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не найден',
            ], 404);
        }

        // Проверяем, не подтвержден ли уже email
        if ($user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Email уже подтвержден',
            ], 400);
        }

        // Подтверждаем email
        $user->email_verified_at = now();
        $user->save();

        // Начисляем приветственные бонусы
        $this->awardWelcomeBonuses($user);

        // Отправляем приветственное письмо после подтверждения email
        try {
            $this->sendWelcomeEmail($user);
        } catch (\Exception $e) {
            Log::error('Welcome email sending failed: '.$e->getMessage());
        }

        // Удаляем токен из кеша
        \Illuminate\Support\Facades\Cache::forget('email_verification_'.$token);

        // Создаем токен для пользователя
        $authToken = $user->createToken('auth-token')->plainTextToken;

        // Получаем разрешения пользователя
        $permissions = $this->getUserPermissions($user);

        return response()->json([
            'success' => true,
            'message' => 'Email успешно подтвержден!',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                    'permissions' => $permissions,
                ],
                'token' => $authToken,
            ],
        ]);
    }

    /**
     * Верификация по email и коду (новый формат)
     */
    private function verifyEmailByCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:4',
        ]);

        $email = $request->email;
        $code = $request->code;

        // Получаем сохраненный код из кеша
        $cachedCode = \Illuminate\Support\Facades\Cache::get('email_verification_code_'.$email);

        if (! $cachedCode || $cachedCode !== $code) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный или истекший код подтверждения',
            ], 400);
        }

        // Получаем временные данные пользователя из кеша
        $tempUserData = \Illuminate\Support\Facades\Cache::get('temp_user_data_'.$email);

        if (! $tempUserData) {
            return response()->json([
                'success' => false,
                'message' => 'Данные регистрации истекли. Пожалуйста, зарегистрируйтесь заново.',
            ], 400);
        }

        // Проверяем, не существует ли уже пользователь с таким email
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь с таким email уже существует',
            ], 400);
        }

        // Создаем пользователя в базе данных
        $userData = [
            'name' => $tempUserData['name'],
            'email' => $tempUserData['email'],
            'password' => $tempUserData['password'],
            'email_verified_at' => now(), // Сразу подтверждаем email
        ];

        // Добавляем телефон, если он был указан
        if (isset($tempUserData['phone']) && ! empty($tempUserData['phone'])) {
            $userData['phone'] = $tempUserData['phone'];
        }

        $user = User::create($userData);

        // Привязываем роль 'user' по умолчанию
        $userRole = \App\Models\Role::where('name', 'user')->first();
        if ($userRole) {
            $user->roles()->attach($userRole->id, [
                'is_active' => true,
                'assigned_at' => now(),
            ]);
        }

        // Начисляем приветственные бонусы
        $this->awardWelcomeBonuses($user);

        // Отправляем письма в зависимости от типа регистрации
        if (isset($tempUserData['login']) && isset($tempUserData['original_password'])) {
            // Быстрая регистрация - отправляем письмо с учетными данными
            try {
                $this->sendCredentialsEmail($user, $tempUserData['login'], $tempUserData['original_password']);
            } catch (\Exception $e) {
                Log::error('Credentials email sending failed: '.$e->getMessage());
            }

            // Также отправляем приветственное письмо для быстрой регистрации
            try {
                $this->sendWelcomeEmail($user);
            } catch (\Exception $e) {
                Log::error('Welcome email sending failed: '.$e->getMessage());
            }
        } else {
            // Регистрация в чекауте (с введенным паролем) - отправляем только приветственное письмо
            try {
                $this->sendWelcomeEmail($user);
            } catch (\Exception $e) {
                Log::error('Welcome email sending failed: '.$e->getMessage());
            }
        }

        // Создаем токен для пользователя
        $token = $user->createToken('auth-token')->plainTextToken;

        // Получаем разрешения пользователя
        $permissions = $this->getUserPermissions($user);

        // Удаляем временные данные и код из кеша
        \Illuminate\Support\Facades\Cache::forget('temp_user_data_'.$email);
        \Illuminate\Support\Facades\Cache::forget('email_verification_code_'.$email);

        return response()->json([
            'success' => true,
            'message' => 'Email успешно подтвержден! Регистрация завершена.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'email_verified_at' => $user->email_verified_at,
                    'role' => $user->roles->first() ? $user->roles->first()->name : 'user',
                    'permissions' => $permissions,
                ],
                'token' => $token,
            ],
        ]);
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
                    'message' => 'Email уже подтвержден',
                ], 400);
            }

            // Генерируем новый 4-значный код
            $verificationCode = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

            // Сохраняем код в кеше
            \Illuminate\Support\Facades\Cache::put(
                'email_verification_code_'.$user->email,
                $verificationCode,
                now()->addMinutes(15)
            );

            // Отправляем email с кодом
            $this->sendVerificationCodeEmail($user, $verificationCode);

            return response()->json([
                'success' => true,
                'message' => 'Письмо с кодом подтверждения отправлено повторно',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка отправки письма',
                'error' => $e->getMessage(),
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
                'message' => $exists ? 'Email найден' : 'Email свободен',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки email',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Проверка телефона на уникальность
     */
    public function checkPhone(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone' => 'required|string',
            ]);

            $phone = $request->phone;
            // Нормализуем телефон для проверки (убираем все нецифровые символы кроме +)
            $normalizedPhone = preg_replace('/[^\d+]/', '', $phone);
            // Если начинается с 8, заменяем на +7
            if (str_starts_with($normalizedPhone, '8')) {
                $normalizedPhone = '+7'.substr($normalizedPhone, 1);
            } elseif (str_starts_with($normalizedPhone, '7')) {
                $normalizedPhone = '+'.$normalizedPhone;
            } elseif (! str_starts_with($normalizedPhone, '+')) {
                $normalizedPhone = '+7'.$normalizedPhone;
            }

            $exists = User::where('phone', $normalizedPhone)->exists();

            return response()->json([
                'success' => true,
                'exists' => $exists,
                'message' => $exists ? 'Телефон найден' : 'Телефон свободен',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки телефона',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Отправить email с кодом подтверждения
     */
    private function sendVerificationCodeEmail(User $user, string $code): void
    {
        // Получаем информацию о сайте
        $siteInfo = SiteInfoService::getSiteInfoForEmail();

        // Получаем информацию о приветственных бонусах
        $bonusesAtReg = \App\Models\Setting::where('key', 'bonuses_at_reg')->first();
        $bonusAmount = ($bonusesAtReg && $bonusesAtReg->value && $bonusesAtReg->value > 0)
            ? (int) $bonusesAtReg->value
            : 0;

        // Отправляем простое письмо с кодом
        Mail::send('emails.verification-code', [
            'user' => $user,
            'code' => $code,
            'siteInfo' => $siteInfo,
            'bonusAmount' => $bonusAmount,
        ], function ($message) use ($user, $siteInfo) {
            $message->to($user->email, $user->name)
                ->subject('Код подтверждения регистрации - '.($siteInfo['site_name'] ?? 'Skate & Snow'));
        });
    }

    /**
     * Отправить email с подтверждением (для обычной регистрации)
     */
    private function sendVerificationEmail(User $user, string $token): void
    {
        $verificationUrl = config('app.frontend_url').'/verify-email?token='.$token;

        // Получаем информацию о сайте
        $siteInfo = SiteInfoService::getSiteInfoForEmail();

        // Отправляем красивое HTML письмо
        Mail::send(new EmailVerificationMail($user, $verificationUrl, $siteInfo));
    }

    /**
     * Начислить приветственные бонусы пользователю
     */
    private function awardWelcomeBonuses(User $user): void
    {
        try {
            // Проверяем, были ли уже начислены приветственные бонусы
            // Проверяем наличие транзакции с registration_bonus в metadata
            $existingRegistrationBonus = \App\Models\UserBonusTransaction::where('user_id', $user->id)
                ->where('type', 'earn')
                ->whereJsonContains('metadata->registration_bonus', true)
                ->first();

            if ($existingRegistrationBonus) {
                Log::info('Welcome bonuses not awarded: user already has registration bonus transaction', [
                    'user_id' => $user->id,
                    'transaction_id' => $existingRegistrationBonus->id,
                ]);

                return; // Приветственные бонусы уже начислены, не начисляем повторно
            }

            // Получаем значение bonuses_at_reg из настроек
            $bonusesAtReg = \App\Models\Setting::where('key', 'bonuses_at_reg')->first();

            if (! $bonusesAtReg || ! $bonusesAtReg->value || $bonusesAtReg->value <= 0) {
                Log::info('Welcome bonuses not awarded: setting not found or value is 0', [
                    'user_id' => $user->id,
                    'setting_value' => $bonusesAtReg?->value ?? 'not found',
                ]);

                return; // Если настройка не найдена или равна 0, не начисляем бонусы
            }

            $bonusAmount = (int) $bonusesAtReg->value;

            // Используем модель UserBonus для правильного создания записи и транзакции
            $userBonus = \App\Models\UserBonus::getOrCreateForUser($user->id);

            // Добавляем бонусы через метод addPoints, который создаст транзакцию
            $userBonus->addPoints(
                $bonusAmount,
                'Приветственные бонусы за регистрацию',
                null, // order_id
                null, // expires_at (без срока действия для приветственных бонусов)
                ['registration_bonus' => true, 'bonuses_at_reg' => $bonusAmount]
            );

            Log::info('Welcome bonuses awarded successfully', [
                'user_id' => $user->id,
                'bonus_amount' => $bonusAmount,
            ]);

        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем регистрацию
            Log::error('Error awarding welcome bonuses: '.$e->getMessage(), [
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);
        }
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

    /**
     * Генерировать логин на основе имени и email
     */
    private function generateLogin(string $name, string $email): string
    {
        // Берем часть email до @ и добавляем случайные цифры
        $emailPart = explode('@', $email)[0];
        $namePart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));

        // Ограничиваем длину
        $namePart = substr($namePart, 0, 8);
        $emailPart = substr($emailPart, 0, 6);

        // Добавляем случайные цифры
        $randomNumbers = random_int(100, 999);

        return $namePart.$emailPart.$randomNumbers;
    }

    /**
     * Генерировать пароль
     */
    private function generatePassword(): string
    {
        // Генерируем пароль из 8 символов: буквы (верхний и нижний регистр) + цифры
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';

        $password = '';

        // Добавляем минимум по одному символу каждого типа
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];

        // Заполняем остальные 5 символов случайно
        $allChars = $uppercase.$lowercase.$numbers;
        for ($i = 0; $i < 5; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Перемешиваем символы
        return str_shuffle($password);
    }

    /**
     * Отправить email с учетными данными
     */
    private function sendCredentialsEmail(User $user, string $login, string $password): void
    {
        // Получаем информацию о сайте
        $siteInfo = SiteInfoService::getSiteInfoForEmail();

        // Получаем информацию о приветственных бонусах
        $bonusesAtReg = \App\Models\Setting::where('key', 'bonuses_at_reg')->first();
        $bonusAmount = ($bonusesAtReg && $bonusesAtReg->value && $bonusesAtReg->value > 0)
            ? (int) $bonusesAtReg->value
            : 0;

        // Отправляем письмо с учетными данными
        Mail::send('emails.credentials', [
            'user' => $user,
            'login' => $login,
            'password' => $password,
            'siteInfo' => $siteInfo,
            'bonusAmount' => $bonusAmount,
        ], function ($message) use ($user, $siteInfo) {
            $message->to($user->email, $user->name)
                ->subject('Ваши учетные данные - '.($siteInfo['site_name'] ?? 'Skate & Snow'));
        });
    }

    /**
     * Отправить приветственное письмо об успешной регистрации (для регистрации в чекауте)
     */
    private function sendWelcomeEmail(User $user): void
    {
        // Получаем информацию о сайте
        $siteInfo = SiteInfoService::getSiteInfoForEmail();

        // Получаем информацию о приветственных бонусах
        $bonusesAtReg = \App\Models\Setting::where('key', 'bonuses_at_reg')->first();
        $bonusAmount = ($bonusesAtReg && $bonusesAtReg->value && $bonusesAtReg->value > 0)
            ? (int) $bonusesAtReg->value
            : 0;

        // Отправляем приветственное письмо
        Mail::send('emails.welcome', [
            'user' => $user,
            'siteInfo' => $siteInfo,
            'bonusAmount' => $bonusAmount,
        ], function ($message) use ($user, $siteInfo) {
            $message->to($user->email, $user->name)
                ->subject('Добро пожаловать на '.($siteInfo['site_name'] ?? 'Skate & Snow').'!');
        });
    }

    // ========== PASSWORD RECOVERY METHODS ==========

    /**
     * Отправить код для восстановления пароля
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
            ]);

            $email = $request->email;

            // Проверяем, существует ли пользователь
            $user = User::where('email', $email)->first();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь с таким email не найден',
                ], 404);
            }

            // Проверяем, зарегистрирован ли пользователь через соцсети или телефон
            $authMethods = [];

            if (! empty($user->google_id)) {
                $authMethods[] = 'google';
            }
            if (! empty($user->vk_id)) {
                $authMethods[] = 'vk';
            }
            if (! empty($user->yandex_id)) {
                $authMethods[] = 'yandex';
            }
            if (! empty($user->phone_verified_at)) {
                $authMethods[] = 'phone';
            }

            // Если пользователь зарегистрирован через соцсеть/телефон и НЕ имеет обычного пароля
            // (проверяем, был ли когда-либо установлен пароль вручную)
            if (! empty($authMethods)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Этот аккаунт зарегистрирован через другой способ авторизации',
                    'auth_methods' => $authMethods,
                    'error' => 'social_auth_account',
                ], 400);
            }

            // Генерируем 6-значный код
            $resetCode = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

            // Сохраняем код в кеше (действителен 15 минут)
            \Illuminate\Support\Facades\Cache::put(
                'password_reset_code_'.$email,
                $resetCode,
                now()->addMinutes(15)
            );

            // Отправляем email с кодом
            try {
                $this->sendPasswordResetCodeEmail($user, $resetCode);
            } catch (\Exception $e) {
                Log::error('Password reset email sending failed: '.$e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка отправки письма. Попробуйте позже.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Код для восстановления пароля отправлен на email',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка отправки кода',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Проверить код восстановления пароля
     */
    public function verifyPasswordResetCode(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'code' => 'required|string|size:6',
            ]);

            $email = $request->email;
            $code = $request->code;

            // Получаем сохраненный код из кеша
            $cachedCode = \Illuminate\Support\Facades\Cache::get('password_reset_code_'.$email);

            if (! $cachedCode || $cachedCode !== $code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный или истекший код',
                ], 400);
            }

            // Генерируем токен для сброса пароля
            $resetToken = Str::random(64);

            // Сохраняем токен в кеше (действителен 15 минут)
            \Illuminate\Support\Facades\Cache::put(
                'password_reset_token_'.$email,
                $resetToken,
                now()->addMinutes(15)
            );

            // Удаляем код из кеша
            \Illuminate\Support\Facades\Cache::forget('password_reset_code_'.$email);

            return response()->json([
                'success' => true,
                'message' => 'Код подтвержден',
                'token' => $resetToken,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки кода',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Сбросить пароль
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'token' => 'required|string',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $email = $request->email;
            $token = $request->token;

            // Проверяем токен
            $cachedToken = \Illuminate\Support\Facades\Cache::get('password_reset_token_'.$email);

            if (! $cachedToken || $cachedToken !== $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недействительный токен сброса пароля. Попробуйте снова.',
                ], 400);
            }

            // Находим пользователя
            $user = User::where('email', $email)->first();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден',
                ], 404);
            }

            // Обновляем пароль
            $user->password = Hash::make($request->password);
            $user->save();

            // Удаляем токен из кеша
            \Illuminate\Support\Facades\Cache::forget('password_reset_token_'.$email);

            // Отправляем уведомление об изменении пароля
            try {
                $this->sendPasswordChangedEmail($user);
            } catch (\Exception $e) {
                Log::error('Password changed notification email failed: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Пароль успешно изменён',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка сброса пароля',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Отправить email с кодом восстановления пароля
     */
    private function sendPasswordResetCodeEmail(User $user, string $code): void
    {
        // Получаем информацию о сайте
        $siteInfo = SiteInfoService::getSiteInfoForEmail();

        // Отправляем письмо с кодом
        Mail::send('emails.password-reset-code', [
            'user' => $user,
            'code' => $code,
            'siteInfo' => $siteInfo,
        ], function ($message) use ($user, $siteInfo) {
            $message->to($user->email, $user->name)
                ->subject('Код для восстановления пароля - '.($siteInfo['site_name'] ?? 'Skate & Snow'));
        });
    }

    /**
     * Отправить уведомление об изменении пароля
     */
    private function sendPasswordChangedEmail(User $user): void
    {
        // Получаем информацию о сайте
        $siteInfo = SiteInfoService::getSiteInfoForEmail();

        // Отправляем уведомление
        Mail::send('emails.password-changed', [
            'user' => $user,
            'siteInfo' => $siteInfo,
        ], function ($message) use ($user, $siteInfo) {
            $message->to($user->email, $user->name)
                ->subject('Пароль изменён - '.($siteInfo['site_name'] ?? 'Skate & Snow'));
        });
    }
}
