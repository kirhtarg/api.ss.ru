<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ShopImageController extends Controller
{
    /**
     * Получить изображения товаров пакетно
     */
    public function getBatchImages(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'good_ids' => 'required|array|max:50',
                'good_ids.*' => 'required|integer|exists:shop_goods,id',
                'include_variations' => 'boolean',
                'image_types' => 'array',
                'image_types.*' => 'in:main,all,thumbnails'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $goodIds = $request->get('good_ids');
            $includeVariations = $request->get('include_variations', false);
            $imageTypes = $request->get('image_types', ['main']);

            $images = collect();

            // Загружаем изображения товаров
            $query = \App\Models\ShopGoodImage::whereIn('good_id', $goodIds)
                ->whereNull('variation_id')
                ->orderBy('good_id')
                ->orderBy('sort_order')
                ->orderBy('id');

            // Если нужны только главные изображения
            if (in_array('main', $imageTypes) && !in_array('all', $imageTypes)) {
                $query->where('is_main', true);
            }

            $goodImages = $query->get();

            // Группируем по товарам
            $goodImages->groupBy('good_id')->each(function ($imagesGroup, $goodId) use ($images) {
                $mainImage = $imagesGroup->where('is_main', true)->first() 
                           ?? $imagesGroup->sortBy('sort_order')->first();
                
                $images->push([
                    'good_id' => (int) $goodId,
                    'main_image' => $mainImage ? $this->getImageUrl($mainImage->file_path) : null,
                    'all_images' => $imagesGroup->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'url' => $this->getImageUrl($image->file_path),
                            'alt_text' => $image->alt_text,
                            'is_main' => $image->is_main,
                            'sort_order' => $image->sort_order
                        ];
                    })->toArray()
                ]);
            });

            // Если нужны изображения вариаций
            if ($includeVariations) {
                $variationImages = \App\Models\ShopGoodImage::whereIn('good_id', $goodIds)
                    ->whereNotNull('variation_id')
                    ->orderBy('good_id')
                    ->orderBy('variation_id')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                $variationImages->groupBy('good_id')->each(function ($variationsGroup, $goodId) use ($images) {
                    $goodImages = $images->where('good_id', (int) $goodId)->first();
                    if ($goodImages) {
                        $goodImages['variations'] = $variationsGroup->groupBy('variation_id')->map(function ($variationImages) {
                            return $variationImages->map(function ($image) {
                                return [
                                    'id' => $image->id,
                                    'url' => $this->getImageUrl($image->file_path),
                                    'alt_text' => $image->alt_text,
                                    'is_main' => $image->is_main,
                                    'sort_order' => $image->sort_order
                                ];
                            })->toArray();
                        })->toArray();
                    }
                });
            }

            return response()->json([
                'success' => true,
                'data' => $images->toArray()
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения изображений товаров', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения изображений'
            ], 500);
        }
    }

    /**
     * Получить изображения категорий пакетно
     */
    public function getCategoryImages(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'category_ids' => 'required|array|max:50',
                'category_ids.*' => 'required|integer|exists:shop_categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $categoryIds = $request->get('category_ids');

            $categories = \App\Models\ShopCategory::whereIn('id', $categoryIds)
                ->select('id', 'name', 'image')
                ->get()
                ->map(function ($category) {
                    return [
                        'category_id' => $category->id,
                        'name' => $category->name,
                        'image_url' => $category->image ? $this->getImageUrl($category->image) : null
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $categories->toArray()
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения изображений категорий', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения изображений категорий'
            ], 500);
        }
    }

    /**
     * Получить полный URL изображения
     */
    private function getImageUrl($filePath)
    {
        if (!$filePath) {
            return null;
        }

        // Если это уже полный URL, возвращаем как есть
        if (str_starts_with($filePath, 'http')) {
            return $filePath;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($filePath, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            return '/' . $cleanPath;
        }

        // Возвращаем путь к файлу в папке public/images/
        return '/images/' . $cleanPath;
    }
}
