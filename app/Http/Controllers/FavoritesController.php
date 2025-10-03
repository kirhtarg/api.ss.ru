<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ShopFavorite;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Log;

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

        // Получаем параметры пагинации
        $perPage = $request->get('limit', 10);
        $page = $request->get('page', 1);

        // Получаем избранные товары с пагинацией и полными данными
        $favorites = ShopFavorite::where('user_id', $user->id)
            ->with(['good' => function($query) {
                $query->with([
                    'categories:id,name,slug',
                    'brands:id,name,slug',
                    'tags:id,name,color',
                    'variations:id,good_id,name,price,sale_price,stock_quantity,is_active',
                    'properties:id,name,slug',
                    'images:id,good_id,file_path,alt_text,is_main,sort_order'
                ])->where('is_active', true);
            }])
            ->paginate($perPage, ['*'], 'page', $page);

        // Форматируем данные для фронтенда
        $formattedGoods = $favorites->map(function ($favorite) {
            if (!$favorite->good) {
                return null;
            }
            
            $good = $favorite->good;
            return [
                'id' => $good->id,
                'name' => $good->name,
                'slug' => $good->slug,
                'description' => $good->description,
                'short_description' => $good->short_description,
                'price' => $good->price,
                'sale_price' => $good->sale_price,
                'discount_percent' => $good->discount_percent,
                'rating' => $good->rating,
                'reviews_count' => $good->reviews_count,
                'stock_quantity' => $good->stock_quantity,
                'is_active' => $good->is_active,
                'is_favorite' => true, // Всегда true для избранных товаров
                'image' => $this->getImageUrl($good->image_url),
                'image_url' => $this->getImageUrl($good->image_url),
                'images' => $good->images ? $good->images->map(function ($img) {
                    return [
                        'id' => $img->id,
                        'url' => $this->getImageUrl($img->file_path),
                        'alt_text' => $img->alt_text,
                        'is_main' => $img->is_main,
                        'sort_order' => $img->sort_order
                    ];
                })->filter(fn($img) => $img['url'])->values() : [],
                'categories' => $good->categories ? $good->categories->map(function ($cat) {
                    return [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'slug' => $cat->slug
                    ];
                }) : [],
                'brands' => $good->brands ? $good->brands->map(function ($brand) {
                    return [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug
                    ];
                }) : [],
                'tags' => $good->tags ? $good->tags->map(function ($tag) {
                    return [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'color' => $tag->color
                    ];
                }) : [],
                'variations' => $good->variations ? $good->variations->map(function ($variation) {
                    return [
                        'id' => $variation->id,
                        'good_id' => $variation->good_id,
                        'name' => $variation->name,
                        'price' => $variation->price,
                        'sale_price' => $variation->sale_price,
                        'stock_quantity' => $variation->stock_quantity,
                        'is_active' => $variation->is_active
                    ];
                }) : [],
                'properties' => $good->properties ? $good->properties->map(function ($prop) {
                    return [
                        'id' => $prop->id,
                        'name' => $prop->name,
                        'slug' => $prop->slug
                    ];
                }) : []
            ];
        })->filter();

        return response()->json([
            'success' => true,
            'data' => $formattedGoods,
            'pagination' => [
                'current_page' => $favorites->currentPage(),
                'last_page' => $favorites->lastPage(),
                'per_page' => $favorites->perPage(),
                'total' => $favorites->total(),
                'from' => $favorites->firstItem(),
                'to' => $favorites->lastItem()
            ]
        ]);
    }

    /**
     * Получить URL изображения
     */
    private function getImageUrl($imagePath)
    {
        if (!$imagePath) {
            return null;
        }
        
        // Возвращаем путь как есть, без изменений
        return $imagePath;
    }
}
