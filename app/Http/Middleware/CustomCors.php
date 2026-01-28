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
        try {
            $origin = $request->header('Origin');
            $url = $request->fullUrl();
            $method = $request->method();

            // СУПЕР ПОДРОБНАЯ ОТЛАДКА
            \Illuminate\Support\Facades\Log::info('=== CORS MIDDLEWARE DEBUG START ===', [
            'timestamp' => now()->toISOString(),
            'origin' => $origin,
            'method' => $method,
            'url' => $url,
            'user_agent' => $request->header('User-Agent'),
            'referer' => $request->header('Referer'),
            'all_headers' => $request->headers->all(),
            'is_export' => $request->has('for_export') ? $request->get('for_export') : 'no',
            'per_page' => $request->get('per_page'),
            'with_variations' => $request->get('with_variations'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        ]);

        // Обрабатываем preflight запросы сразу
        if ($request->isMethod('OPTIONS')) {
            \Illuminate\Support\Facades\Log::info('=== CORS OPTIONS REQUEST ===', [
                'origin' => $origin,
                'url' => $url,
                'timestamp' => now()->toISOString(),
            ]);

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

            $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : false;

            if (!$allowOrigin) {
                \Illuminate\Support\Facades\Log::warning('=== CORS OPTIONS BLOCKED ===', [
                    'origin' => $origin,
                    'reason' => 'Origin not in allowed list'
                ]);
                return response('Origin not allowed', 403);
            }

            \Illuminate\Support\Facades\Log::info('=== CORS OPTIONS ALLOWED ===', [
                'origin' => $origin,
                'allowed_origin' => $allowOrigin
            ]);

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

        // ОТЛАДКА: логируем установленные заголовки
        \Illuminate\Support\Facades\Log::info('=== CORS MIDDLEWARE DEBUG END ===', [
            'timestamp' => now()->toISOString(),
            'response_status' => $response->getStatusCode(),
            'access_control_allow_origin' => $response->headers->get('Access-Control-Allow-Origin'),
            'access_control_allow_methods' => $response->headers->get('Access-Control-Allow-Methods'),
            'access_control_allow_headers' => $response->headers->get('Access-Control-Allow-Headers'),
            'access_control_allow_credentials' => $response->headers->get('Access-Control-Allow-Credentials'),
            'all_response_headers' => $response->headers->all(),
            'error_debug' => 'Headers should be set above this line',
        ]);

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
            \Illuminate\Support\Facades\Log::error('=== CORS MIDDLEWARE EXCEPTION ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_url' => $request->fullUrl(),
                'request_method' => $request->method(),
                'origin' => $request->header('Origin'),
            ]);

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
