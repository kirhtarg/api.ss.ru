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
        $response = $next($request);

        // Разрешенные домены
        $allowedOrigins = [
            'http://localhost:3000',
            'http://localhost:3001',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
            'http://admin.skateandsnow.ru',
            'https://admin.skateandsnow.ru',
            'https://ss75.kirhtarg.ru',
            'http://ss75.kirhtarg.ru'
        ];

        // Проверяем паттерн для поддоменов kirhtarg.ru
        $origin = $request->header('Origin');
        if ($origin && preg_match('/^https?:\/\/[a-zA-Z0-9-]+\.kirhtarg\.ru$/', $origin)) {
            $allowedOrigins[] = $origin;
        }

        // Устанавливаем CORS заголовки
        if ($origin && in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');

        // Обрабатываем preflight запросы
        if ($request->isMethod('OPTIONS')) {
            $response->setStatusCode(200);
        }

        return $response;
    }
}
