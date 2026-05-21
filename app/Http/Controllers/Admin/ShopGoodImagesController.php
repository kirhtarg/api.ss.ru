<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;
use App\Models\ShopGoodVariation;
use App\Services\ImportLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

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
            'file_path' => $img->file_path,
            ]),
            ]);
        }
        else {
            // Для товаров: good_id = ID товара, variation_id = null
            $images = ShopGoodImage::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->ordered()
                ->get();

            Log::info('Loading product images', [
                'good_id' => $goodId,
                'images_count' => $images->count(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $images,
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
            'variations' => [], // Изображения вариаций
        ];

        foreach ($variationImages as $image) {
            if (!isset($groupedImages['variations'][$image->variation_id])) {
                $groupedImages['variations'][$image->variation_id] = [];
            }
            $groupedImages['variations'][$image->variation_id][] = $image;
        }

        return response()->json([
            'success' => true,
            'data' => $groupedImages,
        ]);
    }

    /**
     * Загрузить изображение
     */
    public function store(Request $request, $goodId): JsonResponse
    {

        // Дополнительный простой лог
        file_put_contents('F:/Work/Projects/SS/api.ss.ru/storage/logs/laravel.log', '[' . date('Y-m-d H:i:s') . "] STORE_LOGGING: goodId=$goodId\n", FILE_APPEND);

        \Log::info('=== PNG DEBUG: ShopGoodImagesController::store START ===', [
            'goodId' => $goodId,
            'timestamp' => now(),
            'method' => $request->method(),
            'has_files' => $request->hasFile('images') || $request->hasFile('image'),
            'files_count' => count($request->allFiles()),
            'all_files' => array_keys($request->allFiles()),
            'request_data_keys' => array_keys($request->all()),
            'content_type' => $request->header('Content-Type'),
            'user_id' => auth()->id(),
            'bearer_token' => $request->bearerToken() ? 'present' : 'missing',
        ]);

        $good = ShopGood::findOrFail($goodId);

        $validator = Validator::make($request->all(), [
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:51200', // 50MB
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:51200',
            'variation_id' => 'nullable|exists:shop_good_variations,id',
            'alt_text' => 'nullable|string|max:255',
            'is_main' => 'boolean',
            'sort_order' => 'integer',
            'upload_type' => 'nullable|string|in:system_fit,system_crop,original,custom_fit',
            'custom_width' => 'nullable|integer|min:1|max:5000',
            'custom_height' => 'nullable|integer|min:1|max:5000',
            'white_background' => 'nullable|boolean',
            'fit_with_white_background' => 'nullable|boolean',
        ]);

        // Дополнительная валидация: должно быть либо image, либо images
        $validator->after(function ($validator) use ($request) {
            $hasImage = $request->hasFile('image');
            $hasImages = $request->hasFile('images') || !empty($request->allFiles()['images'] ?? []);
            $hasImagesArray = !empty($request->allFiles()['images'] ?? []);

            if (!$hasImage && !$hasImages && !$hasImagesArray) {
                $validator->errors()->add('image', 'Необходимо указать хотя бы одно изображение');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Определяем, есть ли множественные изображения
            // Для массива файлов images[] используем file('images')
            $images = [];
            if ($request->hasFile('images')) {
                $imagesFiles = $request->file('images');
                // Если это массив, используем его, иначе оборачиваем в массив
                if (is_array($imagesFiles)) {
                    $images = $imagesFiles;
                }
                elseif ($imagesFiles) {
                    $images = [$imagesFiles];
                }
            }
            $singleImage = $request->hasFile('image') ? $request->file('image') : null;

            // Если есть множественные изображения, обрабатываем их
            if (!empty($images) && is_array($images)) {
                $uploadedImages = [];
                $uploadType = $request->input('upload_type', 'system_fit');
                // Читаем параметры как строки и конвертируем в boolean
                $whiteBackground = filter_var($request->input('white_background', '1'), FILTER_VALIDATE_BOOLEAN);
                $fitWithWhiteBackground = filter_var($request->input('fit_with_white_background', '1'), FILTER_VALIDATE_BOOLEAN);

                Log::info('DEBUG: Processing multiple images', [
                    'uploadType' => $uploadType,
                    'whiteBackground' => $whiteBackground,
                    'fitWithWhiteBackground' => $fitWithWhiteBackground,
                    'images_count' => count($images),
                ]);

                foreach ($images as $index => $image) {
                    Log::info('DEBUG: Processing image in batch', [
                        'index' => $index,
                        'filename' => $image->getClientOriginalName(),
                        'size' => $image->getSize(),
                    ]);

                    $uploadedImage = $this->processAndSaveImage(
                        $image,
                        $goodId,
                        $uploadType,
                        $request->input('custom_width'),
                        $request->input('custom_height'),
                        $whiteBackground,
                        $fitWithWhiteBackground
                    );

                    if ($uploadedImage) {
                        $imageData = [
                            'file_path' => $uploadedImage['path'],
                            'alt_text' => $request->get('alt_text'),
                            'is_main' => false,
                            'sort_order' => count($uploadedImages),
                        ];

                        if ($request->filled('variation_id')) {
                            $variation = ShopGoodVariation::where('good_id', $goodId)
                                ->findOrFail($request->get('variation_id'));
                            $imageData['variation_id'] = $variation->id;
                            $imageData['good_id'] = null;
                        }
                        else {
                            $imageData['good_id'] = $goodId;
                            $imageData['variation_id'] = null;
                        }

                        $goodImage = ShopGoodImage::create($imageData);
                        $uploadedImages[] = $goodImage;
                    }
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Изображения успешно загружены',
                    'data' => $uploadedImages,
                ], 201);
            }

            // Обработка одного изображения
            if (!$singleImage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указано изображение для загрузки',
                ], 422);
            }

            $uploadType = $request->input('upload_type', 'system_fit');
            // Читаем параметры как строки и конвертируем в boolean
            $whiteBackground = filter_var($request->input('white_background', '1'), FILTER_VALIDATE_BOOLEAN);
            $fitWithWhiteBackground = filter_var($request->input('fit_with_white_background', '1'), FILTER_VALIDATE_BOOLEAN);

            $uploadedImage = $this->processAndSaveImage(
                $singleImage,
                $goodId,
                $uploadType,
                $request->input('custom_width'),
                $request->input('custom_height'),
                $whiteBackground,
                $fitWithWhiteBackground
            );

            if (!$uploadedImage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка обработки изображения',
                ], 500);
            }

            $path = $uploadedImage['path'];

            $imageData = [
                'file_path' => $path,
                'alt_text' => $request->get('alt_text'),
                'is_main' => $request->boolean('is_main', false),
                'sort_order' => $request->get('sort_order', 0),
            ];

            // Отладочная информация
            \Log::info('Image upload debug', [
                'good_id' => $goodId,
                'variation_id' => $request->get('variation_id'),
                'has_variation_id' => $request->filled('variation_id'),
                'all_request_data' => $request->all(),
            ]);

            if ($request->filled('variation_id')) {
                // Для вариаций: good_id = null, variation_id = ID вариации
                $variation = ShopGoodVariation::where('good_id', $goodId)
                    ->findOrFail($request->get('variation_id'));
                $imageData['variation_id'] = $variation->id;
                $imageData['good_id'] = null; // Для вариаций good_id = null
                \Log::info('Creating variation image', $imageData);
            }
            else {
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
                }
                else {
                    // Для товаров: снимаем флаг с других изображений этого товара
                    ShopGoodImage::where('good_id', $goodId)
                        ->where('id', '!=', $goodImage->id)
                        ->whereNull('variation_id')
                        ->update(['is_main' => false]);
                }
            }

            \Log::info('=== PNG DEBUG: ShopGoodImagesController::store END SUCCESS ===', [
                'goodId' => $goodId,
                'image_id' => $goodImage->id,
                'file_path' => $goodImage->file_path,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'data' => $goodImage,
            ], 201);

        }
        catch (\Exception $e) {

            \Log::error('=== PNG DEBUG: ShopGoodImagesController::store ERROR ===', [
                'goodId' => $goodId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage(),
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
            'sort_order' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
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
            }
            else {
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
            'data' => $image,
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
            $frontendPublicPath = frontend_public_path();
            $filePath = $frontendPublicPath . '/' . $image->file_path;
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $image->delete();

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено',
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Пакетное удаление изображений
     */
    public function destroyBatch(Request $request, $goodId): JsonResponse
    {
        $request->validate([
            'image_ids' => 'required|array|min:1|max:100', // Максимум 100 изображений за раз
            'image_ids.*' => 'required|integer|exists:shop_good_images,id',
        ]);

        $imageIds = $request->input('image_ids', []);
        $deleted = [];
        $errors = [];

        $frontendPublicPath = frontend_public_path();

        foreach ($imageIds as $imageId) {
            try {
                $image = ShopGoodImage::findOrFail($imageId);

                // Удаляем файл с фронтенда
                $filePath = $frontendPublicPath . '/' . $image->file_path;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                $image->delete();
                $deleted[] = $imageId;

            }
            catch (\Exception $e) {
                $errors[] = [
                    'image_id' => $imageId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => count($errors) === 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'total_deleted' => count($deleted),
            'total_errors' => count($errors),
        ]);
    }

    /**
     * Пакетное удаление изображений выбранных вариаций
     */
    public function destroyBatchByVariations(Request $request, $goodId): JsonResponse
    {
        $request->validate([
            'variation_ids' => 'required|array|min:1',
            'variation_ids.*' => 'required|integer|exists:shop_good_variations,id',
        ]);

        $variationIds = $request->input('variation_ids', []);

        // Находим все изображения, привязанные к указанным вариациям
        $images = ShopGoodImage::whereIn('variation_id', $variationIds)->get();

        $deleted = [];
        $errors = [];
        $frontendPublicPath = frontend_public_path();

        foreach ($images as $image) {
            try {
                // Удаляем файл с фронтенда
                $filePath = $frontendPublicPath . '/' . $image->file_path;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                $imageId = $image->id;
                $image->delete();
                $deleted[] = $imageId;

            }
            catch (\Exception $e) {
                $errors[] = [
                    'image_id' => $image->id,
                    'variation_id' => $image->variation_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => count($errors) === 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'total_deleted' => count($deleted),
            'total_errors' => count($errors),
            'message' => 'Удалено изображений: ' . count($deleted),
        ]);
    }

    /**
     * Пакетное копирование изображений из одной вариации в другие
     */
    public function copyBatchByVariations(Request $request, $goodId): JsonResponse
    {
        $request->validate([
            'source_variation_id' => 'required|integer|exists:shop_good_variations,id',
            'target_variation_ids' => 'required|array|min:1',
            'target_variation_ids.*' => 'required|integer|exists:shop_good_variations,id',
        ]);

        $sourceVariationId = $request->input('source_variation_id');
        $targetVariationIds = $request->input('target_variation_ids');

        // Получаем изображения исходной вариации
        $sourceImages = ShopGoodImage::where('variation_id', $sourceVariationId)
            ->ordered()
            ->get();

        if ($sourceImages->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'В исходной вариации нет изображений',
            ], 404);
        }

        $results = [
            'total_copied' => 0,
            'errors' => [],
        ];

        $frontendPublicPath = frontend_public_path();

        foreach ($targetVariationIds as $targetVariationId) {
            // Пропускаем, если целевая вариация совпадает с исходной
            if ($targetVariationId == $sourceVariationId) {
                continue;
            }

            foreach ($sourceImages as $sourceImage) {
                try {
                    $sourcePath = $frontendPublicPath . '/' . $sourceImage->file_path;

                    if (!file_exists($sourcePath)) {
                        $results['errors'][] = "Файл не найден: {$sourceImage->file_path}";

                        continue;
                    }

                    // Генерируем новое имя файла
                    $pathInfo = pathinfo($sourceImage->file_path);
                    $extension = $pathInfo['extension'] ?? 'jpg';
                    $newFileName = 'variation_' . $targetVariationId . '_' . uniqid() . '.' . $extension;
                    $newRelativePath = ($pathInfo['dirname'] !== '.' ? $pathInfo['dirname'] : 'images') . '/' . $newFileName;
                    $newFullPath = $frontendPublicPath . '/' . $newRelativePath;

                    // Убедимся, что директория существует
                    $directory = dirname($newFullPath);
                    if (!file_exists($directory)) {
                        mkdir($directory, 0755, true);
                    }

                    // Копируем файл
                    if (copy($sourcePath, $newFullPath)) {
                        // Создаем запись в БД
                        ShopGoodImage::create([
                            'good_id' => null,
                            'variation_id' => $targetVariationId,
                            'file_path' => $newRelativePath,
                            'alt_text' => $sourceImage->alt_text,
                            'is_main' => $sourceImage->is_main,
                            'sort_order' => $sourceImage->sort_order,
                        ]);

                        $results['total_copied']++;
                    }
                    else {
                        $results['errors'][] = "Не удалось скопировать файл для вариации {$targetVariationId}";
                    }

                }
                catch (\Exception $e) {
                    $results['errors'][] = "Ошибка при копировании для вариации {$targetVariationId}: " . $e->getMessage();
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Скопировано {$results['total_copied']} изображений",
            'data' => $results,
        ]);
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
        }
        else {
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
            'message' => 'Главное изображение установлено',
        ]);
    }

    /**
     * Привязать изображение товара к вариации
     */
    public function linkVariation(Request $request, $goodId, $imageId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'variation_id' => 'required|exists:shop_good_variations,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Проверяем, что товар существует
            $good = ShopGood::findOrFail($goodId);

            // Проверяем, что изображение существует и принадлежит товару
            $image = ShopGoodImage::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->findOrFail($imageId);

            // Проверяем, что вариация существует и принадлежит товару
            $variation = ShopGoodVariation::where('good_id', $goodId)
                ->findOrFail($request->get('variation_id'));

            // Обновляем изображение: привязываем к вариации
            $image->update([
                'good_id' => null,
                'variation_id' => $variation->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно привязано к вариации',
                'data' => $image,
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка привязки изображения к вариации: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Изменить порядок изображений товара
     */
    public function reorder(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order' => 'required|array',
            'order.*.id' => 'required|exists:shop_good_images,id',
            'order.*.sort_order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
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
            'message' => 'Порядок изображений обновлен',
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
            'order.*.sort_order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
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
            'message' => 'Порядок изображений вариации обновлен',
        ]);
    }

    /**
     * Пакетное создание связанных изображений (для импорта)
     */
    public function createFromImportBatch(Request $request): JsonResponse
    {
        // Валидация с поддержкой вариаций: либо good_id, либо variation_id должен быть указан
        $validator = Validator::make($request->all(), [
            'images' => 'required|array|min:1|max:5000', // Максимум 5000 изображений за раз
            'images.*.good_id' => 'nullable|integer',
            'images.*.variation_id' => 'nullable|integer',
            'images.*.file_path' => 'required|string',
            'images.*.alt_text' => 'nullable|string|max:255',
            'images.*.is_main' => 'boolean',
            'images.*.sort_order' => 'integer',
            'images.*.image_action' => 'nullable|in:add,replace,skip,unique',
        ]);

        // Дополнительная валидация: либо good_id, либо variation_id должен быть указан
        $imagesToSkip = []; // Индексы изображений, которые нужно пропустить

        $validator->after(function ($validator) use ($request, &$imagesToSkip) {
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

                // Проверяем, что good_id является положительным целым числом
                if ($hasGoodId && (!is_numeric($image['good_id']) || $image['good_id'] <= 0)) {
                    $validator->errors()->add("images.{$index}.good_id", 'good_id должен быть положительным целым числом');
                }

                // Проверяем, что variation_id является положительным целым числом
                if ($hasVariationId && (!is_numeric($image['variation_id']) || $image['variation_id'] <= 0)) {
                    $validator->errors()->add("images.{$index}.variation_id", 'variation_id должен быть положительным целым числом');
                }

                if (($image['image_action'] ?? 'add') === 'skip') {
                    if ($hasGoodId && ShopGoodImage::where('good_id', $image['good_id'])->whereNull('variation_id')->exists()) {
                        continue;
                    }

                    if ($hasVariationId && ShopGoodImage::whereNull('good_id')->where('variation_id', $image['variation_id'])->exists()) {
                        continue;
                    }
                }

                // Проверяем существование файла (ищем в директории фронтенда)
                if (!empty($image['file_path'])) {
                    // Сначала проверяем в public_path API
                    $apiPath = realpath(public_path($image['file_path'])) ?: public_path($image['file_path']);
                    // Затем проверяем в директории фронтенда (из FRONTEND_PATH в .env)
                    $frontendFullPath = realpath(frontend_public_path($image['file_path'])) ?: frontend_public_path($image['file_path']);

                    $fileExists = file_exists($apiPath) || file_exists($frontendFullPath);

                    if (!$fileExists) {
                        // Вместо $validator->errors()->add(...) — просто логируем и добавляем в skip
                        \Log::warning("Файл изображения не найден — пропускаем его", ['image' => $image]);
                        $imagesToSkip[] = $index;
                        continue;
                    }
                }

                // Проверяем существование товара или вариации
                if ($hasGoodId) {
                    if (!\DB::table('shop_goods')->where('id', $image['good_id'])->exists()) {
                        \Log::warning('Товар не найден в БД - пропускаем изображение', ['good_id' => $image['good_id'], 'image' => $image]);
                        $imagesToSkip[] = $index;
                        continue;
                    }
                }
                elseif ($hasVariationId) {
                    if (!\DB::table('shop_good_variations')->where('id', $image['variation_id'])->exists()) {
                        \Log::warning('Вариация не найдена в БД - пропускаем изображение', ['variation_id' => $image['variation_id'], 'image' => $image]);
                        $imagesToSkip[] = $index;
                        continue;
                    }
                }
            }
        });

        if ($validator->fails()) {
            // Логируем ошибки валидации для отладки
            Log::error('ShopGoodImagesController::createFromImportBatch - Ошибки валидации', [
                'errors' => $validator->errors()->toArray(),
                'first_image' => $request->input('images.0', null),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Удаляем пропущенные изображения из массива
        $images = $request->input('images', []);
        if (!empty($imagesToSkip)) {
            $filteredImages = array_filter($images, function ($image, $index) use ($imagesToSkip) {
                return !in_array($index, $imagesToSkip);
            }, ARRAY_FILTER_USE_BOTH);

            $images = array_values($filteredImages);
        }

        try {
            $results = [];
            $errors = [];
            $skipped = [];

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
                }
                else {
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
                }
                catch (\Exception $e) {
                    Log::error('Ошибка пакетной обработки изображений для товара', [
                        'good_id' => $goodId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Добавляем ошибку для всех изображений этого товара
                    foreach ($goodImages as $imageData) {
                        $errors[] = [
                            'index' => $imageData['_index'] ?? null,
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'] ?? null,
                            'error' => $e->getMessage(),
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
                }
                catch (\Exception $e) {
                    Log::error('Ошибка пакетной обработки изображений для вариации', [
                        'variation_id' => $variationId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Добавляем ошибку для всех изображений этой вариации
                    foreach ($variationImages as $imageData) {
                        $errors[] = [
                            'index' => $imageData['_index'] ?? null,
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'] ?? null,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'created' => $results,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'total' => count($images),
                    'successful' => count($results),
                    'skipped_count' => count($skipped),
                    'failed' => count($errors),
                ],
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка пакетного создания изображений: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Пакетная обработка изображений для товара (оптимизированный метод)
     */
    private function processImagesBatch(ShopGood $good, array $imagesData): array
    {
        $goodId = $good->id;
        $frontendPublicPath = frontend_public_path();
        $results = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];

        $skipIfHasImages = collect($imagesData)->contains(function ($imageData) {
            return ($imageData['image_action'] ?? 'add') === 'skip';
        });

        if ($skipIfHasImages) {
            $hasExistingImages = ShopGoodImage::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->exists();

            if ($hasExistingImages) {
                foreach ($imagesData as $imageData) {
                    $results['skipped'][] = [
                        'good_id' => $goodId,
                        'file_path' => $imageData['file_path'] ?? null,
                        'status' => 'skipped',
                        'message' => 'Изображение пропущено: у товара уже есть привязанные изображения',
                    ];
                }

                return $results;
            }
        }

        // Проверяем существование всех файлов и валидируем данные
        $validImages = [];
        foreach ($imagesData as $imageData) {
            $filePath = $imageData['file_path'] ?? '';
            $fullFilePath = $frontendPublicPath . '/' . $filePath;

            if (!file_exists($fullFilePath)) {
                $results['errors'][] = [
                    'good_id' => $goodId,
                    'file_path' => $filePath,
                    'error' => 'Файл изображения не найден: ' . $filePath,
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
                    'data' => $imageData,
                ];
            }
            else {
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
                            'message' => 'Связь товар-изображение обновлена',
                        ];
                    }
                }
                catch (\Exception $e) {
                    $results['errors'][] = [
                        'good_id' => $goodId,
                        'file_path' => $updateItem['data']['file_path'] ?? null,
                        'error' => $e->getMessage(),
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
                    'updated_at' => now(),
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
                        'message' => 'Изображение успешно создано',
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
            }
            catch (\Exception $e) {
                // Если массовая вставка не удалась, пробуем по одной
                foreach ($toCreate as $imageData) {
                    try {
                        $imageRecord = [
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'],
                            'alt_text' => $imageData['alt_text'] ?? '',
                            'is_main' => $imageData['is_main'] ?? false ? 1 : 0,
                            'sort_order' => $imageData['sort_order'] ?? 0,
                        ];

                        $goodImage = ShopGoodImage::create($imageRecord);

                        $results['created'][] = [
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'],
                            'image_id' => $goodImage->id,
                            'status' => 'created',
                            'message' => 'Изображение успешно создано',
                        ];
                    }
                    catch (\Exception $createError) {
                        $results['errors'][] = [
                            'good_id' => $goodId,
                            'file_path' => $imageData['file_path'] ?? null,
                            'error' => $createError->getMessage(),
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
        $frontendPublicPath = frontend_public_path();
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
            'existing_image_id' => $existingImage->id ?? null,
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
                'image_id' => $existingImage->id,
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
                'message' => 'Связь товар-изображение обновлена',
            ];
        }

        // Если связи нет, но файл существует - создаем связь
        Log::debug('Связь не найдена, создаем новую', [
            'good_id' => $goodId,
            'file_path' => $filePath,
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
        }
        elseif ($imageAction === 'skip') {
        // При 'skip' проверяем только конкретную связь для этого файла
        // Если связь уже существует (проверено выше), пропускаем
        // Если связи нет, но файл существует - создаем связь
        // Это позволяет привязать существующие файлы к товарам при обновлении
        }
        elseif ($imageAction === 'unique') {
            // Проверяем, есть ли уже изображение с таким же путем в базе (для любого товара)
            $existingImageGlobal = ShopGoodImage::where('file_path', $filePath)->first();
            if ($existingImageGlobal) {
                // Если изображение уже привязано к другому товару, пропускаем
                if ($existingImageGlobal->good_id != $goodId) {
                    return [
                        'good_id' => $goodId,
                        'file_path' => $filePath,
                        'status' => 'skipped',
                        'message' => 'Изображение пропущено (уже привязано к другому товару)',
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
            'sort_order' => $sortOrder,
        ];

        Log::debug('Создание записи изображения в БД', [
            'good_id' => $goodId,
            'file_path' => $filePath,
            'image_action' => $imageAction,
        ]);

        $goodImage = ShopGoodImage::create($imageRecord);

        // Логируем успешное создание
        Log::info('ShopGoodImage создано успешно', [
            'image_id' => $goodImage->id,
            'good_id' => $goodId,
            'file_path' => $filePath,
            'is_main' => $isMain,
            'sort_order' => $sortOrder,
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
            'message' => 'Изображение успешно создано',
        ];
    }

    /**
     * Пакетная обработка изображений для вариации (оптимизированный метод)
     */
    private function processVariationImagesBatch(ShopGoodVariation $variation, array $imagesData): array
    {
        $variationId = $variation->id;
        $frontendPublicPath = frontend_public_path();
        $results = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];

        $skipIfHasImages = collect($imagesData)->contains(function ($imageData) {
            return ($imageData['image_action'] ?? 'add') === 'skip';
        });

        if ($skipIfHasImages) {
            $hasExistingImages = ShopGoodImage::whereNull('good_id')
                ->where('variation_id', $variationId)
                ->exists();

            if ($hasExistingImages) {
                foreach ($imagesData as $imageData) {
                    $results['skipped'][] = [
                        'variation_id' => $variationId,
                        'file_path' => $imageData['file_path'] ?? null,
                        'status' => 'skipped',
                        'message' => 'Изображение пропущено: у вариации уже есть привязанные изображения',
                    ];
                }

                return $results;
            }
        }

        // Проверяем существование всех файлов и валидируем данные
        $validImages = [];
        foreach ($imagesData as $imageData) {
            $filePath = $imageData['file_path'] ?? '';
            $fullFilePath = $frontendPublicPath . '/' . $filePath;

            if (!file_exists($fullFilePath)) {
                $results['errors'][] = [
                    'variation_id' => $variationId,
                    'file_path' => $filePath,
                    'error' => 'Файл изображения не найден: ' . $filePath,
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
                    'data' => $imageData,
                ];
            }
            else {
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
                            'message' => 'Связь вариация-изображение обновлена',
                        ];
                    }
                }
                catch (\Exception $e) {
                    $results['errors'][] = [
                        'variation_id' => $variationId,
                        'file_path' => $updateItem['data']['file_path'] ?? null,
                        'error' => $e->getMessage(),
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
                    'updated_at' => now(),
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
                        'message' => 'Изображение вариации успешно создано',
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
            }
            catch (\Exception $e) {
                // Если массовая вставка не удалась, пробуем по одной
                foreach ($toCreate as $imageData) {
                    try {
                        $imageRecord = [
                            'good_id' => null,
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'],
                            'alt_text' => $imageData['alt_text'] ?? '',
                            'is_main' => $imageData['is_main'] ?? false ? 1 : 0,
                            'sort_order' => $imageData['sort_order'] ?? 0,
                        ];

                        $goodImage = ShopGoodImage::create($imageRecord);

                        $results['created'][] = [
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'],
                            'image_id' => $goodImage->id,
                            'status' => 'created',
                            'message' => 'Изображение вариации успешно создано',
                        ];
                    }
                    catch (\Exception $createError) {
                        $results['errors'][] = [
                            'variation_id' => $variationId,
                            'file_path' => $imageData['file_path'] ?? null,
                            'error' => $createError->getMessage(),
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
        $frontendPublicPath = frontend_public_path();
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
                'image_id' => $existingImage->id,
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
                'message' => 'Связь вариация-изображение обновлена',
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
        }
        elseif ($imageAction === 'skip') {
        // При 'skip' проверяем только конкретную связь для этого файла
        // Если связь уже существует (проверено выше), пропускаем
        // Если связи нет, но файл существует - создаем связь
        // Это позволяет привязать существующие файлы к вариациям при обновлении
        }
        elseif ($imageAction === 'unique') {
            // Проверяем, есть ли уже изображение с таким же путем в базе (для любой вариации)
            $existingImageGlobal = ShopGoodImage::where('file_path', $filePath)->first();
            if ($existingImageGlobal) {
                // Если изображение уже привязано к другой вариации, пропускаем
                if ($existingImageGlobal->variation_id != $variationId) {
                    return [
                        'variation_id' => $variationId,
                        'file_path' => $filePath,
                        'status' => 'skipped',
                        'message' => 'Изображение пропущено (уже привязано к другой вариации)',
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
            'sort_order' => $sortOrder,
        ];

        $goodImage = ShopGoodImage::create($imageRecord);

        // Логируем успешное создание
        Log::info('ShopGoodImage для вариации создано успешно', [
            'image_id' => $goodImage->id,
            'variation_id' => $variationId,
            'file_path' => $filePath,
            'is_main' => $isMain,
            'sort_order' => $sortOrder,
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
            'message' => 'Изображение вариации успешно создано',
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
            'image_action' => 'nullable|in:add,replace,skip,unique',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
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
                    'message' => 'Файл изображения не найден: ' . $filePath,
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
            }
            elseif ($imageAction === 'skip') {
                // Проверяем, есть ли уже изображения у товара
                $existingCount = ShopGoodImage::where('good_id', $goodId)->count();
                if ($existingCount > 0) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Изображения пропущены (уже существуют)',
                        'data' => null,
                    ]);
                }
            }
            elseif ($imageAction === 'unique') {
                // Проверяем, есть ли уже изображение с таким же путем в базе
                $existingImage = ShopGoodImage::where('file_path', $filePath)->first();
                if ($existingImage) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Изображение пропущено (уже существует в базе)',
                        'data' => null,
                    ]);
                }
            }

            // Создаем новое изображение
            $imageData = [
                'good_id' => $goodId,
                'file_path' => $filePath,
                'alt_text' => $altText,
                'is_main' => $isMain,
                'sort_order' => $sortOrder,
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
                'data' => $goodImage,
            ], 201);

        }
        catch (\Exception $e) {
            \Log::error('Error creating related image', [
                'goodId' => $goodId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания связанного изображения: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обработка и сохранение изображения с поддержкой белого фона
     */
    private function processAndSaveImage($image, $goodId, $uploadType = 'system_fit', $customWidth = null, $customHeight = null, $whiteBackground = true, $fitWithWhiteBackground = true)
    {

        try {
            // Прямой файл лог для гарантии записи
            file_put_contents('F:/Work/Projects/SS/api.ss.ru/storage/logs/laravel.log', '[' . date('Y-m-d H:i:s') . "] PROCESS_START: goodId=$goodId\n", FILE_APPEND);

            // Создаем уникальное имя файла
            $extension = $image->getClientOriginalExtension();
            $filename = uniqid() . '.' . $extension;
            $path = "images/shop/goods/{$goodId}/{$filename}";

            Log::info('DEBUG: processAndSaveImage START', [
                'goodId' => $goodId,
                'uploadType' => $uploadType,
                'extension' => $extension,
                'filename' => $filename,
                'whiteBackground' => $whiteBackground,
                'fitWithWhiteBackground' => $fitWithWhiteBackground,
                'customWidth' => $customWidth,
                'customHeight' => $customHeight,
            ]);

            // Путь к папке public фронтенда
            $frontendPublicPath = frontend_public_path();
            $fullPath = $frontendPublicPath . '/' . $path;
            $dir = dirname($fullPath);

            // Создаем директорию, если её нет
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Всегда обрабатываем изображения с белым фоном для PNG/GIF/WebP
            $extension = strtolower($extension);
            $mimeType = $image->getMimeType();
            $isTransparentFormat = in_array($extension, ['png', 'gif', 'webp']) ||
                strpos($mimeType, 'png') !== false ||
                strpos($mimeType, 'gif') !== false ||
                strpos($mimeType, 'webp') !== false;

            // Дополнительная проверка на PNG по MIME типу
            $mimeType = $image->getMimeType();
            $isPngByMime = strpos($mimeType, 'png') !== false;

            file_put_contents('F:/Work/Projects/SS/api.ss.ru/storage/logs/laravel.log', '[' . date('Y-m-d H:i:s') . "] PROCESSING_START: extension=$extension, mime=$mimeType, isTransparent=$isTransparentFormat\n", FILE_APPEND);

            Log::info('DEBUG: Processing image START', [
                'extension' => $extension,
                'mime_type' => $mimeType,
                'isTransparentFormat' => $isTransparentFormat,
                'isPngByMime' => $isPngByMime,
                'whiteBackground' => $whiteBackground,
                'fitWithWhiteBackground' => $fitWithWhiteBackground,
                'uploadType' => $uploadType,
                'goodId' => $goodId,
                'filename' => $filename,
            ]);

            // Для PNG всегда устанавливаем флаг прозрачности независимо от расширения
            if ($isPngByMime || $extension === 'png') {
                $isTransparentFormat = true;
            }

            // ВСЕГДА обрабатываем PNG/GIF/WebP через Intervention Image для применения белого фона
            // Для других форматов обрабатываем только если требуется изменение размера
            Log::info('DEBUG: Checking processing conditions', [
                'isTransparentFormat' => $isTransparentFormat,
                'uploadType' => $uploadType,
                'whiteBackground' => $whiteBackground,
                'fitWithWhiteBackground' => $fitWithWhiteBackground,
                'will_process' => $isTransparentFormat || ($uploadType !== 'original' && ($whiteBackground || $fitWithWhiteBackground)),
            ]);

            if ($isTransparentFormat) {
                // ПРОСТОЙ ЛОГ ПЕРЕД ОБРАБОТКОЙ PNG
                file_put_contents('F:/Work/Projects/SS/api.ss.ru/process_debug.log', '[' . date('Y-m-d H:i:s') . "] PNG_PROCESSING: Starting PNG processing for goodId=$goodId\n", FILE_APPEND);

                $manager = new ImageManager(new Driver);
                $processedImage = $manager->read($image);

                // Определяем размеры
                $width = null;
                $height = null;

                if ($uploadType === 'custom_fit' && $customWidth && $customHeight) {
                    $width = $customWidth;
                    $height = $customHeight;
                }
                elseif ($uploadType === 'system_fit' || $uploadType === 'system_crop') {
                    // Получаем системные размеры (можно из настроек или использовать дефолтные)
                    $width = 500;
                    $height = 500;
                }
                elseif ($uploadType === 'original' && $isTransparentFormat) {
                    // Для original используем оригинальные размеры изображения
                    $width = $processedImage->width();
                    $height = $processedImage->height();
                }

                // Для PNG/GIF/WebP с прозрачностью ВСЕГДА применяем белый фон
                if ($isTransparentFormat) {
                    Log::info('DEBUG: Processing PNG/GIF/WEBP image', [
                        'original_size' => $processedImage->width() . 'x' . $processedImage->height(),
                        'target_size' => $width . 'x' . $height,
                        'uploadType' => $uploadType,
                    ]);
                    // Если размеры не определены (original без изменения размера), используем оригинальные
                    if (!$width || !$height) {
                        $width = $processedImage->width();
                        $height = $processedImage->height();
                    }

                    // Создаем новое изображение с белым фоном
                    $canvas = $manager->create($width, $height);
                    $canvas->fill('ffffff'); // Белый фон

                    // ПРОСТОЙ ЛОГ ПОСЛЕ СОЗДАНИЯ БЕЛОГО ФОНА
                    file_put_contents('F:/Work/Projects/SS/api.ss.ru/process_debug.log', '[' . date('Y-m-d H:i:s') . "] WHITE_BACKGROUND_CREATED: Created canvas with white background {$width}x{$height}\n", FILE_APPEND);

                    // Если нужно изменить размер, вписываем изображение
                    if ($uploadType !== 'original' && $fitWithWhiteBackground && $width && $height) {
                        $processedImage->contain($width, $height);
                    }

                    // Накладываем изображение на белый фон с центрированием
                    $canvas->place($processedImage, 'center');
                    $processedImage = $canvas;

                    // ВСЕГДА конвертируем в JPG для удаления прозрачности
                    $imageData = $processedImage->toJpeg(90);
                    $fullPath = str_replace('.' . $extension, '.jpg', $fullPath);
                    $path = str_replace('.' . $extension, '.jpg', $path);

                    // Сохраняем обработанное изображение
                    file_put_contents($fullPath, $imageData);

                    Log::info('DEBUG: PNG processing completed successfully', [
                        'final_path' => $path,
                        'file_size' => strlen($imageData) . ' bytes',
                    ]);

                    return ['path' => $path];
                }
                elseif ($uploadType !== 'original' && $fitWithWhiteBackground) {
                    // Вписываем изображение в размеры с белым фоном
                    $processedImage->contain($width, $height);

                    // Создаем новое изображение с белым фоном
                    $canvas = $manager->create($width, $height);
                    $canvas->fill('ffffff'); // Белый фон

                    // Накладываем вписанное изображение на белый фон с центрированием
                    $canvas->place($processedImage, 'center');
                    $processedImage = $canvas;
                }
                elseif ($uploadType === 'system_crop') {
                    // Обрезка с сохранением пропорций
                    $processedImage->cover($width, $height);
                }

                // Сохраняем в оригинальном формате (для не-прозрачных форматов)
                // PNG/GIF/WebP уже обработаны выше и сохранены
                if (strtolower($extension) === 'jpg' || strtolower($extension) === 'jpeg') {
                    $imageData = $processedImage->toJpeg(90);
                }
                elseif (strtolower($extension) === 'webp') {
                    $imageData = $processedImage->toWebp(90);
                }
                else {
                    $imageData = $processedImage->toJpeg(90);
                }

                // Сохраняем обработанное изображение
                file_put_contents($fullPath, $imageData);
            }
            else {
                // Для PNG/GIF/WebP даже при original нужно обработать для белого фона
                if ($isTransparentFormat) {
                    $manager = new ImageManager(new Driver);
                    $processedImage = $manager->read($image);

                    $width = $processedImage->width();
                    $height = $processedImage->height();

                    // Создаем новое изображение с белым фоном
                    $canvas = $manager->create($width, $height);
                    $canvas->fill('ffffff'); // Белый фон

                    // Накладываем изображение на белый фон с центрированием
                    $canvas->place($processedImage, 'center');

                    // Конвертируем в JPG для удаления прозрачности
                    $imageData = $canvas->toJpeg(90);
                    $fullPath = str_replace('.' . $extension, '.jpg', $fullPath);
                    $path = str_replace('.' . $extension, '.jpg', $path);

                    // Сохраняем обработанное изображение
                    file_put_contents($fullPath, $imageData);

                    Log::info('PNG processed with white background', [
                        'original_path' => $path,
                        'new_path' => $path,
                        'width' => $width,
                        'height' => $height,
                    ]);
                }
                else {
                    // Сохраняем файл без обработки (только для не-прозрачных форматов)
                    $image->move($dir, $filename);
                }
            }

            return ['path' => $path];

        }
        catch (\Exception $e) {
            Log::error('Error processing image: ' . $e->getMessage());

            return null;
        }
    }
}
