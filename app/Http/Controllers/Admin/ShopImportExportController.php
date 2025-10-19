<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopBrand;
use App\Models\ShopTag;
use App\Models\ShopProperty;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShopImportExportController extends Controller
{
    /**
     * Экспорт товаров в CSV
     */
    public function exportCsv(Request $request): JsonResponse
    {
        try {
            $query = ShopGood::with([
                'categories:id,name',
                'brands:id,name',
                'tags:id,name',
                'properties' => function($query) {
                    $query->select('shop_properties.id', 'shop_properties.name')
                          ->withPivot('shop_property_value_id');
                }
            ]);

            // Применяем фильтры если есть
            if ($request->filled('category_id')) {
                $query->byCategory($request->get('category_id'));
            }

            if ($request->filled('brand_id')) {
                $query->byBrand($request->get('brand_id'));
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $goods = $query->get();

            // Создаем CSV файл
            $filename = 'goods_export_' . date('Y-m-d_H-i-s') . '.csv';
            $filepath = 'exports/' . $filename;

            // Создаем директорию если не существует
            if (!Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            $csvData = $this->generateCsvData($goods);
            Storage::disk('public')->put($filepath, $csvData);

            return response()->json([
                'success' => true,
                'message' => 'Экспорт завершен',
                'data' => [
                    'filename' => $filename,
                    'download_url' => Storage::disk('public')->url($filepath),
                    'count' => $goods->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка экспорта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Экспорт товаров в Excel
     */
    public function exportExcel(Request $request): JsonResponse
    {
        // Здесь можно добавить экспорт в Excel используя библиотеку PhpSpreadsheet
        // Пока что возвращаем сообщение о том, что функция в разработке
        return response()->json([
            'success' => false,
            'message' => 'Экспорт в Excel будет доступен в следующей версии'
        ], 501);
    }

    /**
     * Импорт товаров из CSV
     */
    public function importCsv(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10240', // 10MB
            'update_existing' => 'boolean',
            'skip_errors' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $updateExisting = $request->boolean('update_existing', false);
            $skipErrors = $request->boolean('skip_errors', false);

            $csvData = $this->parseCsvFile($file);
            $result = $this->importGoodsFromCsv($csvData, $updateExisting, $skipErrors);

            return response()->json([
                'success' => true,
                'message' => 'Импорт завершен',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка импорта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить шаблон для импорта
     */
    public function getTemplate(): JsonResponse
    {
        try {
            $filename = 'goods_import_template.csv';
            $filepath = 'templates/' . $filename;

            // Создаем директорию если не существует
            if (!Storage::disk('public')->exists('templates')) {
                Storage::disk('public')->makeDirectory('templates');
            }

            // Создаем шаблон CSV
            $templateData = $this->generateTemplateCsv();
            Storage::disk('public')->put($filepath, $templateData);

            return response()->json([
                'success' => true,
                'data' => [
                    'filename' => $filename,
                    'download_url' => Storage::disk('public')->url($filepath)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания шаблона: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Генерация CSV данных для экспорта
     */
    private function generateCsvData($goods): string
    {
        $headers = [
            'ID',
            'Название',
            'Артикул',
            'Slug',
            'Описание',
            'Краткое описание',
            'Цена',
            'Акционная цена',
            'Количество на складе',
            'Ширина',
            'Высота',
            'Глубина',
            'Вес',
            'Рейтинг',
            'Количество отзывов',
            'Meta заголовок',
            'Meta описание',
            'Активен',
            'Рекомендуемый',
            'Новый',
            'Со скидкой',
            'Категории',
            'Бренды',
            'Теги',
            'Свойства',
            'Дата создания',
            'Дата обновления'
        ];

        $csvData = implode(',', array_map(function($header) {
            return '"' . str_replace('"', '""', $header) . '"';
        }, $headers)) . "\n";

        foreach ($goods as $good) {
            $row = [
                $good->id,
                $good->name,
                $good->sku,
                $good->slug,
                $good->description,
                $good->short_description,
                $good->price,
                $good->sale_price,
                $good->stock_quantity,
                $good->width,
                $good->height,
                $good->depth,
                $good->weight,
                $good->rating,
                $good->reviews_count,
                $good->meta_title,
                $good->meta_description,
                $good->is_active ? 'Да' : 'Нет',
                $good->is_featured ? 'Да' : 'Нет',
                $good->is_new ? 'Да' : 'Нет',
                $good->is_sale ? 'Да' : 'Нет',
                $good->categories->pluck('name')->join(';'),
                $good->brands->pluck('name')->join(';'),
                $good->tags->pluck('name')->join(';'),
                $good->properties->map(function($property) {
                    $value = '';
                    if ($property->pivot->shop_property_value_id) {
                        // Получаем значение через связь PropertyValue
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $value = $propertyValue ? $propertyValue->value : '';
                    }
                    return $property->name . ':' . $value;
                })->join(';'),
                $good->created_at,
                $good->updated_at
            ];

            $csvData .= implode(',', array_map(function($value) {
                return '"' . str_replace('"', '""', $value ?? '') . '"';
            }, $row)) . "\n";
        }

        return $csvData;
    }

    /**
     * Генерация шаблона CSV
     */
    private function generateTemplateCsv(): string
    {
        $headers = [
            'Название',
            'Артикул',
            'Slug',
            'Описание',
            'Краткое описание',
            'Цена',
            'Акционная цена',
            'Количество на складе',
            'Ширина',
            'Высота',
            'Глубина',
            'Вес',
            'Meta заголовок',
            'Meta описание',
            'Активен',
            'Рекомендуемый',
            'Новый',
            'Со скидкой',
            'Категории',
            'Бренды',
            'Теги',
            'Свойства'
        ];

        $csvData = implode(',', array_map(function($header) {
            return '"' . str_replace('"', '""', $header) . '"';
        }, $headers)) . "\n";

        // Добавляем пример строки
        $exampleRow = [
            'Пример товара',
            'EXAMPLE-001',
            'example-tovar',
            'Описание товара',
            'Краткое описание',
            '1000.00',
            '800.00',
            '10',
            '10.5',
            '20.0',
            '5.0',
            '1.2',
            'Meta заголовок',
            'Meta описание',
            'Да',
            'Нет',
            'Да',
            'Да',
            'Категория 1;Категория 2',
            'Бренд 1',
            'Тег 1;Тег 2',
            'Цвет:Красный;Размер:L'
        ];

        $csvData .= implode(',', array_map(function($value) {
            return '"' . str_replace('"', '""', $value) . '"';
        }, $exampleRow)) . "\n";

        return $csvData;
    }

    /**
     * Парсинг CSV файла
     */
    private function parseCsvFile($file): array
    {
        $csvData = [];
        $handle = fopen($file->getPathname(), 'r');
        
        if ($handle !== false) {
            $headers = fgetcsv($handle);
            
            while (($row = fgetcsv($handle)) !== false) {
                $csvData[] = array_combine($headers, $row);
            }
            
            fclose($handle);
        }

        return $csvData;
    }

    /**
     * Импорт товаров из CSV данных
     */
    private function importGoodsFromCsv(array $csvData, bool $updateExisting, bool $skipErrors): array
    {
        $result = [
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'error_details' => []
        ];

        DB::beginTransaction();

        try {
            foreach ($csvData as $index => $row) {
                try {
                    $this->importGoodFromRow($row, $updateExisting);
                    
                    if ($updateExisting && ShopGood::where('sku', $row['Артикул'])->exists()) {
                        $result['updated']++;
                    } else {
                        $result['imported']++;
                    }
                    
                } catch (\Exception $e) {
                    $result['errors']++;
                    $result['error_details'][] = [
                        'row' => $index + 2, // +2 потому что первая строка - заголовки, а индексация с 0
                        'error' => $e->getMessage()
                    ];
                    
                    if (!$skipErrors) {
                        throw $e;
                    }
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Импорт одного товара из строки CSV
     */
    private function importGoodFromRow(array $row, bool $updateExisting): void
    {
        $sku = $row['Артикул'] ?? '';
        
        if (empty($sku)) {
            throw new \Exception('Артикул не может быть пустым');
        }

        $goodData = [
            'name' => $row['Название'] ?? '',
            'sku' => $sku,
            'slug' => $row['Slug'] ?? Str::slug($row['Название'] ?? ''),
            'description' => $row['Описание'] ?? '',
            'short_description' => $row['Краткое описание'] ?? '',
            'price' => (float) ($row['Цена'] ?? 0),
            'sale_price' => !empty($row['Акционная цена']) ? (float) $row['Акционная цена'] : null,
            'stock_quantity' => (int) ($row['Количество на складе'] ?? 0),
            'width' => !empty($row['Ширина']) ? (float) $row['Ширина'] : null,
            'height' => !empty($row['Высота']) ? (float) $row['Высота'] : null,
            'depth' => !empty($row['Глубина']) ? (float) $row['Глубина'] : null,
            'weight' => !empty($row['Вес']) ? (float) $row['Вес'] : null,
            'meta_title' => $row['Meta заголовок'] ?? '',
            'meta_description' => $row['Meta описание'] ?? '',
            'is_active' => $this->parseBoolean($row['Активен'] ?? 'Да'),
            'is_featured' => $this->parseBoolean($row['Рекомендуемый'] ?? 'Нет'),
            'is_new' => $this->parseBoolean($row['Новый'] ?? 'Нет'),
            'is_sale' => $this->parseBoolean($row['Со скидкой'] ?? 'Нет'),
        ];

        $existingGood = ShopGood::where('sku', $sku)->first();

        if ($existingGood && $updateExisting) {
            $existingGood->update($goodData);
            $good = $existingGood;
        } elseif (!$existingGood) {
            $good = ShopGood::create($goodData);
        } else {
            throw new \Exception("Товар с артикулом {$sku} уже существует");
        }

        // Обработка категорий
        if (!empty($row['Категории'])) {
            $categoryNames = explode(';', $row['Категории']);
            $categoryIds = [];
            
            foreach ($categoryNames as $categoryName) {
                $categoryName = trim($categoryName);
                if (!empty($categoryName)) {
                    $category = Category::where('name', $categoryName)->first();
                    if ($category) {
                        $categoryIds[] = $category->id;
                    }
                }
            }
            
            $good->categories()->sync($categoryIds);
        }

        // Обработка брендов
        if (!empty($row['Бренды'])) {
            $brandNames = explode(';', $row['Бренды']);
            $brandIds = [];
            
            foreach ($brandNames as $brandName) {
                $brandName = trim($brandName);
                if (!empty($brandName)) {
                    $brand = ShopBrand::where('name', $brandName)->first();
                    if ($brand) {
                        $brandIds[] = $brand->id;
                    }
                }
            }
            
            $good->brands()->sync($brandIds);
        }

        // Обработка тегов
        if (!empty($row['Теги'])) {
            $tagNames = explode(';', $row['Теги']);
            $tagIds = [];
            
            foreach ($tagNames as $tagName) {
                $tagName = trim($tagName);
                if (!empty($tagName)) {
                    $tag = ShopTag::where('name', $tagName)->first();
                    if (!$tag) {
                        $tag = ShopTag::create([
                            'name' => $tagName,
                            'slug' => Str::slug($tagName)
                        ]);
                    }
                    $tagIds[] = $tag->id;
                }
            }
            
            $good->tags()->sync($tagIds);
        }

        // Обработка свойств
        if (!empty($row['Свойства'])) {
            $properties = explode(';', $row['Свойства']);
            
            foreach ($properties as $property) {
                $property = trim($property);
                if (!empty($property) && strpos($property, ':') !== false) {
                    [$propertyName, $propertyValue] = explode(':', $property, 2);
                    $propertyName = trim($propertyName);
                    $propertyValue = trim($propertyValue);
                    
                    if (!empty($propertyName) && !empty($propertyValue)) {
                        $propertyModel = ShopProperty::where('name', $propertyName)->first();
                        if (!$propertyModel) {
                            $propertyModel = ShopProperty::create([
                                'name' => $propertyName,
                                'slug' => Str::slug($propertyName)
                            ]);
                        }
                        
                        $good->properties()->syncWithoutDetaching([
                            $propertyModel->id => ['value' => $propertyValue]
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Парсинг булевых значений
     */
    private function parseBoolean(string $value): bool
    {
        $value = strtolower(trim($value));
        return in_array($value, ['да', 'yes', 'true', '1', 'on']);
    }
}
