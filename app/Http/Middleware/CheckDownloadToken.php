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

        // Try to handle URL encoded token if present
        if ($token && strpos($token, '%') !== false) {
            $decodedToken = urldecode($token);
            // If decoding changed the token, try both
            if ($decodedToken !== $token) {
                 $token = $decodedToken;
            }
        }

        // DEBUG LOG TO LARAVEL LOG
        \Log::warning("Download Token Check (WARNING LEVEL):", [
            'token_provided' => $token,
            'url' => $request->fullUrl(),
            'ip' => $request->ip()
        ]);
        
        if ($token) {
            // Очищаем токен от пробелов и кавычек (если вдруг пришли из JSON)
            $token = trim($token, " \t\n\r\0\x0B\"'");

            // Fix for tokens with pipe that might be double encoded or not handled correctly
            if (strpos($token, '|') !== false) {
                 // Ensure we are passing the raw string to findToken
            }

            $accessToken = PersonalAccessToken::findToken($token);
            
            \Log::info("Token lookup result:", [
                'found' => $accessToken ? true : false,
                'id' => $accessToken ? $accessToken->id : null,
                'tokenable_id' => ($accessToken && $accessToken->tokenable) ? $accessToken->tokenable->id : null
            ]);

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
            'message' => 'Не авторизован (Middleware Check)',
            'error' => 'Unauthenticated.',
            'debug_token' => $token, 
            'debug_encoded' => $request->query('token'),
            'middleware_version' => 'v2'
        ], 401);
    }
}
