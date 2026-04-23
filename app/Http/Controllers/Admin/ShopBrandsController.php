<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShopBrandsController extends Controller
{
    /**
     * Получить список брендов
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShopBrand::query();

        // Поиск
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        }

        // Фильтр по статусу
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Сортировка по умолчанию по sort_order, затем по названию
        $query->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc');

        // Если запрашиваются конкретные ID (для массовых операций)
        if ($request->has('ids')) {
            $ids = is_array($request->ids) ? $request->ids : [$request->ids];
            $query->whereIn('id', $ids);
            $brands = $query->get();

            return response()->json([
                'success' => true,
                'data' => $brands,
            ]);
        }

        // Возвращаем все бренды без пагинации (как в Categories)
        $brands = $query->get();

        // Добавляем количество товаров для каждого бренда
        $brands->each(function ($brand) {
            $brand->goods_count = \App\Models\ShopGood::whereHas('brands', function ($q) use ($brand) {
                $q->where('shop_brands.id', $brand->id);
            })->count();
        });

        return response()->json([
            'success' => true,
            'data' => $brands,
        ]);
    }

    /**
     * Получить бренд по ID
     */
    public function show($id): JsonResponse
    {
        $brand = ShopBrand::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $brand,
        ]);
    }

    /**
     * Создать новый бренд
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255|unique:shop_brands,slug',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $brand = ShopBrand::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Бренд успешно создан',
            'data' => $brand,
        ], 201);
    }

    /**
     * Обновить бренд
     */
    public function update(Request $request, $id): JsonResponse
    {
        $brand = ShopBrand::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shop_brands', 'slug')->ignore($id)],
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $brand->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Бренд успешно обновлен',
            'data' => $brand,
        ]);
    }

    /**
     * Удалить бренд
     */
    public function destroy($id): JsonResponse
    {
        $brand = ShopBrand::findOrFail($id);
        $brand->delete();

        return response()->json([
            'success' => true,
            'message' => 'Бренд успешно удален',
        ]);
    }

    /**
     * Получить все активные бренды для селекта
     */
    public function active(): JsonResponse
    {
        $brands = ShopBrand::active()->ordered()->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $brands,
        ]);
    }

    /**
     * Обновить порядок брендов
     */
    public function updateOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.id' => 'required|exists:shop_brands,id',
            'items.*.sort_order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            foreach ($request->items as $item) {
                ShopBrand::where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Порядок брендов успешно обновлен',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления порядка: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Импорт брендов из файла
     */
    public function import(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'brands' => 'required|array',
                'brands.*.name' => 'required|string|max:255',
                'brands.*.slug' => 'required|string|max:255',
                'brands.*.description' => 'nullable|string',
                'brands.*.logo' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $brandsData = $request->input('brands');
            $created = 0;
            $updated = 0;
            $errors = [];

            // Получаем путь к фронтенду (из FRONTEND_PATH в .env)
            $imagesPath = frontend_public_path('images/shop/brands');

            foreach ($brandsData as $index => $brandData) {
                try {
                    // Проверяем, существует ли бренд с таким slug
                    $existingBrand = ShopBrand::where('slug', $brandData['slug'])->first();

                    // Подготавливаем данные
                    $data = [
                        'name' => $brandData['name'],
                        'slug' => $brandData['slug'],
                        'description' => $brandData['description'] ?? null,
                        'is_active' => true,
                    ];

                    if ($existingBrand) {
                        // Обновляем существующий бренд
                        $existingBrand->name = $brandData['name'];
                        $existingBrand->description = $brandData['description'] ?? $existingBrand->description;

                        // Загружаем логотип, если есть URL
                        if (! empty($brandData['logo'])) {
                            try {
                                // Проверяем, это URL или путь к файлу
                                if (filter_var($brandData['logo'], FILTER_VALIDATE_URL)) {
                                    // Загружаем изображение по URL
                                    $imageData = @file_get_contents($brandData['logo']);
                                    if ($imageData !== false) {
                                        // Создаем директорию, если её нет
                                        if (! is_dir($imagesPath)) {
                                            mkdir($imagesPath, 0755, true);
                                        }

                                        // Определяем расширение
                                        $extension = 'png';
                                        $imageInfo = @getimagesizefromstring($imageData);
                                        if ($imageInfo !== false) {
                                            $mimeType = $imageInfo['mime'];
                                            if ($mimeType === 'image/jpeg') {
                                                $extension = 'jpg';
                                            } elseif ($mimeType === 'image/gif') {
                                                $extension = 'gif';
                                            } elseif ($mimeType === 'image/webp') {
                                                $extension = 'webp';
                                            }
                                        }

                                        // Сохраняем изображение
                                        $fileName = 'brand_'.$existingBrand->id.'_'.time().'.'.$extension;
                                        $filePath = $imagesPath.'/'.$fileName;
                                        file_put_contents($filePath, $imageData);

                                        // Обновляем путь к изображению
                                        $existingBrand->logo = 'images/shop/brands/'.$fileName;
                                    }
                                } else {
                                    // Если это путь к файлу, просто сохраняем
                                    $existingBrand->logo = $brandData['logo'];
                                }
                            } catch (\Exception $e) {
                                $errors[] = "Бренд \"{$brandData['name']}\": ошибка загрузки логотипа - {$e->getMessage()}";
                            }
                        }

                        $existingBrand->save();
                        $updated++;
                    } else {
                        // Создаем новый бренд
                        $newBrand = ShopBrand::create($data);

                        // Загружаем логотип, если есть URL
                        if (! empty($brandData['logo'])) {
                            try {
                                if (filter_var($brandData['logo'], FILTER_VALIDATE_URL)) {
                                    // Загружаем изображение по URL
                                    $imageData = @file_get_contents($brandData['logo']);
                                    if ($imageData !== false) {
                                        // Создаем директорию, если её нет
                                        if (! is_dir($imagesPath)) {
                                            mkdir($imagesPath, 0755, true);
                                        }

                                        // Определяем расширение
                                        $extension = 'png';
                                        $imageInfo = @getimagesizefromstring($imageData);
                                        if ($imageInfo !== false) {
                                            $mimeType = $imageInfo['mime'];
                                            if ($mimeType === 'image/jpeg') {
                                                $extension = 'jpg';
                                            } elseif ($mimeType === 'image/gif') {
                                                $extension = 'gif';
                                            } elseif ($mimeType === 'image/webp') {
                                                $extension = 'webp';
                                            }
                                        }

                                        // Сохраняем изображение
                                        $fileName = 'brand_'.$newBrand->id.'_'.time().'.'.$extension;
                                        $filePath = $imagesPath.'/'.$fileName;
                                        file_put_contents($filePath, $imageData);

                                        // Обновляем путь к изображению
                                        $newBrand->logo = 'images/shop/brands/'.$fileName;
                                        $newBrand->save();
                                    }
                                } else {
                                    // Если это путь к файлу, просто сохраняем
                                    $newBrand->logo = $brandData['logo'];
                                    $newBrand->save();
                                }
                            } catch (\Exception $e) {
                                $errors[] = "Бренд \"{$brandData['name']}\": ошибка загрузки логотипа - {$e->getMessage()}";
                            }
                        }

                        $created++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Бренд \"{$brandData['name']}\": {$e->getMessage()}";
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Импорт завершен',
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка импорта: '.$e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        try {
            $ids = $request->input('ids', []);
            if (empty($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не выбраны бренды для удаления',
                ], 422);
            }

            $brands = ShopBrand::whereIn('id', $ids)->get();
            $count = 0;

            foreach ($brands as $brand) {
                // Удаляем логотип если есть
                if ($brand->logo) {
                    $imagePath = public_path($brand->logo);
                    if (file_exists($imagePath)) {
                        @unlink($imagePath);
                    }
                }
                
                $brand->delete();
                $count++;
            }

            return response()->json([
                'success' => true,
                'message' => "Успешно удалено брендов: $count",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при массовом удалении: ' . $e->getMessage(),
            ], 500);
        }
    }
}
