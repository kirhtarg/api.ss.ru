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
        $origin = $request->header('Origin');

        // Логируем CORS запросы для отладки
        \Illuminate\Support\Facades\Log::info('CORS Middleware: Processing request', [
            'origin' => $origin,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'has_authorization' => $request->hasHeader('Authorization'),
            'bearer_token' => $request->bearerToken() ? substr($request->bearerToken(), 0, 20) . '...' : null,
        ]);

        // Обрабатываем preflight запросы сразу - РАЗРЕШАЕМ ВСЕ ДЛЯ ТЕСТИРОВАНИЯ
        if ($request->isMethod('OPTIONS')) {
            \Illuminate\Support\Facades\Log::info('CORS Middleware: OPTIONS preflight request', [
                'origin' => $origin,
                'method' => $request->method(),
                'headers' => $request->headers->all(),
            ]);

            return response('', 200)
                ->header('Access-Control-Allow-Origin', $origin ?: '*')
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

        // Устанавливаем CORS заголовки - РАЗРЕШАЕМ ВСЕ ДЛЯ ТЕСТИРОВАНИЯ
        $finalOrigin = null;
        if ($origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $finalOrigin = $origin . ' (allowed for testing)';
        } else {
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $finalOrigin = '*';
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');

        // Логируем установленные заголовки
        \Illuminate\Support\Facades\Log::info('CORS Middleware: Headers set', [
            'access-control-allow-origin' => $response->headers->get('Access-Control-Allow-Origin'),
            'access-control-allow-methods' => $response->headers->get('Access-Control-Allow-Methods'),
            'access-control-allow-headers' => $response->headers->get('Access-Control-Allow-Headers'),
            'status_code' => $response->getStatusCode(),
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
    }
}
