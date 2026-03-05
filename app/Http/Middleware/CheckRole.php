<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $user = $request->user();

        // Безопасная загрузка ролей пользователя - используем прямой запрос
        $userRoles = [];
        try {
            // Используем прямой запрос для надежности
            $userRoles = \Illuminate\Support\Facades\DB::table('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('user_roles.user_id', $user->id)
                ->where(function ($query) {
                    $query->where('user_roles.is_active', '=', 1)
                        ->orWhere('user_roles.is_active', '=', true);
                })
                ->pluck('roles.name')
                ->toArray();

            // Если прямой запрос не вернул роли, пробуем через связь
            if (empty($userRoles)) {
                try {
                    $user->load('roles');
                    if ($user->roles && $user->roles->count() > 0) {
                        $userRoles = $user->roles->pluck('name')->toArray();
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибку связи
                }
            }
        } catch (\Exception $e) {
            // Если не удалось загрузить роли, считаем, что у пользователя нет ролей
            \Illuminate\Support\Facades\Log::warning('Ошибка загрузки ролей в middleware CheckRole: '.$e->getMessage(), [
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $userRoles = [];
        }

        // Проверяем, есть ли у пользователя хотя бы одна из требуемых ролей
        $hasRequiredRole = false;
        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                $hasRequiredRole = true;
                break;
            }
        }

        if (! $hasRequiredRole) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Insufficient permissions.',
            ], 403);
        }

        return $next($request);
    }
}
