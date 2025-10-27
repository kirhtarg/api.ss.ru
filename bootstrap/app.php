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
        ]);

        // Настраиваем web middleware с CSRF
        $middleware->web([
            \App\Http\Middleware\VerifyCsrfToken::class,
        ]);

        // Настраиваем API middleware с CORS
        $middleware->api([
            \App\Http\Middleware\CustomCors::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                $response = response()->json([
                    'success' => false,
                    'message' => 'Внутренняя ошибка сервера',
                    'error' => $e->getMessage()
                ], 500);

                // Получаем Origin и проверяем разрешенные домены
                $origin = $request->header('Origin');
                $allowedOrigins = [
                    'https://skateandsnow-test.ru',
                    'https://admin.skateandsnow-test.ru',
                    'https://api.skateandsnow-test.ru',
                    'https://skateandsnow.ru',
                    'https://admin.skateandsnow.ru',
                    'https://api.skateandsnow.ru',
                    'http://localhost:3000',
                    'http://localhost:3001',
                ];

                $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';

                // Добавляем CORS заголовки
                $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID');
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Max-Age', '86400');

                return $response;
            }
        });
    })->create();
