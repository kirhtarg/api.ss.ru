<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // Проверяем, авторизован ли пользователь
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // Если роли не указаны, пропускаем
        if (empty($roles)) {
            return $next($request);
        }

        // Проверяем, есть ли у пользователя одна из указанных ролей
        $userRoles = $request->user()->roles->pluck('name')->toArray();

        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                return $next($request);
            }
        }

        // Если у пользователя нет нужных ролей
        return response()->json([
            'success' => false,
            'message' => 'Insufficient permissions'
        ], 403);
    }
}
