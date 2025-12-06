<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;
use App\Models\ShopGoodVariation;
use App\Services\ImportLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ShopGoodImagesController extends Controller
{
    protected $importLogService;
    
    public function __construct(ImportLogService $importLogService)
    {
        $this->importLogService = $importLogService;
    }
    /**
     * Получить изображения товара
     */
    public function index(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);
        
        // Фильтр по вариации
        if ($request->filled('variation_id')) {
            // Для вариаций: good_id = null, variation_id = ID вариации
            $variationId = $request->get('variation_id');
            $images = ShopGoodImage::whereNull('good_id')
                ->where('variation_id', $variationId)
                ->ordered()
                ->get();
            
            Log::info('Loading variation images', [
                'good_id' => $goodId,
                'variation_id' => $variationId,
                'images_count' => $images->count(),
                'images' => $images->map(fn($img) => [
                    'id' => $img->id,
                    'good_id' => $img->good_id,
                    'variation_id' => $img->variation_id,
                    'file_path' => $img->file_path
                ])
            ]);
        } else {
            // Для товаров: good_id = ID товара, variation_id = null
            $images = ShopGoodImage::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->ordered()
                ->get();
            
            Log::info('Loading product images', [
                'good_id' => $goodId,
                'images_count' => $images->count()
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $images
        ]);
    }

    /**
     * Получить все изображения товара с группировкой по вариациям
     */
    public function getAllWithVariations(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);
        
        // Получаем изображения товара (good_id = $goodId, variation_id = null)
        $goodImages = ShopGoodImage::where('good_id', $goodId)
            ->whereNull('variation_id')
            ->ordered()
            ->get();
        
        // Получаем изображения всех вариаций этого товара (good_id = null, variation_id IN (...))
        $variationIds = $good->variations()->pluck('id');
        $variationImages = ShopGoodImage::whereNull('good_id')
            ->whereIn('variation_id', $variationIds)
            ->ordered()
            ->get();
        
        // Группируем изображения по вариациям
        $groupedImages = [
            'good' => $goodImages, // Изображения товара
            'variations' => [] // Изображения вариаций
        ];
        
        foreach ($variationImages as $image) {
            if (!isset($groupedImages['variations'][$image->variation_id])) {
                $groupedImages['variations'][$image->variation_id] = [];
            }
            $groupedImages['variations'][$image->variation_id][] = $image;
        }
        
        return response()->json([
            'success' => true,
            'data' => $groupedImages
        ]);
    }

    /**
     * Загрузить изображение
     */
    public function store(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:51200', // 50MB
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
            
            // Создаем уникальное имя файла
            $filename = uniqid() . '.' . $image->getClientOriginalExtension();
            $path = "images/shop/goods/{$goodId}/{$filename}";
            
            // Путь к папке public фронтенда
            $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
            $fullPath = $frontendPublicPath . '/' . $path;
            $dir = dirname($fullPath);

            // Создаем директорию, если её нет
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Сохраняем файл на фронтенд
            $image->move($dir, $filename);

            $imageData = [
                'file_path' => $path,
                'alt_text' => $request->get('alt_text'),
                'is_main' => $request->boolean('is_main', false),
                'sort_order' => $request->get('sort_order', 0)
            ];

            // Отладочная информация
            \Log::info('Image upload debug', [
                'good_id' => $goodId,
                'variation_id' => $request->get('variation_id'),
                'has_variation_id' => $request->filled('variation_id'),
                'all_request_data' => $request->all()
            ]);

            if ($request->filled('variation_id')) {
                // Для вариаций: good_id = null, variation_id = ID вариации
                $variation = ShopGoodVariation::where('good_id', $goodId)
                    ->findOrFail($request->get('variation_id'));
                $imageData['variation_id'] = $variation->id;
                $imageData['good_id'] = null; // Для вариаций good_id = null
                \Log::info('Creating variation image', $imageData);
            } else {
                // Для товаров: good_id = ID товара, variation_id = null
                $imageData['good_id'] = $goodId;
                $imageData['variation_id'] = null;
                \Log::info('Creating good image', $imageData);
            }

            $goodImage = ShopGoodImage::create($imageData);

            // Если это главное изображение, снимаем флаг с других
            if ($goodImage->is_main) {
                if ($goodImage->variation_id) {
                    // Для вариаций: снимаем флаг с других изображений этой вариации
                    ShopGoodImage::where('variation_id', $goodImage->variation_id)
                        ->where('id', '!=', $goodImage->id)
                        ->update(['is_main' => false]);
                } else {
                    // Для товаров: снимаем флаг с других изображений этого товара
                    ShopGoodImage::where('good_id', $goodId)
                        ->where('id', '!=', $goodImage->id)
                        ->whereNull('variation_id')
                        ->update(['is_main' => false]);
                }
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
        // Ищем изображение по ID (может быть как товар, так и вариация)
        $image = ShopGoodImage::findOrFail($imageId);

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
            if ($image->variation_id) {
                // Для вариаций: снимаем флаг с других изображений этой вариации
                ShopGoodImage::where('variation_id', $image->variation_id)
                    ->where('id', '!=', $image->id)
                    ->update(['is_main' => false]);
            } else {
                // Для товаров: снимаем флаг с других изображений этого товара
                ShopGoodImage::where('good_id', $goodId)
                    ->where('id', '!=', $image->id)
                    ->whereNull('variation_id')
                    ->update(['is_main' => false]);
            }
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
        // Ищем изображение по ID (может быть как товар, так и вариация)
        $image = ShopGoodImage::findOrFail($imageId);

        try {
            // Удаляем файл с фронтенда
            $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
            $filePath = $frontendPublicPath . '/' . $image->file_path;
            if (file_exists($filePath)) {
                unlink($filePath);
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
        // Ищем изображение по ID (может быть как товар, так и вариация)
        $image = ShopGoodImage::findOrFail($imageId);

        // Снимаем флаг с других изображений
        if ($image->variation_id) {
            // Для вариаций: снимаем флаг с других изображений этой вариации
            ShopGoodImage::where('variation_id', $image->variation_id)
                ->where('id', '!=', $image->id)
                ->update(['is_main' => false]);
        } else {
            // Для товаров: снимаем флаг с других изображений этого товара
            ShopGoodImage::where('good_id', $goodId)
                ->where('id', '!=', $image->id)
                ->whereNull('variation_id')
                ->update(['is_main' => false]);
        }

        // Устанавливаем флаг для выбранного изображения
        $image->update(['is_main' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Главное изображение установлено'
        ]);
    }

    /**
     * Изменить порядок изображений товара
     */
    public function reorder(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order' => 'required|array',
            'order.*.id' => 'required|exists:shop_good_images,id',
            'order.*.sort_order' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Для товаров: good_id = ID товара, variation_id = null
        foreach ($request->get('order') as $imageData) {
            ShopGoodImage::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->where('id', $imageData['id'])
                ->update(['sort_order' => $imageData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Порядок изображений обновлен'
        ]);
    }

    /**
     * Изменить порядок изображений вариации
     */
    public function reorderVariation(Request $request, $variationId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order' => 'required|array',
            'order.*.id' => 'required|exists:shop_good_images,id',
            'order.*.sort_order' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Для вариаций: good_id = null, variation_id = ID вариации
        foreach ($request->get('order') as $imageData) {
            ShopGoodImage::whereNull('good_id')
                ->where('variation_id', $variationId)
                ->where('id', $imageData['id'])
                ->update(['sort_order' => $imageData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Порядок изображений вариации обновлен'
        ]);
    }

    /**
     * Пакетное создание связанных изображений (для импорта)
     */
    public function createFromImportBatch(Request $request): JsonResponse
    {
        // Валидация с поддержкой вариаций: либо good_id, либо variation_id должен быть указан
        $validator = Validator::make($request->all(), [
            'images' => 'required|array|min:1|max:100', // Максимум 100 изображений за раз
            'images.*.good_id' => 'nullable|exists:shop_goods,id',
            'images.*.variation_id' => 'nullable|exists:shop_good_variations,id',
            'images.*.file_path' => 'required|string',
            'images.*.alt_text' => 'nullable|string|max:255',
            'images.*.is_main' => 'boolean',
            'images.*.sort_order' => 'integer',
            'images.*.image_action' => 'nullable|in:add,replace,skip,unique'
        ]);
        
        // Дополнительная валидация: либо good_id, либо variation_id должен быть указан
        $validator->after(function ($validator) use ($request) {
            $images = $request->input('images', []);
            foreach ($images as $index => $image) {
                $hasGoodId = !empty($image['good_id']);
                $hasVariationId = !empty($image['variation_id']);
                
                if (!$hasGoodId && !$hasVariationId) {
                    $validator->errors()->add("images.{$index}.good_id", 'Необходимо указать либо good_id, либо variation_id');
                }
                
                if ($hasGoodId && $hasVariationId) {
                    $validator->errors()->add("images.{$index}.good_id", 'Нельзя указывать одновременно good_id и variation_id');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $images = $request->input('images');
            $results = [];
            $errors = [];
            $skipped = [];
            
            // Логируем входящие данные
            Log::info('ShopGoodImagesController::createFromImportBatch - Получены данные', [
                'images_count' => count($images),
                'first_image_sample' => $images[0] ?? null,
                'request_size' => strlen(json_encode($request->all()))
            ]);

            // Группируем изображения по товарам/вариациям для оптимизации
            $imagesByGood = [];
            $imagesByVariation = [];
            
            foreach ($images as $index => $imageData) {
                if (!empty($imageData['variation_id'])) {
                    // Изображение для вариации
                    $variationId = $imageData['variation_id'];
                    if (!isset($imagesByVariation[$variationId])) {
                        $imagesByVariation[$variationId] = [];
                    }
                    $imagesByVariation[$variationId][] = array_merge($imageData, ['_index' => $index]);
                } else {
                    // Изображение для товара
                    $goodId = $imageData['good_id'];
                    if (!isset($imagesByGood[$goodId])) {
                        $imagesByGood[$goodId] = [];
                    }
                    $imagesByGood[$goodId][] = array_merge($imageData, ['_index' => $index]);
                }
            }

            // Обрабатываем изображения для каждого товара
            foreach ($imagesByGood as $goodId => $goodImages) {
                $good = ShopGood::findOrFail($goodId);
                
                // Обрабатываем каждое изображение для товара
                foreach ($goodImages as $imageData) {
                    try {
                        $result = $this->processSingleImage($good, $imageData);
                        
                        if ($result['status'] === 'skipped') {
                            $skipped[] = $result;
                            // Логируем пропущенное изображение (файл уже существует)
                            $message = $result['message'] ?? 'Изображение пропущено';
                            $this->importLogService->logImageLoadingError(
                                $message,
                                $imageData['file_path'] ?? null,
                                $good->sku ?? null
                            );
                        } else {
                            $results[] = $result;
                        }
                    } catch (\Exception $e) {
                        Log::error('Ошибка создания изображения', [
                            'index' => $imageData['_index'],
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'],
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        $errors[] = [
                            'index' => $imageData['_index'],
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'],
                            'error' => $e->getMessage()
                        ];
                        
                        // Логируем ошибку загрузки изображения
                        $this->importLogService->logImageLoadingError(
                            $e->getMessage(),
                            $imageData['file_path'] ?? null,
                            $good->sku ?? null
                        );
                    }
                }
            }
            
            // Обрабатываем изображения для каждой вариации
            foreach ($imagesByVariation as $variationId => $variationImages) {
                $variation = ShopGoodVariation::findOrFail($variationId);
                
                // Обрабатываем каждое изображение для вариации
                foreach ($variationImages as $imageData) {
                    try {
                        $result = $this->processSingleVariationImage($variation, $imageData);
                        
                        if ($result['status'] === 'skipped') {
                            $skipped[] = $result;
                            // Логируем пропущенное изображение (файл уже существует)
                            $message = $result['message'] ?? 'Изображение пропущено';
                            $this->importLogService->logImageLoadingError(
                                $message,
                                $imageData['file_path'] ?? null,
                                $variation->sku ?? $variation->good->sku ?? null
                            );
                        } else {
                            $results[] = $result;
                        }
                    } catch (\Exception $e) {
                        Log::error('Ошибка создания изображения вариации', [
                            'index' => $imageData['_index'],
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'],
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        $errors[] = [
                            'index' => $imageData['_index'],
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'],
                            'error' => $e->getMessage()
                        ];
                        
                        // Логируем ошибку загрузки изображения
                        $this->importLogService->logImageLoadingError(
                            $e->getMessage(),
                            $imageData['file_path'] ?? null,
                            $variation->sku ?? $variation->good->sku ?? null
                        );
                    }
                }
            }

            // Логируем итоговые результаты
            Log::info('ShopGoodImagesController::createFromImportBatch - Завершено', [
                'total_images' => count($images),
                'successful' => count($results),
                'skipped' => count($skipped),
                'failed' => count($errors),
                'errors_summary' => array_map(function($error) {
                    return [
                        'good_id' => $error['good_id'],
                        'file_path' => $error['file_path'],
                        'error' => $error['error']
                    ];
                }, $errors)
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'created' => $results,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'total' => count($images),
                    'successful' => count($results),
                    'skipped_count' => count($skipped),
                    'failed' => count($errors)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка пакетного создания изображений: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработка одного изображения (вспомогательный метод)
     */
    private function processSingleImage(ShopGood $good, array $imageData): array
    {
        $imageAction = $imageData['image_action'] ?? 'add';
        $filePath = $imageData['file_path'];
        $altText = $imageData['alt_text'] ?? '';
        $isMain = $imageData['is_main'] ?? true;
        $sortOrder = $imageData['sort_order'] ?? 0;
        $goodId = $good->id;

        // Проверяем существование файла на фронтенде
        $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
        $fullFilePath = $frontendPublicPath . '/' . $filePath;
        if (!file_exists($fullFilePath)) {
            throw new \Exception('Файл изображения не найден: ' . $filePath);
        }

        // Проверяем, существует ли уже связь между товаром и изображением
        $existingImage = ShopGoodImage::where('good_id', $goodId)
            ->where('file_path', $filePath)
            ->first();
            
        if ($existingImage) {
            return [
                'good_id' => $goodId,
                'file_path' => $filePath,
                'image_id' => $existingImage->id,
                'status' => 'skipped',
                'message' => 'Связь товар-изображение уже существует'
            ];
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
                return [
                    'good_id' => $goodId,
                    'file_path' => $filePath,
                    'status' => 'skipped',
                    'message' => 'Изображения пропущены (уже существуют)'
                ];
            }
        } elseif ($imageAction === 'unique') {
            // Проверяем, есть ли уже изображение с таким же путем в базе
            $existingImage = ShopGoodImage::where('file_path', $filePath)->first();
            if ($existingImage) {
                return [
                    'good_id' => $goodId,
                    'file_path' => $filePath,
                    'status' => 'skipped',
                    'message' => 'Изображение пропущено (уже существует в базе)'
                ];
            }
        }

        // Создаем новое изображение
        $imageRecord = [
            'good_id' => $goodId,
            'file_path' => $filePath,
            'alt_text' => $altText,
            'is_main' => $isMain,
            'sort_order' => $sortOrder
        ];

        $goodImage = ShopGoodImage::create($imageRecord);
        
        // Логируем успешное создание
        Log::info('ShopGoodImage создано успешно', [
            'image_id' => $goodImage->id,
            'good_id' => $goodId,
            'file_path' => $filePath,
            'is_main' => $isMain,
            'sort_order' => $sortOrder
        ]);

        // Если это главное изображение, снимаем флаг с других
        if ($goodImage->is_main) {
            ShopGoodImage::where('good_id', $goodId)
                ->where('id', '!=', $goodImage->id)
                ->update(['is_main' => false]);
        }

        return [
            'good_id' => $goodId,
            'file_path' => $filePath,
            'image_id' => $goodImage->id,
            'status' => 'created',
            'message' => 'Изображение успешно создано'
        ];
    }
    
    /**
     * Обработка одного изображения вариации (вспомогательный метод)
     */
    private function processSingleVariationImage(ShopGoodVariation $variation, array $imageData): array
    {
        $imageAction = $imageData['image_action'] ?? 'add';
        $filePath = $imageData['file_path'];
        $altText = $imageData['alt_text'] ?? '';
        $isMain = $imageData['is_main'] ?? true;
        $sortOrder = $imageData['sort_order'] ?? 0;
        $variationId = $variation->id;

        // Проверяем существование файла на фронтенде
        $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
        $fullFilePath = $frontendPublicPath . '/' . $filePath;
        if (!file_exists($fullFilePath)) {
            throw new \Exception('Файл изображения не найден: ' . $filePath);
        }

        // Проверяем, существует ли уже связь между вариацией и изображением
        $existingImage = ShopGoodImage::whereNull('good_id')
            ->where('variation_id', $variationId)
            ->where('file_path', $filePath)
            ->first();
            
        if ($existingImage) {
            return [
                'variation_id' => $variationId,
                'file_path' => $filePath,
                'image_id' => $existingImage->id,
                'status' => 'skipped',
                'message' => 'Связь вариация-изображение уже существует'
            ];
        }

        // Обрабатываем действие с изображениями
        if ($imageAction === 'replace') {
            // Удаляем все существующие изображения вариации
            $existingImages = ShopGoodImage::whereNull('good_id')
                ->where('variation_id', $variationId)
                ->get();
            foreach ($existingImages as $existingImage) {
                if (Storage::disk('public')->exists($existingImage->file_path)) {
                    Storage::disk('public')->delete($existingImage->file_path);
                }
                $existingImage->delete();
            }
        } elseif ($imageAction === 'skip') {
            // Проверяем, есть ли уже изображения у вариации
            $existingCount = ShopGoodImage::whereNull('good_id')
                ->where('variation_id', $variationId)
                ->count();
            if ($existingCount > 0) {
                return [
                    'variation_id' => $variationId,
                    'file_path' => $filePath,
                    'status' => 'skipped',
                    'message' => 'Изображения пропущены (уже существуют)'
                ];
            }
        } elseif ($imageAction === 'unique') {
            // Проверяем, есть ли уже изображение с таким же путем в базе
            $existingImage = ShopGoodImage::where('file_path', $filePath)->first();
            if ($existingImage) {
                return [
                    'variation_id' => $variationId,
                    'file_path' => $filePath,
                    'status' => 'skipped',
                    'message' => 'Изображение пропущено (уже существует в базе)'
                ];
            }
        }

        // Создаем новое изображение для вариации
        $imageRecord = [
            'good_id' => null, // Для вариаций good_id = null
            'variation_id' => $variationId,
            'file_path' => $filePath,
            'alt_text' => $altText,
            'is_main' => $isMain,
            'sort_order' => $sortOrder
        ];

        $goodImage = ShopGoodImage::create($imageRecord);
        
        // Логируем успешное создание
        Log::info('ShopGoodImage для вариации создано успешно', [
            'image_id' => $goodImage->id,
            'variation_id' => $variationId,
            'file_path' => $filePath,
            'is_main' => $isMain,
            'sort_order' => $sortOrder
        ]);

        // Если это главное изображение, снимаем флаг с других изображений этой вариации
        if ($goodImage->is_main) {
            ShopGoodImage::whereNull('good_id')
                ->where('variation_id', $variationId)
                ->where('id', '!=', $goodImage->id)
                ->update(['is_main' => false]);
        }

        return [
            'variation_id' => $variationId,
            'file_path' => $filePath,
            'image_id' => $goodImage->id,
            'status' => 'created',
            'message' => 'Изображение вариации успешно создано'
        ];
    }

    /**
     * Создать связанное изображение (для импорта)
     */
    public function createFromImport(Request $request, $goodId): JsonResponse
    {

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
