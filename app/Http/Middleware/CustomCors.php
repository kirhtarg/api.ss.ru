<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomCors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ЛОГИРУЕМ ВСЕ запросы, которые доходят до middleware
        \Illuminate\Support\Facades\Log::info('=== CORS MIDDLEWARE CALLED ===', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'origin' => $request->header('Origin'),
            'user_agent' => substr($request->header('User-Agent') ?? '', 0, 50),
            'timestamp' => now()->toISOString(),
        ]);

        // Обрабатываем preflight запросы сразу
        if ($request->isMethod('OPTIONS')) {
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

            $allowOrigin = in_array($request->header('Origin'), $allowedOrigins) ? $request->header('Origin') : false;

            if (!$allowOrigin) {
                return response('Origin not allowed', 403);
            }

            return response('', 200)
                ->header('Access-Control-Allow-Origin', $allowOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        try {
            $origin = $request->header('Origin');
            $url = $request->fullUrl();
            $method = $request->method();

        // Обрабатываем preflight запросы сразу
        if ($request->isMethod('OPTIONS')) {
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

            $allowOrigin = in_array($request->header('Origin'), $allowedOrigins) ? $request->header('Origin') : false;

            if (!$allowOrigin) {
                return response('Origin not allowed', 403);
            }

            return response('', 200)
                ->header('Access-Control-Allow-Origin', $allowOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        // Получаем разрешенные домены из конфигурации
        $allowedOrigins = config('cors.allowed_origins', []);
        $allowedPatterns = config('cors.allowed_origins_patterns', []);

        // Проверяем паттерны для поддоменов
        $origin = $request->header('Origin');

        // Список разрешенных доменов
        $hardCodedAllowedOrigins = [
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

        // Все разрешенные origins
        $allAllowedOrigins = array_unique(array_merge($allowedOrigins, $hardCodedAllowedOrigins));

        // Устанавливаем CORS заголовки - упрощенная версия для тестирования
        if ($origin && in_array($origin, $hardCodedAllowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } elseif ($origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin); // Временно разрешаем все для тестирования
        } else {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');

        // Принудительно добавляем CORS заголовки к ошибкам
            if ($response->getStatusCode() >= 400) {
                $errorOrigin = $request->header('Origin');
                if ($errorOrigin && in_array($errorOrigin, $hardCodedAllowedOrigins)) {
                    $response->headers->set('Access-Control-Allow-Origin', $errorOrigin);
                } else {
                    $response->headers->set('Access-Control-Allow-Origin', '*');
                }
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID');
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Max-Age', '86400');
            }

            return $response;
        } catch (\Exception $e) {
            // В случае ошибки возвращаем ответ с базовыми CORS заголовками
            $errorResponse = response()->json(['error' => 'CORS middleware error'], 500);
            $errorResponse->headers->set('Access-Control-Allow-Origin', $request->header('Origin') ?: '*');
            $errorResponse->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $errorResponse->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID');
            $errorResponse->headers->set('Access-Control-Allow-Credentials', 'true');

            return $errorResponse;
        }
    }
}
