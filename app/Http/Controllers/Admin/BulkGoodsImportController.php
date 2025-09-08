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

        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'goodIds' => []
        ];

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
        $good->price = $goodData['price'] ?? 0;
        $good->sale_price = $goodData['sale_price'] ?? null;
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
        if (isset($goodData['categories']) && is_array($goodData['categories'])) {
            $categoryIds = $this->processCategories($goodData['categories'], $autoCreateCategories);
            $good->categories()->sync($categoryIds);
        }

        // Обрабатываем бренды
        if (isset($goodData['brands']) && is_array($goodData['brands'])) {
            $brandIds = $this->processBrands($goodData['brands'], $autoCreateBrands);
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
        $existingGood->price = $goodData['price'] ?? $existingGood->price;
        $existingGood->sale_price = $goodData['sale_price'] ?? $existingGood->sale_price;
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
        if (isset($goodData['categories']) && is_array($goodData['categories'])) {
            $categoryIds = $this->processCategories($goodData['categories'], $autoCreateCategories);
            $existingGood->categories()->sync($categoryIds);
        }

        // Обрабатываем бренды
        if (isset($goodData['brands']) && is_array($goodData['brands'])) {
            $brandIds = $this->processBrands($goodData['brands'], $autoCreateBrands);
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

        foreach ($brands as $brand) {
            if (is_numeric($brand)) {
                // Это ID бренда
                $brandIds[] = (int)$brand;
            } else {
                // Это название бренда
                $existingBrand = ShopBrand::where('name', $brand)->first();
                
                if ($existingBrand) {
                    $brandIds[] = $existingBrand->id;
                } elseif ($autoCreate) {
                    $newBrand = ShopBrand::create([
                        'name' => $brand,
                        'slug' => Str::slug($brand),
                        'is_active' => true
                    ]);
                    $brandIds[] = $newBrand->id;
                }
            }
        }

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
}
