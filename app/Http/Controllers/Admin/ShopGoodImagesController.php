<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;
use App\Models\ShopGoodVariation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ShopGoodImagesController extends Controller
{
    /**
     * Получить изображения товара
     */
    public function index(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);
        
        $query = $good->images();
        
        // Фильтр по вариации
        if ($request->filled('variation_id')) {
            $query->where('variation_id', $request->get('variation_id'));
        }

        $images = $query->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $images
        ]);
    }

    /**
     * Загрузить изображение
     */
    public function store(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB
            'variation_id' => 'nullable|exists:shop_good_variations,id',
            'alt_text' => 'nullable|string|max:255',
            'is_main' => 'boolean',
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $image = $request->file('image');
            $path = $image->store('shop/goods', 'public');

            $imageData = [
                'good_id' => $goodId,
                'image_path' => $path,
                'alt_text' => $request->get('alt_text'),
                'is_main' => $request->boolean('is_main', false),
                'sort_order' => $request->get('sort_order', 0)
            ];

            if ($request->filled('variation_id')) {
                $variation = ShopGoodVariation::where('good_id', $goodId)
                    ->findOrFail($request->get('variation_id'));
                $imageData['variation_id'] = $variation->id;
            }

            $goodImage = ShopGoodImage::create($imageData);

            // Если это главное изображение, снимаем флаг с других
            if ($goodImage->is_main) {
                ShopGoodImage::where('good_id', $goodId)
                    ->where('id', '!=', $goodImage->id)
                    ->where('variation_id', $goodImage->variation_id)
                    ->update(['is_main' => false]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'data' => $goodImage
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить изображение
     */
    public function update(Request $request, $goodId, $imageId): JsonResponse
    {
        $image = ShopGoodImage::where('good_id', $goodId)->findOrFail($imageId);

        $validator = Validator::make($request->all(), [
            'alt_text' => 'nullable|string|max:255',
            'is_main' => 'boolean',
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $image->update($request->only(['alt_text', 'is_main', 'sort_order']));

        // Если это главное изображение, снимаем флаг с других
        if ($image->is_main) {
            ShopGoodImage::where('good_id', $goodId)
                ->where('id', '!=', $image->id)
                ->where('variation_id', $image->variation_id)
                ->update(['is_main' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Изображение успешно обновлено',
            'data' => $image
        ]);
    }

    /**
     * Удалить изображение
     */
    public function destroy($goodId, $imageId): JsonResponse
    {
        $image = ShopGoodImage::where('good_id', $goodId)->findOrFail($imageId);

        try {
            // Удаляем файл
            if (Storage::disk('public')->exists($image->image_path)) {
                Storage::disk('public')->delete($image->image_path);
            }

            $image->delete();

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Установить главное изображение
     */
    public function setMain($goodId, $imageId): JsonResponse
    {
        $image = ShopGoodImage::where('good_id', $goodId)->findOrFail($imageId);

        // Снимаем флаг с других изображений
        ShopGoodImage::where('good_id', $goodId)
            ->where('id', '!=', $image->id)
            ->where('variation_id', $image->variation_id)
            ->update(['is_main' => false]);

        // Устанавливаем флаг для выбранного изображения
        $image->update(['is_main' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Главное изображение установлено'
        ]);
    }

    /**
     * Изменить порядок изображений
     */
    public function reorder(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'images' => 'required|array',
            'images.*.id' => 'required|exists:shop_good_images,id',
            'images.*.sort_order' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->get('images') as $imageData) {
            ShopGoodImage::where('good_id', $goodId)
                ->where('id', $imageData['id'])
                ->update(['sort_order' => $imageData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Порядок изображений обновлен'
        ]);
    }
}
