<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopCategory;
use App\Models\ShopBrand;
use App\Models\ShopGoodImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BulkGoodsImportController extends Controller
{
    public function bulkImport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'goods' => 'required|array',
            'goods.*.sku' => 'required|string',
            'goods.*.name' => 'required|string',
            'duplicate_action' => 'required|in:skip,update',
            'auto_create_categories' => 'boolean',
            'auto_create_brands' => 'boolean',
            'process_categories_and_brands' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $goods = $request->input('goods');
        $duplicateAction = $request->input('duplicate_action', 'skip');
        $autoCreateCategories = $request->input('auto_create_categories', true);
        $autoCreateBrands = $request->input('auto_create_brands', true);
        $processCategoriesAndBrands = $request->input('process_categories_and_brands', false);

        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
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
            foreach ($goods as $index => $goodData) {
                try {
                    $sku = $goodData['sku'];
                    $existingGood = ShopGood::where('sku', $sku)->first();

                    if ($existingGood) {
                        // Товар существует
                        if ($duplicateAction === 'update') {
                            $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands);
                            $results['updated']++;
                            $results['goodIds'][$sku] = $existingGood->id;
                        } else {
                            $results['skipped']++;
                        }
                    } else {
                        // Создаем новый товар
                        $newGood = $this->createGood($goodData, $autoCreateCategories, $autoCreateBrands);
                        $results['imported']++;
                        $results['goodIds'][$sku] = $newGood->id;
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 1,
                        'sku' => $goodData['sku'] ?? 'неизвестно',
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Импорт завершен',
                'results' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
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
                $existingBrand = ShopBrand::where('name', $brand)->first();
                
                if ($existingBrand) {
                    $brandIds[] = $existingBrand->id;
                    \Log::info('Found existing brand', ['brand_name' => $brand, 'brand_id' => $existingBrand->id]);
                } elseif ($autoCreate) {
                    $newBrand = ShopBrand::create([
                        'name' => $brand,
                        'slug' => Str::slug($brand),
                        'is_active' => true
                    ]);
                    $brandIds[] = $newBrand->id;
                    \Log::info('Created new brand', ['brand_name' => $brand, 'brand_id' => $newBrand->id]);
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
        $this->applyCategoryAndBrandIds($goods);
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
            }
        }

        // Сохраняем карту в кэше для использования в applyCategoryAndBrandIds
        cache(['category_map_' . auth()->id() => $categoryMap], 300); // 5 минут
    }

    /**
     * Пакетная обработка брендов
     */
    private function processBrandsBatch($allBrands, $autoCreate)
    {
        // Загружаем существующие бренды
        $existingBrands = ShopBrand::whereIn('name', $allBrands->toArray())
            ->get()
            ->keyBy(function($item) {
                return strtolower($item->name);
            });

        $brandMap = $existingBrands->mapWithKeys(function($item) {
            return [strtolower($item->name) => $item->id];
        });

        // Создаем недостающие бренды
        if ($autoCreate) {
            $brandsToCreate = $allBrands->filter(function($name) use ($brandMap) {
                return !$brandMap->has(strtolower($name));
            });

            \Log::info('Creating brands batch', ['brands_to_create' => $brandsToCreate->toArray()]);

            foreach ($brandsToCreate as $brandName) {
                $brand = ShopBrand::create([
                    'name' => $brandName,
                    'slug' => Str::slug($brandName),
                    'is_active' => true
                ]);

                $brandMap[strtolower($brandName)] = $brand->id;
                \Log::info('Created brand', ['brand_name' => $brandName, 'brand_id' => $brand->id]);
            }
        }

        \Log::info('Final brand map', ['brand_map' => $brandMap->toArray()]);

        // Сохраняем карту в кэше для использования в applyCategoryAndBrandIds
        cache(['brand_map_' . auth()->id() => $brandMap], 300); // 5 минут
    }

    /**
     * Применение найденных ID к товарам
     */
    private function applyCategoryAndBrandIds(&$goods)
    {
        $categoryMap = cache('category_map_' . auth()->id(), collect());
        $brandMap = cache('brand_map_' . auth()->id(), collect());
        
        \Log::info('Applying category and brand IDs', [
            'category_map_size' => $categoryMap->count(),
            'brand_map_size' => $brandMap->count(),
            'goods_count' => count($goods)
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
                    unset($good['category']);
                }
            } elseif (isset($good['categories'])) {
                if (is_string($good['categories'])) {
                    $categoryNames = $this->parseDelimitedString($good['categories']);
                    $categoryIds = [];

                    foreach ($categoryNames as $categoryName) {
                        $categoryId = $categoryMap[strtolower($categoryName)] ?? null;
                        if ($categoryId) {
                            $categoryIds[] = $categoryId;
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
                    unset($good['brand']);
                }
            } elseif (isset($good['brands'])) {
                if (is_string($good['brands'])) {
                    $brandNames = $this->parseDelimitedString($good['brands']);
                    $brandIds = [];

                    foreach ($brandNames as $brandName) {
                        $brandId = $brandMap[strtolower($brandName)] ?? null;
                        if ($brandId) {
                            $brandIds[] = $brandId;
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

        foreach ($goods as $index => $goodData) {
            \Log::info("Processing good {$index}", ['good_data' => $goodData]);
            
            // Собираем новые категории - ищем по ID в кэше
            if (isset($goodData['categories']) && is_array($goodData['categories'])) {
                foreach ($goodData['categories'] as $categoryId) {
                    if (is_numeric($categoryId)) {
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
            
            if (isset($goodData['category']) && is_numeric($goodData['category'])) {
                $categoryName = $categoryMap->search(function($item, $key) use ($goodData) {
                    return $item == $goodData['category'];
                });
                
                if ($categoryName && !in_array($categoryName, $newCategories)) {
                    $newCategories[] = $categoryName;
                    \Log::info("Added new category: {$categoryName} (ID: {$goodData['category']})");
                }
            }

            // Собираем новые бренды - ищем по ID в кэше
            if (isset($goodData['brands']) && is_array($goodData['brands'])) {
                foreach ($goodData['brands'] as $brandId) {
                    if (is_numeric($brandId)) {
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
            
            if (isset($goodData['brand']) && is_numeric($goodData['brand'])) {
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
            'newBrandsCount' => count($newBrands)
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
}
