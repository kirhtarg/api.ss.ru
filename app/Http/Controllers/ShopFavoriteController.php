<?php

namespace App\Http\Controllers;

use App\Models\ShopFavorite;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopFavoriteController extends Controller
{
    /**
     * Добавить товар в избранное
     */
    public function add(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'good_id' => 'required|integer|exists:shop_goods,id'
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }

            $goodId = $request->good_id;

            // Проверяем, не добавлен ли уже товар в избранное
            $existingFavorite = ShopFavorite::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->first();

            if ($existingFavorite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар уже в избранном'
                ], 400);
            }

            // Добавляем в избранное
            ShopFavorite::create([
                'user_id' => $user->id,
                'good_id' => $goodId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Товар добавлен в избранное'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при добавлении в избранное',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить товар из избранного
     */
    public function remove(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'good_id' => 'required|integer|exists:shop_goods,id'
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }

            $goodId = $request->good_id;

            // Удаляем из избранного
            $deleted = ShopFavorite::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->delete();

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в избранном'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Товар удален из избранного'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении из избранного',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Переключить состояние избранного (добавить/удалить)
     */
    public function toggle(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'good_id' => 'required|integer|exists:shop_goods,id'
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }

            $goodId = $request->good_id;

            // Проверяем, есть ли товар в избранном
            $existingFavorite = ShopFavorite::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->first();

            if ($existingFavorite) {
                // Удаляем из избранного
                $existingFavorite->delete();
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
                'message' => $message,
                'is_favorite' => $isFavorite
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при переключении избранного',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить, находится ли товар в избранном
     */
    public function check(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'good_id' => 'required|integer|exists:shop_goods,id'
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => true,
                    'is_favorite' => false,
                    'message' => 'Пользователь не авторизован'
                ]);
            }

            $goodId = $request->good_id;

            $isFavorite = ShopFavorite::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->exists();

            return response()->json([
                'success' => true,
                'is_favorite' => $isFavorite
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при проверке избранного',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить список избранных товаров пользователя
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }

            // Получаем параметры пагинации
            $perPage = $request->get('limit', 10);
            $page = $request->get('page', 1);

            // Получаем избранные товары с пагинацией
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
                    'image_url' => $good->image_url,
                    'images' => $good->images ? $good->images->map(function ($img) {
                        return [
                            'id' => $img->id,
                            'url' => $img->file_path ? (str_starts_with($img->file_path, 'http') ? $img->file_path : url($img->file_path)) : null,
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
                    }) : [],
                    'created_at' => $good->created_at,
                    'updated_at' => $good->updated_at
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

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении избранного',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}