<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Регистрируем кастомные middleware
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'shop.access' => \App\Http\Middleware\CheckShopAccess::class,
            'cors' => \App\Http\Middleware\CustomCors::class,
            'options.cors' => \App\Http\Middleware\OptionsCors::class,
            'throttle.public' => \App\Http\Middleware\ThrottlePublicRoutes::class,
            'api.logger' => \App\Http\Middleware\GlobalApiLogger::class,
            'download.token' => \App\Http\Middleware\CheckDownloadToken::class,
        ]);

        // Настраиваем web middleware с CSRF
        $middleware->web([
            \App\Http\Middleware\VerifyCsrfToken::class,
        ]);

        // Настраиваем API middleware с CORS
        $middleware->api([
            \App\Http\Middleware\CustomCors::class,
            'api.logger',
            'throttle.public',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        try {
            $settings = \Illuminate\Support\Facades\DB::table('settings')
                ->where('group', 'shop')
                ->whereIn('key', ['yml_feed_regeneration_frequency', 'yml_feed_regeneration_time'])
                ->pluck('value', 'key')
                ->toArray();

            $frequency = $settings['yml_feed_regeneration_frequency'] ?? 'daily';
            $time = $settings['yml_feed_regeneration_time'] ?? '03:00';

            $event = $schedule->command('shop:generate-yml');

            switch ($frequency) {
                case 'hourly':
                    $event->hourly();
                    break;
                case 'weekly':
                    $event->weeklyOn(1, $time); // Понедельник по умолчанию
                    break;
                case 'daily':
                default:
                    $event->dailyAt($time);
                    break;
            }
        } catch (\Exception $e) {
            // Резервный вариант, если БД недоступна
            $schedule->command('shop:generate-yml')->dailyAt('03:00');
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                // Получаем Origin и проверяем разрешенные домены
                $origin = $request->header('Origin');
                $allowedOrigins = [
                    'https://skateandsnow-test.ru',
                    'https://admin.skateandsnow-test.ru',
                    'https://api.skateandsnow-test.ru',
                    'https://skateandsnow.ru',
                    'https://admin.skateandsnow.ru',
                    'https://api.skateandsnow.ru',
                    'https://psy.kirhtarg.ru',
                    'https://api-psy.kirhtarg.ru',
                    'https://self-reason.ru',
                    'https://api.self-reason.ru',
                    'http://localhost:3000',
                    'http://localhost:3001',
                ];

                $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';

                // Функция для добавления CORS заголовков
                $addCorsHeaders = function ($response) use ($allowOrigin) {
                    $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
                    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
                    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID');
                    $response->headers->set('Access-Control-Allow-Credentials', 'true');
                    $response->headers->set('Access-Control-Max-Age', '86400');

                    return $response;
                };

                // Обработка ошибок авторизации
                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $authHeader = $request->header('Authorization');
                    $bearerToken = $request->bearerToken();

                    \Illuminate\Support\Facades\Log::info('Global exception handler: AuthenticationException caught', [
                        'message' => $e->getMessage(),
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                        'has_auth_header' => $authHeader ? true : false,
                        'auth_header_preview' => $authHeader ? substr($authHeader, 0, 20).'...' : null,
                        'has_bearer_token' => $bearerToken ? true : false,
                        'bearer_token_length' => $bearerToken ? strlen($bearerToken) : 0,
                        'bearer_token_preview' => $bearerToken ? substr($bearerToken, 0, 20).'...' : null,
                        'headers' => $request->headers->all(),
                    ]);
                    $response = response()->json([
                        'success' => false,
                        'message' => 'Не авторизован',
                        'error' => 'Unauthenticated.',
                    ], 401);

                    return $addCorsHeaders($response);
                }

                // Обработка ошибок валидации
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $response = response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации',
                        'error' => $e->getMessage(),
                        'errors' => $e->errors(),
                    ], 422);

                    return $addCorsHeaders($response);
                }

                // Обработка HTTP исключений (404, 403 и т.д.)
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $statusCode = $e->getStatusCode();
                    $message = $e->getMessage() ?: 'Ошибка запроса';

                    // Для ошибок авторизации возвращаем 401
                    if ($statusCode === 401 || $statusCode === 403) {
                        \Illuminate\Support\Facades\Log::info('Global exception handler: HttpException with 401/403', [
                            'status_code' => $statusCode,
                            'message' => $message,
                            'url' => $request->fullUrl(),
                        ]);
                        $response = response()->json([
                            'success' => false,
                            'message' => 'Не авторизован',
                            'error' => 'Unauthenticated.',
                        ], 401);

                        return $addCorsHeaders($response);
                    }

                    $response = response()->json([
                        'success' => false,
                        'message' => $message,
                        'error' => $message,
                    ], $statusCode);

                    return $addCorsHeaders($response);
                }

                // Логируем все остальные исключения для отладки
                \Illuminate\Support\Facades\Log::error('Global exception handler: Unhandled exception', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'url' => $request->fullUrl(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $response = response()->json([
                    'success' => false,
                    'message' => 'Внутренняя ошибка сервера',
                    'error' => $e->getMessage(),
                ], 500);

                return $addCorsHeaders($response);
            }

            // Обработка ошибок для веб-запросов (не API)
            $statusCode = 500;

            // Определяем статус код ошибки
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                $statusCode = $e->getStatusCode();
            } elseif (method_exists($e, 'getStatusCode')) {
                $statusCode = $e->getStatusCode();
            } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                // Ошибки валидации обычно 422, но можем обработать как 400
                $statusCode = 422;
            }

            // Получаем настройки сайта для страниц ошибок
            $siteLogo = null;
            $mainSiteUrl = null;

            try {
                $settings = \App\Models\Setting::whereIn('key', ['site_logo', 'main_site'])
                    ->get()
                    ->keyBy('key');

                // Обрабатываем логотип
                if ($settings->has('site_logo') && $settings['site_logo']->value) {
                    $logoPath = $settings['site_logo']->value;
                    // Если это уже полный URL, возвращаем как есть
                    if (str_starts_with($logoPath, 'http')) {
                        $siteLogo = $logoPath;
                    } else {
                        // Нормализуем путь
                        $logoPath = str_replace('\\', '/', $logoPath);
                        $logoPath = ltrim($logoPath, '/');
                        if (str_starts_with($logoPath, 'images/')) {
                            $siteLogo = '/'.$logoPath;
                        } else {
                            $siteLogo = '/images/'.$logoPath;
                        }
                    }
                }

                // Получаем URL главной страницы
                if ($settings->has('main_site') && $settings['main_site']->value) {
                    $mainSiteUrl = $settings['main_site']->value;
                }
            } catch (\Exception $settingsException) {
                // Игнорируем ошибки получения настроек
            }

            // Рендерим страницы ошибок 400 и 500
            if ($statusCode === 400 && view()->exists('errors.400')) {
                return response()->view('errors.400', [
                    'siteLogo' => $siteLogo,
                    'mainSiteUrl' => $mainSiteUrl,
                ], 400);
            }

            if ($statusCode === 500 && view()->exists('errors.500')) {
                return response()->view('errors.500', [
                    'siteLogo' => $siteLogo,
                    'mainSiteUrl' => $mainSiteUrl,
                ], 500);
            }
        });
    })->create();
