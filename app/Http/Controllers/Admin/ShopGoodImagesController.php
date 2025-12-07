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
            'images' => 'required|array|min:1|max:1000', // Максимум 1000 изображений за раз
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

            // Обрабатываем изображения для каждого товара пакетно
            foreach ($imagesByGood as $goodId => $goodImages) {
                try {
                    $good = ShopGood::findOrFail($goodId);
                    $batchResults = $this->processImagesBatch($good, $goodImages);
                    
                    // Добавляем результаты в общие массивы
                    $results = array_merge($results, $batchResults['created'] ?? []);
                    $results = array_merge($results, $batchResults['updated'] ?? []);
                    $skipped = array_merge($skipped, $batchResults['skipped'] ?? []);
                    $errors = array_merge($errors, $batchResults['errors'] ?? []);
                } catch (\Exception $e) {
                    Log::error('Ошибка пакетной обработки изображений для товара', [
                        'good_id' => $goodId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    // Добавляем ошибку для всех изображений этого товара
                    foreach ($goodImages as $imageData) {
                        $errors[] = [
                            'index' => $imageData['_index'] ?? null,
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'] ?? null,
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }
            
            // Обрабатываем изображения для каждой вариации пакетно
            foreach ($imagesByVariation as $variationId => $variationImages) {
                try {
                    $variation = ShopGoodVariation::findOrFail($variationId);
                    $batchResults = $this->processVariationImagesBatch($variation, $variationImages);
                    
                    // Добавляем результаты в общие массивы
                    $results = array_merge($results, $batchResults['created'] ?? []);
                    $results = array_merge($results, $batchResults['updated'] ?? []);
                    $skipped = array_merge($skipped, $batchResults['skipped'] ?? []);
                    $errors = array_merge($errors, $batchResults['errors'] ?? []);
                } catch (\Exception $e) {
                    Log::error('Ошибка пакетной обработки изображений для вариации', [
                        'variation_id' => $variationId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    // Добавляем ошибку для всех изображений этой вариации
                    foreach ($variationImages as $imageData) {
                        $errors[] = [
                            'index' => $imageData['_index'] ?? null,
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'] ?? null,
                            'error' => $e->getMessage()
                        ];
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
     * Пакетная обработка изображений для товара (оптимизированный метод)
     */
    private function processImagesBatch(ShopGood $good, array $imagesData): array
    {
        $goodId = $good->id;
        $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
        $results = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
        
        // Проверяем существование всех файлов и валидируем данные
        $validImages = [];
        foreach ($imagesData as $imageData) {
            $filePath = $imageData['file_path'] ?? '';
            $fullFilePath = $frontendPublicPath . '/' . $filePath;
            
            if (!file_exists($fullFilePath)) {
                $results['errors'][] = [
                    'good_id' => $goodId,
                    'file_path' => $filePath,
                    'error' => 'Файл изображения не найден: ' . $filePath
                ];
                continue;
            }
            
            $validImages[] = $imageData;
        }
        
        if (empty($validImages)) {
            return $results;
        }
        
        // Получаем все существующие связи одним запросом
        $filePaths = array_column($validImages, 'file_path');
        $existingImages = ShopGoodImage::where('good_id', $goodId)
            ->whereIn('file_path', $filePaths)
            ->get()
            ->keyBy('file_path');
        
        // Разделяем на те, что нужно обновить, и те, что нужно создать
        $toUpdate = [];
        $toCreate = [];
        
        foreach ($validImages as $imageData) {
            $filePath = $imageData['file_path'];
            $existingImage = $existingImages->get($filePath);
            
            if ($existingImage) {
                // Обновляем существующую связь
                $toUpdate[] = [
                    'id' => $existingImage->id,
                    'data' => $imageData
                ];
            } else {
                // Создаем новую связь
                $toCreate[] = $imageData;
            }
        }
        
        // Массовое обновление существующих связей
        if (!empty($toUpdate)) {
            foreach ($toUpdate as $updateItem) {
                try {
                    $existingImage = ShopGoodImage::find($updateItem['id']);
                    if ($existingImage) {
                        $imageData = $updateItem['data'];
                        $existingImage->alt_text = $imageData['alt_text'] ?? '';
                        $existingImage->is_main = $imageData['is_main'] ?? false;
                        $existingImage->sort_order = $imageData['sort_order'] ?? 0;
                        $existingImage->save();
                        
                        $results['updated'][] = [
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'],
                            'image_id' => $existingImage->id,
                            'status' => 'updated',
                            'message' => 'Связь товар-изображение обновлена'
                        ];
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'good_id' => $goodId,
                        'file_path' => $updateItem['data']['file_path'] ?? null,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Снимаем флаг is_main с других изображений, если есть новые главные
            $hasMainImages = collect($toUpdate)->contains(function ($item) {
                return ($item['data']['is_main'] ?? false) == true;
            });
            
            if ($hasMainImages) {
                $mainImageIds = collect($toUpdate)
                    ->filter(function ($item) {
                        return ($item['data']['is_main'] ?? false) == true;
                    })
                    ->pluck('id')
                    ->toArray();
                
                ShopGoodImage::where('good_id', $goodId)
                    ->whereNotIn('id', $mainImageIds)
                    ->update(['is_main' => false]);
            }
        }
        
        // Массовое создание новых связей
        if (!empty($toCreate)) {
            $insertData = [];
            foreach ($toCreate as $imageData) {
                $insertData[] = [
                    'good_id' => $goodId,
                    'variation_id' => null,
                    'file_path' => $imageData['file_path'],
                    'alt_text' => $imageData['alt_text'] ?? '',
                    'is_main' => $imageData['is_main'] ?? false ? 1 : 0,
                    'sort_order' => $imageData['sort_order'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
            
            try {
                // Используем массовую вставку
                ShopGoodImage::insert($insertData);
                
                // Получаем созданные записи для возврата результатов
                $createdImages = ShopGoodImage::where('good_id', $goodId)
                    ->whereIn('file_path', array_column($toCreate, 'file_path'))
                    ->get();
                
                foreach ($createdImages as $createdImage) {
                    $results['created'][] = [
                        'good_id' => $goodId,
                        'file_path' => $createdImage->file_path,
                        'image_id' => $createdImage->id,
                        'status' => 'created',
                        'message' => 'Изображение успешно создано'
                    ];
                }
                
                // Снимаем флаг is_main с других изображений, если есть новые главные
                $hasMainImages = collect($toCreate)->contains(function ($item) {
                    return ($item['is_main'] ?? false) == true;
                });
                
                if ($hasMainImages) {
                    $mainImageIds = $createdImages->where('is_main', true)->pluck('id')->toArray();
                    if (!empty($mainImageIds)) {
                        ShopGoodImage::where('good_id', $goodId)
                            ->whereNotIn('id', $mainImageIds)
                            ->update(['is_main' => false]);
                    }
                }
            } catch (\Exception $e) {
                // Если массовая вставка не удалась, пробуем по одной
                foreach ($toCreate as $imageData) {
                    try {
                        $imageRecord = [
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'],
                            'alt_text' => $imageData['alt_text'] ?? '',
                            'is_main' => $imageData['is_main'] ?? false ? 1 : 0,
                            'sort_order' => $imageData['sort_order'] ?? 0
                        ];
                        
                        $goodImage = ShopGoodImage::create($imageRecord);
                        
                        $results['created'][] = [
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'],
                            'image_id' => $goodImage->id,
                            'status' => 'created',
                            'message' => 'Изображение успешно создано'
                        ];
                    } catch (\Exception $createError) {
                        $results['errors'][] = [
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'] ?? null,
                            'error' => $createError->getMessage()
                        ];
                    }
                }
            }
        }
        
        return $results;
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
        
        // Логируем для диагностики
        Log::debug('Проверка существования связи изображения', [
            'good_id' => $goodId,
            'file_path' => $filePath,
            'existing_image_found' => $existingImage ? true : false,
            'existing_image_id' => $existingImage->id ?? null
        ]);
            
        if ($existingImage) {
            // Связь уже существует - обновляем данные изображения (alt_text, is_main, sort_order)
            // Это позволяет обновить метаданные даже если связь уже существует
            $existingImage->alt_text = $altText;
            $existingImage->is_main = $isMain;
            $existingImage->sort_order = $sortOrder;
            $existingImage->save();
            
            Log::debug('Связь уже существует, обновлены метаданные', [
                'good_id' => $goodId,
                'file_path' => $filePath,
                'image_id' => $existingImage->id
            ]);
            
            // Если это главное изображение, снимаем флаг с других
            if ($existingImage->is_main) {
                ShopGoodImage::where('good_id', $goodId)
                    ->where('id', '!=', $existingImage->id)
                    ->update(['is_main' => false]);
            }
            
            return [
                'good_id' => $goodId,
                'file_path' => $filePath,
                'image_id' => $existingImage->id,
                'status' => 'updated',
                'message' => 'Связь товар-изображение обновлена'
            ];
        }
        
        // Если связи нет, но файл существует - создаем связь
        Log::debug('Связь не найдена, создаем новую', [
            'good_id' => $goodId,
            'file_path' => $filePath
        ]);

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
            // При 'skip' проверяем только конкретную связь для этого файла
            // Если связь уже существует (проверено выше), пропускаем
            // Если связи нет, но файл существует - создаем связь
            // Это позволяет привязать существующие файлы к товарам при обновлении
        } elseif ($imageAction === 'unique') {
            // Проверяем, есть ли уже изображение с таким же путем в базе (для любого товара)
            $existingImageGlobal = ShopGoodImage::where('file_path', $filePath)->first();
            if ($existingImageGlobal) {
                // Если изображение уже привязано к другому товару, пропускаем
                if ($existingImageGlobal->good_id != $goodId) {
                    return [
                        'good_id' => $goodId,
                        'file_path' => $filePath,
                        'status' => 'skipped',
                        'message' => 'Изображение пропущено (уже привязано к другому товару)'
                    ];
                }
                // Если изображение уже привязано к этому товару, это уже обработано выше
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

        Log::debug('Создание записи изображения в БД', [
            'good_id' => $goodId,
            'file_path' => $filePath,
            'image_action' => $imageAction
        ]);

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
     * Пакетная обработка изображений для вариации (оптимизированный метод)
     */
    private function processVariationImagesBatch(ShopGoodVariation $variation, array $imagesData): array
    {
        $variationId = $variation->id;
        $frontendPublicPath = base_path('../admin.skateandsnow.ru/public');
        $results = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
        
        // Проверяем существование всех файлов и валидируем данные
        $validImages = [];
        foreach ($imagesData as $imageData) {
            $filePath = $imageData['file_path'] ?? '';
            $fullFilePath = $frontendPublicPath . '/' . $filePath;
            
            if (!file_exists($fullFilePath)) {
                $results['errors'][] = [
                    'variation_id' => $variationId,
                    'file_path' => $filePath,
                    'error' => 'Файл изображения не найден: ' . $filePath
                ];
                continue;
            }
            
            $validImages[] = $imageData;
        }
        
        if (empty($validImages)) {
            return $results;
        }
        
        // Получаем все существующие связи одним запросом
        $filePaths = array_column($validImages, 'file_path');
        $existingImages = ShopGoodImage::whereNull('good_id')
            ->where('variation_id', $variationId)
            ->whereIn('file_path', $filePaths)
            ->get()
            ->keyBy('file_path');
        
        // Разделяем на те, что нужно обновить, и те, что нужно создать
        $toUpdate = [];
        $toCreate = [];
        
        foreach ($validImages as $imageData) {
            $filePath = $imageData['file_path'];
            $existingImage = $existingImages->get($filePath);
            
            if ($existingImage) {
                // Обновляем существующую связь
                $toUpdate[] = [
                    'id' => $existingImage->id,
                    'data' => $imageData
                ];
            } else {
                // Создаем новую связь
                $toCreate[] = $imageData;
            }
        }
        
        // Массовое обновление существующих связей
        if (!empty($toUpdate)) {
            foreach ($toUpdate as $updateItem) {
                try {
                    $existingImage = ShopGoodImage::find($updateItem['id']);
                    if ($existingImage) {
                        $imageData = $updateItem['data'];
                        $existingImage->alt_text = $imageData['alt_text'] ?? '';
                        $existingImage->is_main = $imageData['is_main'] ?? false;
                        $existingImage->sort_order = $imageData['sort_order'] ?? 0;
                        $existingImage->save();
                        
                        $results['updated'][] = [
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'],
                            'image_id' => $existingImage->id,
                            'status' => 'updated',
                            'message' => 'Связь вариация-изображение обновлена'
                        ];
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'variation_id' => $variationId,
                        'file_path' => $updateItem['data']['file_path'] ?? null,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Снимаем флаг is_main с других изображений, если есть новые главные
            $hasMainImages = collect($toUpdate)->contains(function ($item) {
                return ($item['data']['is_main'] ?? false) == true;
            });
            
            if ($hasMainImages) {
                $mainImageIds = collect($toUpdate)
                    ->filter(function ($item) {
                        return ($item['data']['is_main'] ?? false) == true;
                    })
                    ->pluck('id')
                    ->toArray();
                
                ShopGoodImage::whereNull('good_id')
                    ->where('variation_id', $variationId)
                    ->whereNotIn('id', $mainImageIds)
                    ->update(['is_main' => false]);
            }
        }
        
        // Массовое создание новых связей
        if (!empty($toCreate)) {
            $insertData = [];
            foreach ($toCreate as $imageData) {
                $insertData[] = [
                    'good_id' => null,
                    'variation_id' => $variationId,
                    'file_path' => $imageData['file_path'],
                    'alt_text' => $imageData['alt_text'] ?? '',
                    'is_main' => $imageData['is_main'] ?? false ? 1 : 0,
                    'sort_order' => $imageData['sort_order'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
            
            try {
                // Используем массовую вставку
                ShopGoodImage::insert($insertData);
                
                // Получаем созданные записи для возврата результатов
                $createdImages = ShopGoodImage::whereNull('good_id')
                    ->where('variation_id', $variationId)
                    ->whereIn('file_path', array_column($toCreate, 'file_path'))
                    ->get();
                
                foreach ($createdImages as $createdImage) {
                    $results['created'][] = [
                        'variation_id' => $variationId,
                        'file_path' => $createdImage->file_path,
                        'image_id' => $createdImage->id,
                        'status' => 'created',
                        'message' => 'Изображение вариации успешно создано'
                    ];
                }
                
                // Снимаем флаг is_main с других изображений, если есть новые главные
                $hasMainImages = collect($toCreate)->contains(function ($item) {
                    return ($item['is_main'] ?? false) == true;
                });
                
                if ($hasMainImages) {
                    $mainImageIds = $createdImages->where('is_main', true)->pluck('id')->toArray();
                    if (!empty($mainImageIds)) {
                        ShopGoodImage::whereNull('good_id')
                            ->where('variation_id', $variationId)
                            ->whereNotIn('id', $mainImageIds)
                            ->update(['is_main' => false]);
                    }
                }
            } catch (\Exception $e) {
                // Если массовая вставка не удалась, пробуем по одной
                foreach ($toCreate as $imageData) {
                    try {
                        $imageRecord = [
                            'good_id' => null,
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'],
                            'alt_text' => $imageData['alt_text'] ?? '',
                            'is_main' => $imageData['is_main'] ?? false ? 1 : 0,
                            'sort_order' => $imageData['sort_order'] ?? 0
                        ];
                        
                        $goodImage = ShopGoodImage::create($imageRecord);
                        
                        $results['created'][] = [
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'],
                            'image_id' => $goodImage->id,
                            'status' => 'created',
                            'message' => 'Изображение вариации успешно создано'
                        ];
                    } catch (\Exception $createError) {
                        $results['errors'][] = [
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'] ?? null,
                            'error' => $createError->getMessage()
                        ];
                    }
                }
            }
        }
        
        return $results;
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
            // Связь уже существует - обновляем данные изображения (alt_text, is_main, sort_order)
            // Это позволяет обновить метаданные даже если связь уже существует
            $existingImage->alt_text = $altText;
            $existingImage->is_main = $isMain;
            $existingImage->sort_order = $sortOrder;
            $existingImage->save();
            
            Log::debug('Связь вариации уже существует, обновлены метаданные', [
                'variation_id' => $variationId,
                'file_path' => $filePath,
                'image_id' => $existingImage->id
            ]);
            
            // Если это главное изображение, снимаем флаг с других
            if ($existingImage->is_main) {
                ShopGoodImage::whereNull('good_id')
                    ->where('variation_id', $variationId)
                    ->where('id', '!=', $existingImage->id)
                    ->update(['is_main' => false]);
            }
            
            return [
                'variation_id' => $variationId,
                'file_path' => $filePath,
                'image_id' => $existingImage->id,
                'status' => 'updated',
                'message' => 'Связь вариация-изображение обновлена'
            ];
        }
        
        // Если связи нет, но файл существует - создаем связь
        // Это позволяет привязать существующие файлы к вариациям при обновлении

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
            // При 'skip' проверяем только конкретную связь для этого файла
            // Если связь уже существует (проверено выше), пропускаем
            // Если связи нет, но файл существует - создаем связь
            // Это позволяет привязать существующие файлы к вариациям при обновлении
        } elseif ($imageAction === 'unique') {
            // Проверяем, есть ли уже изображение с таким же путем в базе (для любой вариации)
            $existingImageGlobal = ShopGoodImage::where('file_path', $filePath)->first();
            if ($existingImageGlobal) {
                // Если изображение уже привязано к другой вариации, пропускаем
                if ($existingImageGlobal->variation_id != $variationId) {
                    return [
                        'variation_id' => $variationId,
                        'file_path' => $filePath,
                        'status' => 'skipped',
                        'message' => 'Изображение пропущено (уже привязано к другой вариации)'
                    ];
                }
                // Если изображение уже привязано к этой вариации, это уже обработано выше
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
