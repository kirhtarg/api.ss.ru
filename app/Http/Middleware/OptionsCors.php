<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OptionsCors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Обрабатываем только OPTIONS запросы
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

        // Для не-OPTIONS запросов продолжаем
        return $next($request);
    }
}