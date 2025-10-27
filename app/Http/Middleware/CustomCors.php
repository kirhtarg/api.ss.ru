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
        $method = $request->method();
        $path = $request->path();

        // Логируем входящий запрос для отладки
        \Log::info('CustomCors middleware', [
            'method' => $method,
            'path' => $path,
            'origin' => $origin,
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
                'http://localhost:3000',
                'http://localhost:3001',
            ];

            $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';

            \Log::info('CORS preflight handled', [
                'origin' => $origin,
                'allowOrigin' => $allowOrigin,
                'isAllowed' => in_array($origin, $allowedOrigins),
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
            'http://localhost:3000',
            'http://localhost:3001',
        ];

        // Все разрешенные origins
        $allAllowedOrigins = array_unique(array_merge($allowedOrigins, $hardCodedAllowedOrigins));

        // Устанавливаем CORS заголовки
        $finalOrigin = null;
        if ($origin && in_array($origin, $allAllowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $finalOrigin = $origin;
        } elseif ($origin) {
            // Разрешаем origin для отладки (временно)
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $finalOrigin = $origin . ' (not in whitelist)';
        } else {
            // Если нет Origin, разрешаем все домены
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $finalOrigin = '*';
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Session-ID');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');

        \Log::info('CORS headers set', [
            'origin' => $origin,
            'finalOrigin' => $finalOrigin,
            'statusCode' => $response->getStatusCode(),
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
