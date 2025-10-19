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
        // Обрабатываем preflight запросы сразу
        if ($request->isMethod('OPTIONS')) {
            $origin = $request->header('Origin');
            return response('', 200)
                ->header('Access-Control-Allow-Origin', $origin ?: '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        // Получаем разрешенные домены из конфигурации
        $allowedOrigins = config('cors.allowed_origins', []);
        $allowedPatterns = config('cors.allowed_origins_patterns', []);

        // Проверяем паттерны для поддоменов
        $origin = $request->header('Origin');
        $isAllowed = false;

        if ($origin) {
            // Проверяем точные совпадения
            if (in_array($origin, $allowedOrigins)) {
                $isAllowed = true;
            } else {
                // Проверяем паттерны
                foreach ($allowedPatterns as $pattern) {
                    if (preg_match('/' . $pattern . '/', $origin)) {
                        $isAllowed = true;
                        break;
                    }
                }
            }
        }

        // Устанавливаем CORS заголовки
        if ($origin && $isAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } elseif ($origin) {
            // Временное решение - разрешаем все домены (только для отладки!)
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } else {
            // Если нет Origin, разрешаем все домены для отладки
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');

        // Принудительно добавляем CORS заголовки к ошибкам
        if ($response->getStatusCode() >= 400) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
