<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AdminPage;
use App\Models\User;

class CheckShopAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Получаем пользователя из токена
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не аутентифицирован'
            ], 401);
        }

        // Администратор имеет доступ ко всем операциям
        if ($user->hasRole('admin')) {
            return $next($request);
        }

        // Получаем страницу shop
        $shopPage = AdminPage::where('slug', 'shop')->first();
        if (!$shopPage) {
            return response()->json([
                'success' => false,
                'message' => 'Страница shop не найдена'
            ], 404);
        }

        // Проверяем доступ пользователя к странице shop
        if (!$this->userHasAccessToShop($user, $shopPage)) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ к операциям с категориями запрещен. Необходим доступ к разделу shop.'
            ], 403);
        }

        return $next($request);
    }

    /**
     * Проверяет, имеет ли пользователь доступ к странице shop
     */
    private function userHasAccessToShop(User $user, AdminPage $shopPage): bool
    {
        // Администратор имеет доступ ко всем страницам
        if ($user->hasRole('admin')) {
            return true;
        }

        // Проверяем роли пользователя
        $userRoles = $user->roles;
        
        foreach ($userRoles as $role) {
            if ($shopPage->roles()->where('role_id', $role->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
