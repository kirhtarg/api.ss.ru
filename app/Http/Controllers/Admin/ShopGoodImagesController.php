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
                'file_path' => $path,
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
            if (Storage::disk('public')->exists($image->file_path)) {
                Storage::disk('public')->delete($image->file_path);
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

    /**
     * Создать связанное изображение (для импорта)
     */
    public function createFromImport(Request $request, $goodId): JsonResponse
    {
        \Log::info('createFromImport called', [
            'goodId' => $goodId,
            'requestData' => $request->all()
        ]);

        $good = ShopGood::findOrFail($goodId);

        $validator = Validator::make($request->all(), [
            'file_path' => 'required|string',
            'alt_text' => 'nullable|string|max:255',
            'is_main' => 'boolean',
            'sort_order' => 'integer',
            'image_action' => 'nullable|in:add,replace,skip,unique'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $imageAction = $request->get('image_action', 'add');
            $filePath = $request->get('file_path');
            $altText = $request->get('alt_text', '');
            $isMain = $request->boolean('is_main', true);
            $sortOrder = $request->get('sort_order', 0);

            \Log::info('Processing image data', [
                'imageAction' => $imageAction,
                'filePath' => $filePath,
                'altText' => $altText,
                'isMain' => $isMain,
                'sortOrder' => $sortOrder
            ]);

            // Проверяем существование файла
            if (!Storage::disk('public')->exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл изображения не найден: ' . $filePath
                ], 404);
            }

            // Обрабатываем действие с изображениями
            if ($imageAction === 'replace') {
                // Удаляем все существующие изображения товара
                $existingImages = ShopGoodImage::where('good_id', $goodId)->get();
                foreach ($existingImages as $existingImage) {
                    if (Storage::disk('public')->exists($existingImage->file_path)) {
                        Storage::disk('public')->delete($existingImage->file_path);
                    }
                    $existingImage->delete();
                }
            } elseif ($imageAction === 'skip') {
                // Проверяем, есть ли уже изображения у товара
                $existingCount = ShopGoodImage::where('good_id', $goodId)->count();
                if ($existingCount > 0) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Изображения пропущены (уже существуют)',
                        'data' => null
                    ]);
                }
            } elseif ($imageAction === 'unique') {
                // Проверяем, есть ли уже изображение с таким же путем в базе
                $existingImage = ShopGoodImage::where('file_path', $filePath)->first();
                if ($existingImage) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Изображение пропущено (уже существует в базе)',
                        'data' => null
                    ]);
                }
            }

            // Создаем новое изображение
            $imageData = [
                'good_id' => $goodId,
                'file_path' => $filePath,
                'alt_text' => $altText,
                'is_main' => $isMain,
                'sort_order' => $sortOrder
            ];

            $goodImage = ShopGoodImage::create($imageData);

            // Если это главное изображение, снимаем флаг с других
            if ($goodImage->is_main) {
                ShopGoodImage::where('good_id', $goodId)
                    ->where('id', '!=', $goodImage->id)
                    ->update(['is_main' => false]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Связанное изображение успешно создано',
                'data' => $goodImage
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Error creating related image', [
                'goodId' => $goodId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания связанного изображения: ' . $e->getMessage()
            ], 500);
        }
    }
}
