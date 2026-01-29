<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class CheckDownloadToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');

        if ($token) {
            // Пытаемся найти токен в базе Sanctum
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && $accessToken->tokenable) {
                // Аутентифицируем пользователя
                Auth::login($accessToken->tokenable);
                return $next($request);
            }
        }

        // Если токен не найден или не валиден, проверяем стандартную аутентификацию
        if (Auth::check()) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Не авторизован',
            'error' => 'Unauthenticated.'
        ], 401);
    }
}
