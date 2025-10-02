<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ShopFavorite;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class FavoritesController extends Controller
{
    /**
     * Получить пользователя по токену
     */
    private function getUserFromToken(Request $request)
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return null;
        }

        // Ищем по Sanctum токену
        $personalAccessToken = PersonalAccessToken::findToken($token);
        if ($personalAccessToken) {
            return $personalAccessToken->tokenable;
        }

        // Ищем по remember_token
        return User::where('remember_token', $token)->first();
    }

    /**
     * Переключить избранное
     */
    public function toggle(Request $request)
    {
        $request->validate([
            'good_id' => 'required|integer|exists:shop_goods,id'
        ]);

        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация'
            ], 401);
        }

        $goodId = $request->good_id;

        // Проверяем, есть ли уже в избранном
        $existing = ShopFavorite::where('user_id', $user->id)
            ->where('good_id', $goodId)
            ->first();

        if ($existing) {
            // Удаляем из избранного
            $existing->delete();
            $isFavorite = false;
            $message = 'Товар удален из избранного';
        } else {
            // Добавляем в избранное
            ShopFavorite::create([
                'user_id' => $user->id,
                'good_id' => $goodId
            ]);
            $isFavorite = true;
            $message = 'Товар добавлен в избранное';
        }

        return response()->json([
            'success' => true,
            'is_favorite' => $isFavorite,
            'message' => $message
        ]);
    }

    /**
     * Проверить статус избранного
     */
    public function check(Request $request)
    {
        $request->validate([
            'good_id' => 'required|integer|exists:shop_goods,id'
        ]);

        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'is_favorite' => false,
                'message' => 'Необходима авторизация'
            ], 401);
        }

        $isFavorite = ShopFavorite::where('user_id', $user->id)
            ->where('good_id', $request->good_id)
            ->exists();

        return response()->json([
            'success' => true,
            'is_favorite' => $isFavorite
        ]);
    }

    /**
     * Получить все избранные товары
     */
    public function index(Request $request)
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация'
            ], 401);
        }

        $favorites = ShopFavorite::where('user_id', $user->id)
            ->with('good')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $favorites
        ]);
    }
}
