<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopCategory;
use App\Models\ShopBrand;
use App\Models\ShopGoodImage;
use App\Services\ImportLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BulkGoodsImportController extends Controller
{
    private $importLogService;
    
    public function __construct(ImportLogService $importLogService)
    {
        $this->importLogService = $importLogService;
    }
    
    public function bulkImport(Request $request)
    {
        // Получаем информацию о батче для очистки логов
        $isFirstBatch = $request->input('is_first_batch', false);
        
        // Очищаем логи только для первого батча
        if ($isFirstBatch) {
            $this->importLogService->clearAllLogs();
        }
        
        // Получаем данные товаров
        $allGoods = $request->input('goods', []);
        
        // Фильтруем пустые строки - оставляем только товары с заполненными SKU и названием
        $goods = [];
        $skippedRows = [];
        
        foreach ($allGoods as $index => $good) {
            $sku = isset($good['sku']) ? trim((string) $good['sku']) : '';
            $name = isset($good['name']) ? trim((string) $good['name']) : '';
            
            // Создаем уникальный идентификатор для товара
            $itemId = 'item_' . $index . '_' . substr(md5($sku . $name), 0, 8);
            
            // Пропускаем только полностью пустые строки (где И SKU И название пустые)
            if (empty($sku) && empty($name)) {
                $reason = 'Пустая строка';
                
                // Логируем пропущенную строку с уникальным ID
                \Log::info("Пропущенная строка {$itemId}: SKU='{$sku}', Name='{$name}', Reason={$reason}");
                
                $skippedRows[] = [
                    'count' => $index + 1,
                    'sku' => $sku,
                    'name' => $name,
                    'reason' => $reason
                ];
                
                continue;
            }
            
            // Если отсутствует SKU или название, но не оба - это ошибка валидации
            if (empty($sku) || empty($name)) {
                $reason = 'Отсутствует ' . (empty($sku) ? 'SKU' : 'название');
                
                // Логируем ошибку валидации с уникальным ID
                \Log::info("Ошибка валидации {$itemId}: SKU='{$sku}', Name='{$name}', Reason={$reason}");
                
                $skippedRows[] = [
                    'count' => $index + 1,
                    'sku' => $sku,
                    'name' => $name,
                    'reason' => $reason
                ];
                continue;
            }
            
            // Нормализуем данные для непустых товаров
            $good['sku'] = $sku;
            $good['name'] = $name;
            $goods[] = $good;
            
            // Логируем валидный товар с уникальным ID
            \Log::info("Валидный товар {$itemId}: SKU='{$sku}', Name='{$name}'");
        }
        
        // Логируем пропущенные строки
        if (!empty($skippedRows)) {
            Log::info("BulkImport Debug - Логируем skippedRows:", [
                'count' => count($skippedRows),
                'first_skipped' => $skippedRows[0] ?? null
            ]);
            $this->importLogService->logSkippedBatch($skippedRows);
        } else {
            Log::info("BulkImport Debug - Нет skippedRows");
        }
        
        // Валидируем только непустые товары
        $validator = Validator::make([
            'goods' => $goods,
            'duplicate_action' => $request->input('duplicate_action'),
            'auto_create_categories' => $request->input('auto_create_categories'),
            'auto_create_brands' => $request->input('auto_create_brands'),
            'process_categories_and_brands' => $request->input('process_categories_and_brands'),
        ], [
            'goods' => 'required|array',
            'goods.*.sku' => 'required|string|min:1',
            'goods.*.name' => 'required|string|min:1',
            'duplicate_action' => 'required|in:skip,update',
            'auto_create_categories' => 'boolean',
            'auto_create_brands' => 'boolean',
            'process_categories_and_brands' => 'boolean',
        ]);

        // Проверяем, есть ли товары для обработки после фильтрации
        if (empty($goods)) {
            $skippedCount = count($skippedRows);
            return response()->json([
                'success' => true,
                'message' => "Нет товаров для импорта (все {$skippedCount} строк пустые или неполные)",
                'results' => [
                    'imported' => 0,
                    'updated' => 0,
                    'skipped' => $skippedCount,
                    'failed' => 0,
                    'errors' => [],
                    'goodIds' => [],
                    'newCategories' => [],
                    'newBrands' => []
                ]
            ]);
        }

        if ($validator->fails()) {
            // Логируем ошибки валидации с детальной информацией
            $errors = $validator->errors();
            $errorMessages = [];
            foreach ($errors->all() as $error) {
                $errorMessages[] = $error;
            }
            
            // Логируем первые несколько товаров для диагностики
            $firstGoods = array_slice($goods, 0, 3);
            \Log::info('Validation failed - sample goods data:', [
                'first_goods' => $firstGoods,
                'validation_errors' => $errors->toArray()
            ]);
            
            $this->importLogService->logGeneralError('Ошибка валидации: ' . implode('; ', $errorMessages));
            
            // Логируем ошибки валидации файлов отдельно
            foreach ($errorMessages as $error) {
                if (strpos($error, 'goods') !== false || strpos($error, 'file') !== false || strpos($error, 'required') !== false) {
                    $this->importLogService->logFileLoadingError($error);
                }
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $duplicateAction = $request->input('duplicate_action', 'skip');
        $autoCreateCategories = $request->input('auto_create_categories', false);
        $autoCreateBrands = $request->input('auto_create_brands', false);
        $processCategoriesAndBrands = $request->input('process_categories_and_brands', false);
        
        // Получаем информацию о батче
        $batchNumber = $request->input('batch_number', 1);
        $totalBatches = $request->input('total_batches', 1);
        

        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => count($skippedRows), // Включаем пропущенные строки
            'failed' => 0,
            'errors' => [],
            'goodIds' => [],
            'newCategories' => [],
            'newBrands' => []
        ];

        // Пакетная обработка категорий и брендов
        if ($processCategoriesAndBrands) {
            \Log::info('Starting batch processing', ['goods_count' => count($goods)]);
            $this->processCategoriesAndBrandsBatch($goods, $autoCreateCategories, $autoCreateBrands);
            \Log::info('Batch processing completed', ['first_good' => $goods[0] ?? null]);
            
            // Собираем информацию о новых категориях и брендах
            $this->collectNewCategoriesAndBrands($goods, $results);
        }

        DB::beginTransaction();

        try {
            // Группируем товары для пакетного логирования
            $loadItems = [];
            $updateItems = [];
            $skipItems = [];
            $errorItems = [];
            
            foreach ($goods as $index => $goodData) {
                $count = $index + 1;
                $sku = $goodData['sku'] ?? 'неизвестно';
                $name = $goodData['name'] ?? 'неизвестно';
                
                try {
                    // Пустые строки уже отфильтрованы на этапе валидации
                    // Здесь обрабатываем только валидные товары
                    
                    $existingGood = ShopGood::where('sku', $sku)->first();

                    if ($existingGood) {
                        // Товар существует
                        if ($duplicateAction === 'update') {
                            $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands);
                            $results['updated']++;
                            $results['goodIds'][$sku] = $existingGood->id;
                            
                            // Добавляем в группу для обновления
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet];
                        } else {
                            $results['skipped']++;
                            
                            // Добавляем в группу для пропуска
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => 'Дубликат (настройка: пропустить)'];
                        }
                    } else {
                        // Создаем новый товар
                        $newGood = $this->createGood($goodData, $autoCreateCategories, $autoCreateBrands);
                        $results['imported']++;
                        $results['goodIds'][$sku] = $newGood->id;
                        
                        // Добавляем в группу для загрузки
                        $sheet = $goodData['_sheet'] ?? 'неизвестно';
                        $loadItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet];
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $count,
                        'sku' => $sku,
                        'error' => $e->getMessage()
                    ];
                    
                    // Добавляем в группу для ошибок
                    $sheet = $goodData['_sheet'] ?? 'неизвестно';
                    $errorItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'error' => $e->getMessage()];
                }
            }

            DB::commit();
            
            // Пакетное логирование после успешного коммита
            
            if (!empty($loadItems)) {
                $this->importLogService->logLoadedBatch($loadItems);
            }
            if (!empty($updateItems)) {
                $this->importLogService->logUpdatedBatch($updateItems);
            }
            if (!empty($skipItems)) {
                $this->importLogService->logSkippedBatch($skipItems);
            }
            if (!empty($errorItems)) {
                $this->importLogService->logErrorBatch($errorItems);
            }

            return response()->json([
                'success' => true,
                'message' => 'Импорт завершен',
                'results' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Логируем общую ошибку
            $this->importLogService->logGeneralError('Общая ошибка импорта: ' . $e->getMessage());
            
            // Логируем ошибки загрузки файлов отдельно
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'file') !== false || 
                strpos($errorMessage, 'upload') !== false || 
                strpos($errorMessage, 'parse') !== false ||
                strpos($errorMessage, 'read') !== false) {
                $this->importLogService->logFileLoadingError($errorMessage);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при импорте: ' . $e->getMessage(),
                'results' => $results
            ], 500);
        }
    }
    

    private function createGood($goodData, $autoCreateCategories, $autoCreateBrands)
    {
        $good = new ShopGood();
        $good->sku = $goodData['sku'];
        $good->name = $goodData['name'];
        $good->slug = $this->generateSlug($goodData['name'], $goodData['sku']);
        $good->description = $goodData['description'] ?? null;
        
        // Применяем модификацию цены
        $priceModification = $goodData['price_modification'] ?? null;
        $good->price = $this->applyPriceModification($goodData['price'] ?? 0, $priceModification['regular'] ?? null);
        $good->sale_price = $this->applySalePriceModification($goodData, $priceModification);
        $good->stock_quantity = $goodData['stock'] ?? 0;
        $good->weight = $goodData['weight'] ?? 0;
        $good->width = $goodData['width'] ?? 0;
        $good->height = $goodData['height'] ?? 0;
        $good->depth = $goodData['length'] ?? 0;
        $good->is_active = $goodData['is_active'] ?? true;
        $good->is_featured = $goodData['is_featured'] ?? false;
        $good->meta_title = $goodData['meta_title'] ?? null;
        $good->meta_description = $goodData['meta_description'] ?? null;
        $good->save();

        // Обрабатываем категории
        if (isset($goodData['category']) && is_numeric($goodData['category'])) {
            // Одиночная категория по ID
            \Log::info('Syncing single category', ['good_id' => $good->id, 'category_id' => $goodData['category']]);
            $good->categories()->sync([$goodData['category']]);
        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
            // Множественные категории - ID уже применены в applyCategoryAndBrandIds
            $categoryIds = array_filter($goodData['categories'], 'is_numeric');
            \Log::info('Syncing multiple categories', ['good_id' => $good->id, 'category_ids' => $categoryIds]);
            $good->categories()->sync($categoryIds);
        }

        // Обрабатываем бренды
        if (isset($goodData['brand']) && is_numeric($goodData['brand'])) {
            // Одиночный бренд по ID
            \Log::info('Syncing single brand', ['good_id' => $good->id, 'brand_id' => $goodData['brand']]);
            $good->brands()->sync([$goodData['brand']]);
        } elseif (isset($goodData['brands']) && is_array($goodData['brands'])) {
            // Множественные бренды - ID уже применены в applyCategoryAndBrandIds
            $brandIds = array_filter($goodData['brands'], 'is_numeric');
            \Log::info('Syncing multiple brands', ['good_id' => $good->id, 'brand_ids' => $brandIds]);
            $good->brands()->sync($brandIds);
        }

        // Обрабатываем изображения
        if (isset($goodData['images']) && is_array($goodData['images'])) {
            $this->processImages($good, $goodData['images']);
        }

        // Обрабатываем свойства товаров
        if (isset($goodData['properties']) && is_array($goodData['properties'])) {
            $this->processProperties($good, $goodData['properties']);
        }

        return $good;
    }

    private function updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands)
    {
        $existingGood->name = $goodData['name'];
        $existingGood->slug = $this->generateSlug($goodData['name'], $goodData['sku']);
        $existingGood->description = $goodData['description'] ?? $existingGood->description;
        
        // Применяем модификацию цены
        $priceModification = $goodData['price_modification'] ?? null;
        $existingGood->price = $this->applyPriceModification($goodData['price'] ?? $existingGood->price, $priceModification['regular'] ?? null);
        $existingGood->sale_price = $this->applySalePriceModification($goodData, $priceModification) ?? $existingGood->sale_price;
        $existingGood->stock_quantity = $goodData['stock'] ?? $existingGood->stock_quantity;
        $existingGood->weight = $goodData['weight'] ?? $existingGood->weight;
        $existingGood->width = $goodData['width'] ?? $existingGood->width;
        $existingGood->height = $goodData['height'] ?? $existingGood->height;
        $existingGood->depth = $goodData['length'] ?? $existingGood->depth;
        $existingGood->is_active = $goodData['is_active'] ?? $existingGood->is_active;
        $existingGood->is_featured = $goodData['is_featured'] ?? $existingGood->is_featured;
        $existingGood->meta_title = $goodData['meta_title'] ?? $existingGood->meta_title;
        $existingGood->meta_description = $goodData['meta_description'] ?? $existingGood->meta_description;
        $existingGood->save();

        // Обрабатываем категории
        if (isset($goodData['category']) && is_numeric($goodData['category'])) {
            // Одиночная категория по ID
            \Log::info('Updating single category', ['good_id' => $existingGood->id, 'category_id' => $goodData['category']]);
            $existingGood->categories()->sync([$goodData['category']]);
        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
            // Множественные категории - ID уже применены в applyCategoryAndBrandIds
            $categoryIds = array_filter($goodData['categories'], 'is_numeric');
            \Log::info('Updating multiple categories', ['good_id' => $existingGood->id, 'category_ids' => $categoryIds]);
            $existingGood->categories()->sync($categoryIds);
        }

        // Обрабатываем бренды
        if (isset($goodData['brand']) && is_numeric($goodData['brand'])) {
            // Одиночный бренд по ID
            \Log::info('Updating single brand', ['good_id' => $existingGood->id, 'brand_id' => $goodData['brand']]);
            $existingGood->brands()->sync([$goodData['brand']]);
        } elseif (isset($goodData['brands']) && is_array($goodData['brands'])) {
            // Множественные бренды - ID уже применены в applyCategoryAndBrandIds
            $brandIds = array_filter($goodData['brands'], 'is_numeric');
            \Log::info('Updating multiple brands', ['good_id' => $existingGood->id, 'brand_ids' => $brandIds]);
            $existingGood->brands()->sync($brandIds);
        }

        // Обрабатываем изображения
        if (isset($goodData['images']) && is_array($goodData['images'])) {
            $this->processImages($existingGood, $goodData['images']);
        }

        // Обрабатываем свойства товаров
        if (isset($goodData['properties']) && is_array($goodData['properties'])) {
            $this->processProperties($existingGood, $goodData['properties']);
        }

        return $existingGood;
    }

    private function processCategories($categories, $autoCreate)
    {
        $categoryIds = [];

        foreach ($categories as $category) {
            if (is_numeric($category)) {
                // Это ID категории
                $categoryIds[] = (int)$category;
            } else {
                // Это название категории
                $existingCategory = ShopCategory::where('name', $category)->first();
                
                if ($existingCategory) {
                    $categoryIds[] = $existingCategory->id;
                } elseif ($autoCreate) {
                    $newCategory = ShopCategory::create([
                        'name' => $category,
                        'slug' => Str::slug($category),
                        'is_active' => true
                    ]);
                    $categoryIds[] = $newCategory->id;
                }
            }
        }

        return array_unique($categoryIds);
    }

    private function processBrands($brands, $autoCreate)
    {
        $brandIds = [];

        \Log::info('Processing brands', ['brands' => $brands, 'autoCreate' => $autoCreate]);

        foreach ($brands as $brand) {
            if (is_numeric($brand)) {
                // Это ID бренда
                $brandIds[] = (int)$brand;
                \Log::info('Added numeric brand ID', ['brand_id' => $brand]);
            } else {
                // Это название бренда
                $brandSlug = Str::slug($brand);
                $existingBrand = ShopBrand::where('name', $brand)
                    ->orWhere('slug', $brandSlug)
                    ->first();
                
                if ($existingBrand) {
                    $brandIds[] = $existingBrand->id;
                    \Log::info('Found existing brand', ['brand_name' => $brand, 'brand_id' => $existingBrand->id]);
                } elseif ($autoCreate) {
                    // Проверяем, не существует ли уже бренд с таким slug
                    $existingBrandBySlug = ShopBrand::where('slug', $brandSlug)->first();
                    if ($existingBrandBySlug) {
                        $brandIds[] = $existingBrandBySlug->id;
                        \Log::info('Found existing brand by slug', ['brand_name' => $brand, 'slug' => $brandSlug, 'brand_id' => $existingBrandBySlug->id]);
                    } else {
                        $newBrand = ShopBrand::create([
                            'name' => $brand,
                            'slug' => $brandSlug,
                            'is_active' => true
                        ]);
                        $brandIds[] = $newBrand->id;
                        \Log::info('Created new brand', ['brand_name' => $brand, 'brand_id' => $newBrand->id]);
                    }
                } else {
                    \Log::warning('Brand not found and autoCreate disabled', ['brand_name' => $brand]);
                }
            }
        }

        \Log::info('Final brand IDs', ['brand_ids' => $brandIds]);
        return array_unique($brandIds);
    }

    private function processImages($good, $images)
    {
        foreach ($images as $imageUrl) {
            if (!empty($imageUrl)) {
                // Если это уже локальный путь (начинается с /), сохраняем как есть
                if (str_starts_with($imageUrl, '/')) {
                    ShopGoodImage::create([
                        'good_id' => $good->id,
                        'image_url' => $imageUrl,
                        'is_primary' => false
                    ]);
                } else {
                    // Если это внешний URL, скачиваем изображение
                    try {
                        $downloadResponse = $this->downloadImage($imageUrl);
                        if ($downloadResponse && isset($downloadResponse['data']['path'])) {
                            ShopGoodImage::create([
                                'good_id' => $good->id,
                                'image_url' => $downloadResponse['data']['path'],
                                'is_primary' => false
                            ]);
                        } else {
                            // Если не удалось скачать, сохраняем оригинальный URL
                            ShopGoodImage::create([
                                'good_id' => $good->id,
                                'image_url' => $imageUrl,
                                'is_primary' => false
                            ]);
                        }
                    } catch (\Exception $e) {
                        // В случае ошибки сохраняем оригинальный URL
                        ShopGoodImage::create([
                            'good_id' => $good->id,
                            'image_url' => $imageUrl,
                            'is_primary' => false
                        ]);
                    }
                }
            }
        }
    }

    private function downloadImage($imageUrl)
    {
        // Используем тот же метод, что и в ShopGoodsController
        $response = Http::timeout(30)->post(url('/api/admin/shop/goods/download-image'), [
            'imageUrl' => $imageUrl,
            'storagePath' => '/shop/goods',
            'optimize' => true,
            'naming' => 'hash',
            'resize' => 'no_change',
            'width' => null,
            'height' => null,
        ], [
            'Authorization' => 'Bearer ' . request()->bearerToken(),
            'Content-Type' => 'application/json',
        ]);

        return $response->json();
    }

    private function generateSlug($name, $sku)
    {
        $baseSlug = Str::slug($name);
        $counter = 1;
        $slug = $baseSlug;

        while (ShopGood::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Пакетная обработка категорий и брендов
     */
    private function processCategoriesAndBrandsBatch(&$goods, $autoCreateCategories, $autoCreateBrands)
    {
        // Собираем все уникальные названия категорий и брендов
        $allCategories = collect();
        $allBrands = collect();

        foreach ($goods as $good) {
            // Собираем категории
            if (isset($good['category']) && is_string($good['category']) && !empty($good['category'])) {
                $allCategories->push($good['category']);
            } elseif (isset($good['categories'])) {
                if (is_string($good['categories'])) {
                    $categoryNames = $this->parseDelimitedString($good['categories']);
                    $allCategories = $allCategories->merge($categoryNames);
                } elseif (is_array($good['categories'])) {
                    $allCategories = $allCategories->merge($good['categories']);
                }
            }

            // Собираем бренды
            if (isset($good['brand']) && is_string($good['brand']) && !empty($good['brand'])) {
                $allBrands->push($good['brand']);
            } elseif (isset($good['brands'])) {
                if (is_string($good['brands'])) {
                    $brandNames = $this->parseDelimitedString($good['brands']);
                    $allBrands = $allBrands->merge($brandNames);
                } elseif (is_array($good['brands'])) {
                    $allBrands = $allBrands->merge($good['brands']);
                }
            }
        }

        $allCategories = $allCategories->unique()->filter();
        $allBrands = $allBrands->unique()->filter();

        // Обрабатываем категории пакетно
        if ($allCategories->isNotEmpty()) {
            $this->processCategoriesBatch($allCategories, $autoCreateCategories);
        }

        // Обрабатываем бренды пакетно
        if ($allBrands->isNotEmpty()) {
            $this->processBrandsBatch($allBrands, $autoCreateBrands);
        }

        // Применяем найденные ID к товарам
        $this->applyCategoryAndBrandIds($goods, $autoCreateCategories, $autoCreateBrands);
    }

    /**
     * Пакетная обработка категорий
     */
    private function processCategoriesBatch($allCategories, $autoCreate)
    {
        // Загружаем существующие категории
        $existingCategories = ShopCategory::whereIn('name', $allCategories->toArray())
            ->get()
            ->keyBy(function($item) {
                return strtolower($item->name);
            });

        $categoryMap = $existingCategories->mapWithKeys(function($item) {
            return [strtolower($item->name) => $item->id];
        });

        // Создаем недостающие категории
        $createdCategories = [];
        if ($autoCreate) {
            $categoriesToCreate = $allCategories->filter(function($name) use ($categoryMap) {
                return !$categoryMap->has(strtolower($name));
            });

            foreach ($categoriesToCreate as $categoryName) {
                $category = ShopCategory::create([
                    'name' => $categoryName,
                    'slug' => Str::slug($categoryName),
                    'is_active' => true
                ]);

                $categoryMap[strtolower($categoryName)] = $category->id;
                $createdCategories[] = $category->id;
            }
        }

        // Сохраняем информацию о созданных категориях в кэше
        cache(['created_categories_' . auth()->id() => $createdCategories], 300);

        // Сохраняем карту в кэше для использования в applyCategoryAndBrandIds
        cache(['category_map_' . auth()->id() => $categoryMap], 300); // 5 минут
    }

    /**
     * Пакетная обработка брендов
     */
    private function processBrandsBatch($allBrands, $autoCreate)
    {
        // Загружаем существующие бренды по имени и slug
        $existingBrands = ShopBrand::where(function($query) use ($allBrands) {
            $query->whereIn('name', $allBrands->toArray())
                  ->orWhereIn('slug', $allBrands->map(function($name) {
                      return Str::slug($name);
                  })->toArray());
        })->get();

        $brandMap = collect();
        
        // Создаем карту по имени и slug
        foreach ($existingBrands as $brand) {
            $brandMap[strtolower($brand->name)] = $brand->id;
            $brandMap[strtolower($brand->slug)] = $brand->id;
        }

        // Создаем недостающие бренды
        $createdBrands = [];
        if ($autoCreate) {
            $brandsToCreate = $allBrands->filter(function($name) use ($brandMap) {
                $nameLower = strtolower($name);
                $slugLower = strtolower(Str::slug($name));
                return !$brandMap->has($nameLower) && !$brandMap->has($slugLower);
            });

            \Log::info('Creating brands batch', ['brands_to_create' => $brandsToCreate->toArray()]);

            foreach ($brandsToCreate as $brandName) {
                $brandSlug = Str::slug($brandName);
                
                // Дополнительная проверка на случай, если slug уже существует
                $existingBrandBySlug = ShopBrand::where('slug', $brandSlug)->first();
                if ($existingBrandBySlug) {
                    $brandMap[strtolower($brandName)] = $existingBrandBySlug->id;
                    \Log::info('Found existing brand by slug during batch', ['brand_name' => $brandName, 'slug' => $brandSlug, 'brand_id' => $existingBrandBySlug->id]);
                    continue;
                }

                $brand = ShopBrand::create([
                    'name' => $brandName,
                    'slug' => $brandSlug,
                    'is_active' => true
                ]);

                $brandMap[strtolower($brandName)] = $brand->id;
                $brandMap[strtolower($brandSlug)] = $brand->id;
                $createdBrands[] = $brand->id;
                \Log::info('Created brand', ['brand_name' => $brandName, 'brand_id' => $brand->id]);
            }
        }

        // Сохраняем информацию о созданных брендах в кэше
        cache(['created_brands_' . auth()->id() => $createdBrands], 300);

        \Log::info('Final brand map', ['brand_map' => $brandMap->toArray()]);

        // Сохраняем карту в кэше для использования в applyCategoryAndBrandIds
        cache(['brand_map_' . auth()->id() => $brandMap], 300); // 5 минут
    }

    /**
     * Применение найденных ID к товарам
     */
    private function applyCategoryAndBrandIds(&$goods, $autoCreateCategories = true, $autoCreateBrands = true)
    {
        $categoryMap = cache('category_map_' . auth()->id(), collect());
        $brandMap = cache('brand_map_' . auth()->id(), collect());
        
        \Log::info('Applying category and brand IDs', [
            'category_map_size' => $categoryMap->count(),
            'brand_map_size' => $brandMap->count(),
            'goods_count' => count($goods),
            'autoCreateCategories' => $autoCreateCategories,
            'autoCreateBrands' => $autoCreateBrands
        ]);

        foreach ($goods as &$good) {
            // Обрабатываем категории
            if (isset($good['category']) && is_string($good['category']) && !empty($good['category'])) {
                $categoryId = $categoryMap[strtolower($good['category'])] ?? null;
                if ($categoryId) {
                    $good['category'] = $categoryId;
                    \Log::info('Applied single category ID', ['category_name' => $good['category'], 'category_id' => $categoryId]);
                } else {
                    \Log::warning('Category not found in map', ['category_name' => $good['category']]);
                    if (!$autoCreateCategories) {
                        unset($good['category']);
                    }
                }
            } elseif (isset($good['categories'])) {
                if (is_string($good['categories'])) {
                    $categoryNames = $this->parseDelimitedString($good['categories']);
                    $categoryIds = [];

                    foreach ($categoryNames as $categoryName) {
                        $categoryId = $categoryMap[strtolower($categoryName)] ?? null;
                        if ($categoryId) {
                            $categoryIds[] = $categoryId;
                        } else {
                            \Log::warning('Category not found in map', ['category_name' => $categoryName]);
                        }
                    }

                    $good['categories'] = $categoryIds;
                } elseif (is_array($good['categories'])) {
                    $categoryIds = [];

                    foreach ($good['categories'] as $category) {
                        if (is_string($category)) {
                            $categoryId = $categoryMap[strtolower($category)] ?? null;
                            if ($categoryId) {
                                $categoryIds[] = $categoryId;
                            } else {
                                \Log::warning('Category not found in map', ['category_name' => $category]);
                            }
                        } elseif (is_numeric($category)) {
                            $categoryIds[] = (int)$category;
                        }
                    }

                    $good['categories'] = $categoryIds;
                }
            }

            // Обрабатываем бренды
            if (isset($good['brand']) && is_string($good['brand']) && !empty($good['brand'])) {
                $brandId = $brandMap[strtolower($good['brand'])] ?? null;
                if ($brandId) {
                    $good['brand'] = $brandId;
                    \Log::info('Applied single brand ID', ['brand_name' => $good['brand'], 'brand_id' => $brandId]);
                } else {
                    \Log::warning('Brand not found in map', ['brand_name' => $good['brand']]);
                    if (!$autoCreateBrands) {
                        unset($good['brand']);
                    }
                }
            } elseif (isset($good['brands'])) {
                if (is_string($good['brands'])) {
                    $brandNames = $this->parseDelimitedString($good['brands']);
                    $brandIds = [];

                    foreach ($brandNames as $brandName) {
                        $brandId = $brandMap[strtolower($brandName)] ?? null;
                        if ($brandId) {
                            $brandIds[] = $brandId;
                        } else {
                            \Log::warning('Brand not found in map', ['brand_name' => $brandName]);
                        }
                    }

                    $good['brands'] = $brandIds;
                } elseif (is_array($good['brands'])) {
                    $brandIds = [];

                    foreach ($good['brands'] as $brand) {
                        if (is_string($brand) && !empty($brand)) {
                            $brandId = $brandMap[strtolower($brand)] ?? null;
                            if ($brandId) {
                                $brandIds[] = $brandId;
                            } else {
                                \Log::warning('Brand not found in map', ['brand_name' => $brand]);
                            }
                        } elseif (is_numeric($brand)) {
                            $brandIds[] = (int)$brand;
                        }
                    }

                    $good['brands'] = $brandIds;
                }
            }
        }
        
        \Log::info('Finished applying category and brand IDs', [
            'first_good_after' => $goods[0] ?? null
        ]);
    }

    /**
     * Парсинг строк с разделителями
     */
    private function parseDelimitedString($str)
    {
        if (empty($str)) {
            return [];
        }

        // Поддерживаем различные разделители: запятая, точка с запятой, вертикальная черта, перенос строки
        return collect(preg_split('/[,;|\n\r]+/', $str))
            ->map(function($item) {
                return trim($item);
            })
            ->filter()
            ->toArray();
    }

    /**
     * Собирает информацию о новых категориях и брендах
     */
    private function collectNewCategoriesAndBrands($goods, &$results)
    {
        $newCategories = [];
        $newBrands = [];

        \Log::info('Collecting new categories and brands', ['goods_count' => count($goods), 'first_good' => $goods[0] ?? null]);

        // Получаем кэш карт для определения новых элементов
        $categoryMap = cache('category_map_' . auth()->id(), collect());
        $brandMap = cache('brand_map_' . auth()->id(), collect());

        // Получаем информацию о том, какие элементы были созданы в этом сеансе
        $createdCategories = cache('created_categories_' . auth()->id(), []);
        $createdBrands = cache('created_brands_' . auth()->id(), []);

        foreach ($goods as $index => $goodData) {
            \Log::info("Processing good {$index}", ['good_data' => $goodData]);
            
            // Собираем только те категории, которые были созданы в этом сеансе
            if (isset($goodData['categories']) && is_array($goodData['categories'])) {
                foreach ($goodData['categories'] as $categoryId) {
                    if (is_numeric($categoryId) && in_array($categoryId, $createdCategories)) {
                        // Ищем название категории по ID в кэше
                        $categoryName = $categoryMap->search(function($item, $key) use ($categoryId) {
                            return $item == $categoryId;
                        });
                        
                        if ($categoryName && !in_array($categoryName, $newCategories)) {
                            $newCategories[] = $categoryName;
                            \Log::info("Added new category: {$categoryName} (ID: {$categoryId})");
                        }
                    }
                }
            }
            
            if (isset($goodData['category']) && is_numeric($goodData['category']) && in_array($goodData['category'], $createdCategories)) {
                $categoryName = $categoryMap->search(function($item, $key) use ($goodData) {
                    return $item == $goodData['category'];
                });
                
                if ($categoryName && !in_array($categoryName, $newCategories)) {
                    $newCategories[] = $categoryName;
                    \Log::info("Added new category: {$categoryName} (ID: {$goodData['category']})");
                }
            }

            // Собираем только те бренды, которые были созданы в этом сеансе
            if (isset($goodData['brands']) && is_array($goodData['brands'])) {
                foreach ($goodData['brands'] as $brandId) {
                    if (is_numeric($brandId) && in_array($brandId, $createdBrands)) {
                        // Ищем название бренда по ID в кэше
                        $brandName = $brandMap->search(function($item, $key) use ($brandId) {
                            return $item == $brandId;
                        });
                        
                        if ($brandName && !in_array($brandName, $newBrands)) {
                            $newBrands[] = $brandName;
                            \Log::info("Added new brand: {$brandName} (ID: {$brandId})");
                        }
                    }
                }
            }
            
            if (isset($goodData['brand']) && is_numeric($goodData['brand']) && in_array($goodData['brand'], $createdBrands)) {
                $brandName = $brandMap->search(function($item, $key) use ($goodData) {
                    return $item == $goodData['brand'];
                });
                
                if ($brandName && !in_array($brandName, $newBrands)) {
                    $newBrands[] = $brandName;
                    \Log::info("Added new brand: {$brandName} (ID: {$goodData['brand']})");
                }
            }
        }

        \Log::info('Final collected data', [
            'newCategories' => $newCategories,
            'newBrands' => $newBrands,
            'newCategoriesCount' => count($newCategories),
            'newBrandsCount' => count($newBrands),
            'createdCategories' => $createdCategories,
            'createdBrands' => $createdBrands
        ]);

        $results['newCategories'] = $newCategories;
        $results['newBrands'] = $newBrands;
    }

    /**
     * Применяет модификацию к обычной цене
     */
    private function applyPriceModification($price, $modification)
    {
        if (!$price || !$modification) {
            return $price;
        }

        $multiplier = $modification['multiplier'] ?? 1;
        $addType = $modification['addType'] ?? 'percent';
        $addValue = $modification['addValue'] ?? 0;

        // Сначала умножаем цену
        $newPrice = $price * $multiplier;

        // Затем применяем добавление/вычитание
        switch ($addType) {
            case 'percent':
                $newPrice = $newPrice + ($newPrice * $addValue / 100);
                break;
            case 'number':
                $newPrice = $newPrice + $addValue;
                break;
            case 'subtract_percent':
                $newPrice = $newPrice - ($newPrice * $addValue / 100);
                break;
            case 'subtract_number':
                $newPrice = $newPrice - $addValue;
                break;
        }

        return round($newPrice, 2);
    }

    /**
     * Применяет модификацию к акционной цене
     */
    private function applySalePriceModification($goodData, $priceModification)
    {
        if (!$priceModification || !isset($priceModification['sale'])) {
            return $goodData['sale_price'] ?? null;
        }

        $saleModification = $priceModification['sale'];
        $source = $saleModification['source'] ?? 'file';

        if ($source === 'new_price') {
            // Берем измененную обычную цену
            $regularPrice = $this->applyPriceModification($goodData['price'] ?? 0, $priceModification['regular'] ?? null);
            return $this->applyPriceModification($regularPrice, $saleModification);
        } else {
            // Берем значение из файла
            $salePrice = $goodData['sale_price'] ?? null;
            if (!$salePrice) {
                return null;
            }
            return $this->applyPriceModification($salePrice, $saleModification);
        }
    }

    /**
     * Обрабатывает свойства товара
     */
    private function processProperties($good, $properties)
    {
        if (empty($properties)) {
            return;
        }

        \Log::info('Processing properties for good', [
            'good_id' => $good->id,
            'properties' => $properties
        ]);

        // Подготавливаем данные для синхронизации
        $propertiesToSync = [];
        
        foreach ($properties as $propertyId => $value) {
            if (is_numeric($propertyId) && !empty($value)) {
                $propertiesToSync[$propertyId] = ['value' => $value];
            }
        }

        if (!empty($propertiesToSync)) {
            \Log::info('Syncing properties', [
                'good_id' => $good->id,
                'properties_to_sync' => $propertiesToSync
            ]);
            
            $good->properties()->sync($propertiesToSync);
        }
    }
}
