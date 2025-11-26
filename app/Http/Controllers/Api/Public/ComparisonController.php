<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopComparison;
use App\Models\ShopGood;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ComparisonController extends Controller
{
    /**
     * Получить список товаров в сравнении
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            // Проверяем, существует ли таблица shop_comparisons
            if (!Schema::hasTable('shop_comparisons')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Таблица сравнений не настроена'
                ], 500);
            }

            // Проверяем, существует ли таблица shop_goods
            if (!Schema::hasTable('shop_goods')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Таблица товаров не настроена'
                ], 500);
            }

            // Проверяем, существует ли таблица shop_good_images
            if (!Schema::hasTable('shop_good_images')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Таблица изображений товаров не настроена'
                ], 500);
            }


            $comparisons = ShopComparison::where('user_id', $user->id)
                ->with([
                    'good' => function ($query) {
                        $query->with([
                            'images' => function ($query) {
                                $query->where('is_main', true)
                                      ->whereNull('variation_id')
                                      ->orderBy('sort_order')
                                      ->limit(1);
                            },
                            'properties' => function ($query) {
                                $query->with('values');
                            },
                            'variations' => function ($query) {
                                $query->where('is_active', true);
                            }
                        ]);
                    }
                ])
                ->get();


            $goods = $comparisons->map(function ($comparison) use ($user) {
                $good = $comparison->good;
                if (!$good) return null;

                // Проверяем, есть ли товар в избранном у пользователя
                $isFavorite = DB::table('shop_favorites')
                    ->where('user_id', $user->id)
                    ->where('good_id', $good->id)
                    ->exists();

                return [
                    'id' => $good->id,
                    'name' => $good->name,
                    'slug' => $good->slug,
                    'price' => $good->price,
                    'sale_price' => $good->sale_price,
                    'old_price' => $good->old_price,
                    'stock_quantity' => $good->stock_quantity,
                    'in_stock' => $good->in_stock,
                    'is_new' => $good->is_new,
                    'is_sale' => $good->is_sale,
                    'is_favorite' => $isFavorite,
                    'image_url' => $this->getGoodImageUrl($good),
                    'properties' => $good->properties->map(function ($property) {
                        // Получаем значение из pivot таблицы
                        $propertyValueId = $property->pivot->shop_property_value_id ?? null;
                        $value = '';
                        
                        if ($propertyValueId && $property->values) {
                            $propertyValue = $property->values->find($propertyValueId);
                            $value = $propertyValue ? $propertyValue->value : '';
                        }
                        
                        return [
                            'id' => $property->id,
                            'name' => $property->name ?? 'Свойство',
                            'value' => $value,
                            'property_id' => $property->id,
                        ];
                    }),
                    'variations' => $good->variations->map(function ($variation) {
                        return [
                            'id' => $variation->id,
                            'name' => $variation->name,
                            'price' => $variation->price,
                            'sale_price' => $variation->sale_price,
                            'stock_quantity' => $variation->stock_quantity,
                            'is_active' => $variation->is_active,
                        ];
                    }),
                ];
            })->filter();

            return response()->json([
                'success' => true,
                'data' => $goods,
                'count' => $goods->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения товаров для сравнения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Добавить товар в сравнение
     */
    public function add(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $request->validate([
                'good_id' => 'required|integer|exists:shop_goods,id'
            ]);

            $goodId = $request->good_id;

            // Проверяем, не добавлен ли уже товар
            $existingComparison = ShopComparison::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->first();

            if ($existingComparison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар уже добавлен в сравнение'
                ], 400);
            }

            // Ограничение на количество товаров в сравнении убрано

            // Добавляем товар в сравнение
            ShopComparison::create([
                'user_id' => $user->id,
                'good_id' => $goodId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Товар добавлен в сравнение'
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
                'message' => 'Ошибка добавления товара в сравнение'
            ], 500);
        }
    }

    /**
     * Удалить товар из сравнения
     */
    public function remove(Request $request, $id = null): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            // Получаем good_id из URL параметра или из тела запроса
            $goodId = $id ?? $request->get('good_id');

            // Валидируем ID товара
            if (!$goodId || !is_numeric($goodId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Некорректный ID товара'
                ], 400);
            }

            // Проверяем существование товара
            if (!ShopGood::where('id', $goodId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден'
                ], 404);
            }

            $comparison = ShopComparison::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->first();

            if (!$comparison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в сравнении'
                ], 404);
            }

            $comparison->delete();

            return response()->json([
                'success' => true,
                'message' => 'Товар удален из сравнения'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка удаления товара из сравнения:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления товара из сравнения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Очистить все товары из сравнения
     */
    public function clear(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            ShopComparison::where('user_id', $user->id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Сравнение очищено'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка очистки сравнения'
            ], 500);
        }
    }

    /**
     * Проверить, добавлен ли товар в сравнение
     */
    public function check(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $request->validate([
                'good_id' => 'required|integer|exists:shop_goods,id'
            ]);

            $goodId = $request->good_id;

            $isInComparison = ShopComparison::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->exists();

            return response()->json([
                'success' => true,
                'is_in_comparison' => $isInComparison
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
                'message' => 'Ошибка проверки товара в сравнении'
            ], 500);
        }
    }

    /**
     * Проверить, добавлены ли товары в сравнение (массовая проверка)
     */
    public function checkMultiple(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $request->validate([
                'good_ids' => 'required|array',
                'good_ids.*' => 'integer|exists:shop_goods,id'
            ]);

            $goodIds = $request->good_ids;

            // Получаем все товары пользователя в сравнении одним запросом
            $comparisonGoodIds = ShopComparison::where('user_id', $user->id)
                ->whereIn('good_id', $goodIds)
                ->pluck('good_id')
                ->toArray();

            // Создаем массив результатов
            $results = [];
            foreach ($goodIds as $goodId) {
                $results[$goodId] = in_array($goodId, $comparisonGoodIds);
            }

            return response()->json([
                'success' => true,
                'data' => $results
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
                'message' => 'Ошибка массовой проверки товаров в сравнении'
            ], 500);
        }
    }

    /**
     * Получить URL изображения товара
     */
    private function getGoodImageUrl($good): ?string
    {
        try {
            // Сначала пытаемся получить главное изображение
            $mainImage = $good->images->where('is_main', true)->first();
            if ($mainImage) {
                return $mainImage->url;
            }

            // Если главного изображения нет, берем первое доступное
            $firstImage = $good->images->first();
            if ($firstImage) {
                return $firstImage->url;
            }

            // Если изображений нет, возвращаем null
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
