<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AdminPage;
use App\Models\User;

class CheckPageAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $pageSlug = null): Response
    {
        // Если slug страницы не указан, пропускаем
        if (!$pageSlug) {
            return $next($request);
        }

        // Получаем пользователя из токена
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не аутентифицирован'
            ], 401);
        }

        // Получаем страницу по slug
        $page = AdminPage::where('slug', $pageSlug)->first();
        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Страница не найдена'
            ], 404);
        }

        // Проверяем доступ пользователя к странице
        if (!$this->userHasAccessToPage($user, $page)) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ запрещен'
            ], 403);
        }

        return $next($request);
    }

    /**
     * Проверяет, имеет ли пользователь доступ к странице
     */
    private function userHasAccessToPage(User $user, AdminPage $page): bool
    {
        // Администратор имеет доступ ко всем страницам
        if ($user->hasRole('admin')) {
            return true;
        }

        // Проверяем роли пользователя
        $userRoles = $user->roles;
        
        foreach ($userRoles as $role) {
            if ($page->roles()->where('role_id', $role->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
