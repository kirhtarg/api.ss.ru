<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopCategory;
use App\Models\ShopBrand;
use App\Models\ShopSupplier;
use App\Models\ShopGoodImage;
use App\Models\ShopPropertyValue;
use App\Models\ShopGoodProperty;
use App\Models\ShopGoodVariation;
use App\Services\ImportLogService;
use App\Services\GoodsBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class BulkGoodsImportController extends Controller
{
    private $importLogService;
    private $backupService;

    public function __construct(ImportLogService $importLogService, GoodsBackupService $backupService)
    {
        $this->importLogService = $importLogService;
        $this->backupService = $backupService;
    }

    public function bulkImport(Request $request)
    {
        // Определяем имя поставщика
        $supplierNameFromRequest = $request->input('supplier_name');
        if ($supplierNameFromRequest && $supplierNameFromRequest !== '__reset_all__') {
            $supplierNameFromRequest = trim($supplierNameFromRequest);
        }

        // Получаем информацию о батче для очистки логов
        $isFirstBatch = $request->input('is_first_batch', false);

        // Получаем выбранные поля остатков из запроса для обнуления
        $resetStockFields = $request->input('reset_stock_fields', []);

        // Определяем supplierStockFields для обнуления и использования в остальной части метода
        $supplierStockFields = [];
        if (!empty($resetStockFields)) {
            foreach ($resetStockFields as $field) {
                $supplierStockFields[$field] = true;
            }
        }

        // Выполняем обнуление остатков перед импортом (только для первого батча)
        $resetStats = null;
        if ($isFirstBatch && !empty($supplierStockFields) && $supplierNameFromRequest) {
            $resetStats = $this->resetSupplierStocks($supplierNameFromRequest, $supplierStockFields);

            }

        // Очищаем логи только для первого батча
        if ($isFirstBatch) {
            $this->importLogService->clearAllLogs();
        }

        // Создаем резервную копию перед импортом (только для первого батча) - временно отключено
        // $backupId = null;
        // $createBackup = $request->input('create_backup_before_import', false);
        // if ($isFirstBatch && $createBackup) {
        //     try {
        //         //         $backup = $this->backupService->createBackup(
        //             'Резервная копия перед импортом ' . now()->format('d.m.Y H:i'),
        //             auth()->id()
        //         );

        //         $backupId = $backup->id;

        //         //     } catch (\Exception $e) {
        //         Log::error('Ошибка создания резервной копии перед импортом', [
        //             'error' => $e->getMessage(),
        //             'user_id' => auth()->id()
        //         ]);

        //         // Продолжаем импорт, но логируем ошибку
        //     }
        // }

        // Получаем данные товаров
        $allGoods = $request->input('goods', []);

        // Получаем параметры импорта
        $nameTrimSymbol = $request->input('name_trim_symbol');

        // Фильтруем пустые строки - оставляем только товары с заполненными SKU и названием
        $goods = [];
        $skippedRows = [];

        foreach ($allGoods as $index => $good) {
            $sku = isset($good['sku']) ? trim((string) $good['sku']) : '';
            $name = isset($good['name']) ? trim((string) $good['name']) : '';

            // Получаем информацию о листе
            $sheet = $good['_sheet'] ?? 'неизвестно';


            // Создаем уникальный идентификатор для товара
            $itemId = $sheet . '_' . $index . '_' . substr(md5($sku . $name), 0, 8);

            // Пропускаем только полностью пустые строки (где И SKU И название пустые)
            if (empty($sku) && empty($name)) {
                $reason = 'Пустая строка';


                $skippedRows[] = [
                    'count' => $index + 1,
                    'sku' => $sku,
                    'name' => $name,
                    'sheet' => $sheet,
                    'reason' => $reason
                ];

                continue;
            }

            // Если нет ни SKU, ни названия - пропускаем строку
            if (empty($sku) && empty($name)) {
                $reason = 'Отсутствует SKU и название';
                $skippedRows[] = [
                    'count' => $index + 1,
                    'sku' => $sku,
                    'name' => $name,
                    'sheet' => $sheet,
                    'reason' => $reason
                ];
                continue;
            }

            // Если есть только SKU без названия - это валидно для обновления существующего товара
            // Если есть только название без SKU - это валидно для создания нового товара
            // Если есть и SKU, и название - это валидно для создания или обновления
            
            // Нормализуем данные
            $good['sku'] = $sku;
            $good['name'] = $name; // Может быть пустым, если есть только SKU
            
            // Добавляем supplier_name из запроса, если указан
            $supplierName = $request->input('supplier_name', null);
            if ($supplierName !== null && $supplierName !== '') {
                $good['supplier_name'] = trim($supplierName);
            }
            
            $goods[] = $good;

        }

        // Логируем пропущенные строки
        if (!empty($skippedRows)) {
            $this->importLogService->logSkippedBatch($skippedRows);
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
            'goods.*.sku' => 'nullable|string|max:255',
            'goods.*.name' => 'nullable|string|min:1', // name теперь необязателен, если есть SKU
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
        $defaultCategory = $request->input('default_category', null);
        $useDefaultCategory = $request->input('use_default_category', false);
        $immutableFields = $request->input('immutable_fields', []);
        $supplierName = $request->input('supplier_name', null);
        
        // Преобразуем immutable_fields в массив, если пришло как строка
        if (is_string($immutableFields)) {
            $immutableFields = json_decode($immutableFields, true) ?? [];
        }
        if (!is_array($immutableFields)) {
            $immutableFields = [];
        }
        
        // Преобразуем в boolean, если пришло как строка
        if (is_string($useDefaultCategory)) {
            $useDefaultCategory = in_array(strtolower($useDefaultCategory), ['true', '1', 'yes', 'on']);
        }
        $useDefaultCategory = (bool)$useDefaultCategory;
        
        // Обрабатываем defaultCategory: если это строка (название), находим или создаем категорию
        // Если это число (ID), используем как есть
        if ($defaultCategory !== null && is_string($defaultCategory) && trim($defaultCategory) !== '') {
            $categoryName = trim($defaultCategory);
            // Ищем категорию по названию
            $foundCategory = \App\Models\ShopCategory::where('name', $categoryName)->first();
            
            if ($foundCategory) {
                $defaultCategory = $foundCategory->id;
            } else {
                // Если категория не найдена, создаем её
                $newCategory = \App\Models\ShopCategory::create([
                    'name' => $categoryName,
                    'slug' => \Illuminate\Support\Str::slug($categoryName),
                    'parent_id' => null,
                    'is_active' => true,
                    'is_main' => false,
                    'in_catalog' => false,
                    'in_figure' => false
                ]);
                $defaultCategory = $newCategory->id;
            }
        } elseif ($defaultCategory !== null && is_numeric($defaultCategory)) {
            // Если это число, используем как ID
            $defaultCategory = (int)$defaultCategory;
        } else {
            // Если пустое или null, устанавливаем null
            $defaultCategory = null;
        }
        


        // Получаем информацию о батче
        $batchNumber = $request->input('batch_number', 1);
        $totalBatches = $request->input('total_batches', 1);

        // Если указан поставщик и это первый батч, обнуляем остатки у всех товаров этого поставщика
        if ($isFirstBatch && $supplierName !== null && $supplierName !== '') {
            $this->resetSupplierStockByName(trim($supplierName));
        }

        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => count($skippedRows), // Включаем пропущенные строки
            'failed' => 0,
            'errors' => [],
            'goodIds' => [],
            'variationIds' => [], // ID вариаций для связи с изображениями
            'newCategories' => [],
            'newBrands' => [],
            'imagesDownloaded' => 0, // Количество загруженных изображений
            'imagesFailed' => 0 // Количество неудачных загрузок изображений
        ];

        // Пакетная обработка категорий и брендов
        if ($processCategoriesAndBrands && ($autoCreateCategories || $autoCreateBrands)) {
            $this->processCategoriesAndBrandsBatch($goods, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory);

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

                    // Определяем поле для поиска совпадений
                    $duplicateFields = $request->input('duplicate_fields', ['sku']);
                    $searchByNameInVariations = $request->input('search_by_name_in_variations', false);
                    // Преобразуем в boolean, если пришло как строка
                    if (is_string($searchByNameInVariations)) {
                        $searchByNameInVariations = in_array(strtolower($searchByNameInVariations), ['true', '1', 'yes', 'on']);
                    }
                    $searchByNameInVariations = (bool)$searchByNameInVariations;

                    // Поле для поиска в вариациях ('name' или 'sku')
                    $searchByFieldInVariations = $request->input('search_by_field_in_variations', 'name');
                    if (!in_array($searchByFieldInVariations, ['name', 'sku'])) {
                        $searchByFieldInVariations = 'name'; // значение по умолчанию
                    }


                    $existingGood = null;
                    $existingVariation = null;
                    $foundByFields = []; // Массив для хранения информации о том, по каким полям найден товар

                    // Инициализируем переменную hasVariation
                    $hasVariation = false;

                    // Если есть поле variation, всегда ищем товар по имени
                    $hasVariation = isset($goodData['variation']) && is_array($goodData['variation']) &&
                                    isset($goodData['variation']['attributes']) &&
                                    is_array($goodData['variation']['attributes']) &&
                                    count($goodData['variation']['attributes']) > 0;

                    // Сначала проверяем режим поиска в вариациях (если включен)
                    if ($searchByNameInVariations && !$hasVariation) {
                        // Поиск вариаций по имени отключаем для товаров с вариациями,
                        // потому что может быть несколько вариаций с одинаковым SKU
                        // Определяем значение для поиска в вариациях
                        $searchValue = ($searchByFieldInVariations === 'sku') ? $sku : $name;

                        if (!empty($searchValue)) {
                            // Ищем вариацию по выбранному полю
                            $existingVariation = ShopGoodVariation::where($searchByFieldInVariations, $searchValue)->first();

                            if ($existingVariation) {
                                $existingGood = $existingVariation->good;
                            }
                            // Если вариация не найдена, existingVariation остается null
                            // и existingGood тоже null, что приведет к обычному поиску товара ниже
                        }
                    }

                    // Если вариация не найдена (или поиск в вариациях отключен), ищем товар обычным способом
                    if (!$existingVariation) {
                        // Обычный поиск товара по duplicateFields
                        foreach ($duplicateFields as $field) {
                            if ($field === 'sku') {
                                // Если SKU пустой, ищем товары с NULL в поле SKU
                                if (!empty($sku)) {
                                    $existingGood = ShopGood::where('sku', $sku)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}'";
                                    }
                                } else {
                                    // Ищем товары с пустым SKU
                                    $existingGood = ShopGood::whereNull('sku')->where('name', $name)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "пустой SKU + название: '{$name}'";
                                    }
                                }
                            } elseif ($field === 'name') {
                                $existingGood = ShopGood::where('name', $name)->first();
                                if ($existingGood) {
                                    $foundByFields[] = "название: '{$name}'";
                                }
                            }

                            if ($existingGood) {
                                break; // Найден товар, прекращаем поиск
                            }
                        }
                    }

                    if ($hasVariation) {
                        // При наличии вариации ищем товар более тщательно
                        // Важно: для вариаций товар должен существовать, иначе будет ошибка дублирования
                        // Проверяем все возможные варианты поиска


                        // 1. Сначала по SKU (если указан) - самый надежный способ
                        if (!empty($sku)) {
                            $existingGood = ShopGood::where('sku', $sku)->first();
                            if ($existingGood) {
                                $foundByFields[] = "SKU: '{$sku}' (для вариации)";
                            }
                        }

                        // 2. Если не найден по SKU, ищем по имени (точное совпадение)
                        if (!$existingGood) {
                            $existingGood = ShopGood::where('name', $name)->first();
                            if ($existingGood) {
                                $foundByFields[] = "название: '{$name}' (для вариации)";
                            }
                        }

                        // 3. Если не найден, пробуем по имени с пустым SKU
                        if (!$existingGood) {
                            $existingGood = ShopGood::whereNull('sku')->where('name', $name)->first();
                            if ($existingGood) {
                                $foundByFields[] = "пустой SKU + название: '{$name}' (для вариации)";
                            }
                        }
                        
                        // 4. Если все еще не найден, пробуем поиск по имени без учета регистра и пробелов
                        if (!$existingGood) {
                            // Нормализуем имя для поиска (убираем лишние пробелы, приводим к нижнему регистру)
                            $normalizedName = trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
                            $allGoods = ShopGood::whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])->get();
                            if ($allGoods->count() > 0) {
                                $existingGood = $allGoods->first();
                            }
                        }
                        
                    } else {
                        // Ищем товар по указанным полям (обычная логика)
                        foreach ($duplicateFields as $field) {
                            if ($field === 'sku') {
                                // Если SKU пустой, ищем товары с NULL в поле SKU
                                if (!empty($sku)) {
                                    $existingGood = ShopGood::where('sku', $sku)->first();
                                } else {
                                    // Ищем товары с пустым SKU
                                    $existingGood = ShopGood::whereNull('sku')->where('name', $name)->first();
                                }
                            } elseif ($field === 'name') {
                                $existingGood = ShopGood::where('name', $name)->first();
                            }

                            if ($existingGood) {
                                break; // Найден товар, прекращаем поиск
                            }
                        }
                    }

                    // Если найдена вариация по имени (при включенном поиске по именам в вариациях)
                    if ($existingVariation && $searchByNameInVariations) {
                        // Убеждаемся, что товар найден (если вариация найдена, товар должен быть)
                        if (!$existingGood && $existingVariation->good) {
                            $existingGood = $existingVariation->good;
                        }
                        
                        // Если товар все еще не найден, пропускаем эту строку
                        if (!$existingGood) {
                            $results['skipped']++;
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $reason = 'Вариация найдена по имени "' . $searchValue . '" (поле: ' . $searchByFieldInVariations . '), но связанный товар не найден в базе данных. Вариация ID: ' . $existingVariation->id;
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];
                            continue;
                        }
                        
                        // Обновляем вариацию найденную по имени
                        $variationId = $this->updateVariationFromGoodData($existingVariation, $goodData, $searchByNameInVariations);

                        // Логируем действие с вариацией
                        $sheet = $goodData['_sheet'] ?? 'неизвестно';
                        $variationAttributes = [];
                        // Получаем атрибуты вариации для логирования
                        $variationAttributeRows = DB::table('shop_variation_attributes_values as vav')
                            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                            ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                            ->where('vav.variation_id', $existingVariation->id)
                            ->select('a.name as attribute_name', 'av.value as value_value')
                            ->get();

                        foreach ($variationAttributeRows as $row) {
                            $variationAttributes[] = [
                                'name' => $row->attribute_name,
                                'value' => $row->value_value
                            ];
                        }

                        $details = "Найдена по имени: {$name}";
                        if ($sheet !== 'неизвестно') {
                            $details .= ", Лист: {$sheet}, Строка: {$count}";
                        }

                        $this->importLogService->logVariation(
                            'ОБНОВЛЕНА ВАРИАЦИЯ ПО ИМЕНИ',
                            $existingGood->name ?? $name,
                            $existingGood->sku ?? 'нет SKU',
                            $variationAttributes,
                            $existingVariation->id,
                            $details
                        );
                        
                        // Применяем обрезку названия к существующему товару, если указан символ обрезки
                        if ($nameTrimSymbol && !empty(trim($nameTrimSymbol)) && $existingGood->name && !in_array('name', $immutableFields) && !$searchByNameInVariations) {
                            $trimSymbol = trim($nameTrimSymbol);
                            $currentName = $existingGood->name;
                            $trimIndex = strpos($currentName, $trimSymbol);
                            if ($trimIndex !== false) {
                                $trimmedName = trim(substr($currentName, 0, $trimIndex));
                                if (!empty($trimmedName) && $trimmedName !== $currentName) {
                                    $goodData['name'] = $trimmedName;
                                }
                            }
                        }

                        // Обновляем товар, если нужно
                        if ($duplicateAction === 'update') {
                            // Проверяем поле для удаления дубликатов
                            $duplicateCheckField = $request->input('duplicate_check_field');

                            // Проверяем уникальность значения, которое будет установлено товару
                            if (!empty($duplicateCheckField) && isset($goodData[$duplicateCheckField])) {
                                $fieldValue = $goodData[$duplicateCheckField];

                                // Проверяем, есть ли уже товар с таким значением поля (включая текущий товар)
                                $existingGoodWithField = ShopGood::where($duplicateCheckField, $fieldValue)->first();

                                // Если найден товар с таким значением поля, и это НЕ текущий товар
                                if ($existingGoodWithField && $existingGoodWithField->id !== $existingGood->id) {
                                    // Удаляем дубликат товара
                                    try {
                                        // Удаляем связанные данные
                                        $existingGoodWithField->categories()->detach();
                                        $existingGoodWithField->brands()->detach();
                                        $existingGoodWithField->tags()->detach();
                                        $existingGoodWithField->properties()->detach();
                                        $existingGoodWithField->images()->delete();
                                        $existingGoodWithField->variations()->delete();

                                        // Удаляем сам товар
                                        $existingGoodWithField->delete();

                                        } catch (\Exception $deleteException) {
                                        Log::error("Ошибка при удалении дубликата товара", [
                                            'duplicate_good_id' => $existingGoodWithField->id,
                                            'error' => $deleteException->getMessage()
                                        ]);
                                        // Продолжаем импорт, но логируем ошибку
                                    }
                                } else {
                                    }
                            }


                            $updateResult = $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $immutableFields, $searchByNameInVariations, $hasVariation, $supplierStockFields);
                            $results['imagesDownloaded'] += $updateResult['imageStats']['downloaded'];
                            $results['imagesFailed'] += $updateResult['imageStats']['failed'];

                            // Для логики "Изменить вариацию" также обновляем остатки сопоставленных вариаций
                            if (isset($goodData['variation_ids']) && is_array($goodData['variation_ids'])) {
                                foreach ($goodData['variation_ids'] as $variationId) {
                                    $variation = ShopGoodVariation::where('id', $variationId)
                                        ->where('good_id', $existingGood->id)
                                        ->first();

                                    if ($variation) {
                                        // Обновляем остатки вариации данными из товара с конвертацией типов
                                        if (isset($goodData['stock_quantity']) && !in_array('stock_quantity', $immutableFields)) {
                                            $variation->stock_quantity = is_numeric($goodData['stock_quantity']) ? (float)$goodData['stock_quantity'] : 0;
                                        }
                                        if (isset($goodData['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                                            $variation->remote_stock_quantity = $goodData['remote_stock_quantity'] !== null && is_numeric($goodData['remote_stock_quantity']) ? (string)$goodData['remote_stock_quantity'] : $goodData['remote_stock_quantity'];
                                        }
                                        if (isset($goodData['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                                            $variation->fast_remote_stock_quantity = $goodData['fast_remote_stock_quantity'] !== null && is_numeric($goodData['fast_remote_stock_quantity']) ? (string)$goodData['fast_remote_stock_quantity'] : $goodData['fast_remote_stock_quantity'];
                                        }

                                        $variation->save();

                                        }
                                }
                            }
                        }
                        
                        // Обрабатываем категории товара
                        // ВАЖНО: При обновлении товара нужно проверить существующие категории ПЕРЕД применением категорий из файла
                        // Если категория была применена по умолчанию в applyCategoryAndBrandIds, но у товара уже есть категории - не перезаписываем
                        $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();
                        $categoryIds = [];
                        
                        if (isset($goodData['category']) && !empty($goodData['category'])) {
                            $categoryIds = [(int)$goodData['category']];
                        } elseif (isset($goodData['categories']) && is_array($goodData['categories']) && !empty($goodData['categories'])) {
                            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function($id) {
                                return $id > 0;
                            });
                        }

                        // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
                        // Если единственная категория совпадает с defaultCategory, и у товара уже есть другие категории - не перезаписываем
                        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                            $defaultCategoryId = (int)$defaultCategory;
                            // Если единственная категория - это defaultCategory, и у товара уже есть другие категории
                            if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                // Вероятно, категория была применена по умолчанию - оставляем существующие категории
                                $categoryIds = $existingCategoryIds;
                            }
                        } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                            // Если категорий нет в файле, но включена категория по умолчанию
                            // При обновлении товара: применяем категорию по умолчанию только если у товара нет категорий
                            if (empty($existingCategoryIds)) {
                                // У товара нет категорий - применяем категорию по умолчанию
                                $categoryIds = [(int)$defaultCategory];
                            } else {
                                // У товара уже есть категории - оставляем их без изменений
                                $categoryIds = $existingCategoryIds;
                            }
                        }
                        
                        // Синхронизируем категории только если они были указаны в файле или применена категория по умолчанию
                        // Если категорий нет и категория по умолчанию не применялась - не трогаем существующие категории
                        if (!empty($categoryIds)) {
                            $existingGood->categories()->sync($categoryIds);
                        }
                        // Если $categoryIds пустой и категория по умолчанию не применялась - не вызываем sync, оставляем существующие категории
                        
                        $results['updated']++;
                        
                        // Сохраняем ID товара
                        if (!empty($sku)) {
                            $results['goodIds'][$sku] = $existingGood->id;
                        }
                        
                        if (isset($goodData['_row'])) {
                            $results['goodIds'][$goodData['_row']] = $existingGood->id;
                        }
                        
                        // Сохраняем ID вариации для связи с изображениями
                        if ($variationId) {
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }

                            // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                            // когда несколько вариаций имеют одинаковый SKU
                            if (isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // Сортируем атрибуты для консистентности ключа
                                $sortedAttributes = collect($attributes)->sortBy(function($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;
                            }
                        }
                        
                        // Добавляем в группу для обновления
                        $sheet = $goodData['_sheet'] ?? 'неизвестно';
                        $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id];
                        
                        continue; // Пропускаем дальнейшую обработку
                    }

                    // Логируем текущие значения переменных для отладки

                    // Если включен поиск в вариациях и товар имеет вариации, но вариация не найдена,
                    // а товар найден - создаем новую вариацию для существующего товара
                    if ($searchByNameInVariations && $hasVariation && !$existingVariation && $existingGood) {

                        // Создаем новую вариацию для найденного товара
                        $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields);

                        // Сохраняем ID вариации для связи с изображениями
                        if ($variationId) {
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }

                            // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                            // когда несколько вариаций имеют одинаковый SKU
                            if (isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // Сортируем атрибуты для консистентности ключа
                                $sortedAttributes = collect($attributes)->sortBy(function($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;
                            }
                        }


                        // Обрабатываем категории товара (даже если обрабатывается только вариация)
                        $categoryIds = [];
                        if (isset($goodData['category']) && !empty($goodData['category'])) {
                            $categoryIds = [(int)$goodData['category']];
                        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function($id) {
                                return $id > 0;
                            });
                        }

                        // Обрабатываем категории товара при обновлении через вариацию
                        // ВАЖНО: Проверяем существующие категории ПЕРЕД применением категорий из файла
                        $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();

                        // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
                        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                            $defaultCategoryId = (int)$defaultCategory;
                            // Если единственная категория - это defaultCategory, и у товара уже есть другие категории
                            if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                // Вероятно, категория была применена по умолчанию - оставляем существующие категории
                                $categoryIds = $existingCategoryIds;
                            }
                        } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                            // Если категорий нет в файле, но включена категория по умолчанию
                            // При обновлении товара: применяем категорию по умолчанию только если у товара нет категорий
                            if (empty($existingCategoryIds)) {
                                // У товара нет категорий - применяем категорию по умолчанию
                                $categoryIds = [(int)$defaultCategory];
                            } else {
                                // У товара уже есть категории - оставляем их без изменений
                                $categoryIds = $existingCategoryIds;
                            }
                        }

                        // Синхронизируем категории только если они были указаны в файле или применена категория по умолчанию
                        // Если категорий нет и категория по умолчанию не применялась - не трогаем существующие категории
                        if (!empty($categoryIds)) {
                            $existingGood->categories()->sync($categoryIds);
                        }
                        // Если $categoryIds пустой и категория по умолчанию не применялась - не вызываем sync, оставляем существующие категории

                        $results['updated']++; // Считаем как обновление (добавление вариации)

                        // Сохраняем ID товара
                        if (!empty($sku)) {
                            $results['goodIds'][$sku] = $existingGood->id;
                        }

                        if (isset($goodData['_row'])) {
                            $results['goodIds'][$goodData['_row']] = $existingGood->id;
                        }

                        // Сохраняем ID вариации для связи с изображениями
                        if ($variationId) {
                            // Используем _row как ключ для связи с изображениями (самый надежный способ)
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }

                            // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                            // когда несколько вариаций имеют одинаковый SKU
                            if (isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // Сортируем атрибуты для консистентности ключа
                                $sortedAttributes = collect($attributes)->sortBy(function($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;
                            }
                        }

                        // Добавляем в группу для обновления
                        $sheet = $goodData['_sheet'] ?? 'неизвестно';
                        $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id];

                        continue; // Пропускаем дальнейшую обработку
                    }

                    if ($existingGood) {
                        // Товар существует
                        // Если у строки есть вариация, обрабатываем её независимо от duplicateAction
                        if ($hasVariation) {
                            // Обрабатываем только вариацию, но также обновляем категории товара, если нужно
                            $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields);
                            
                            // Обрабатываем категории товара (даже если обрабатывается только вариация)
                            $categoryIds = [];
                            if (isset($goodData['category']) && !empty($goodData['category'])) {
                                $categoryIds = [(int)$goodData['category']];
                            } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                                $categoryIds = array_filter(array_map('intval', $goodData['categories']), function($id) {
                                    return $id > 0;
                                });
                            }

                            // Обрабатываем категории товара при обновлении через вариацию
                            // ВАЖНО: Проверяем существующие категории ПЕРЕД применением категорий из файла
                            $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();
                            
                            // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
                            if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                                $defaultCategoryId = (int)$defaultCategory;
                                // Если единственная категория - это defaultCategory, и у товара уже есть другие категории
                                if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                    // Вероятно, категория была применена по умолчанию - оставляем существующие категории
                                    $categoryIds = $existingCategoryIds;
                                }
                            } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                                // Если категорий нет в файле, но включена категория по умолчанию
                                // При обновлении товара: применяем категорию по умолчанию только если у товара нет категорий
                                if (empty($existingCategoryIds)) {
                                    // У товара нет категорий - применяем категорию по умолчанию
                                    $categoryIds = [(int)$defaultCategory];
                                } else {
                                    // У товара уже есть категории - оставляем их без изменений
                                    $categoryIds = $existingCategoryIds;
                                }
                            }
                            
                            // Синхронизируем категории только если они были указаны в файле или применена категория по умолчанию
                            // Если категорий нет и категория по умолчанию не применялась - не трогаем существующие категории
                            if (!empty($categoryIds)) {
                                $existingGood->categories()->sync($categoryIds);
                            }
                            // Если $categoryIds пустой и категория по умолчанию не применялась - не вызываем sync, оставляем существующие категории
                            
                            $results['updated']++; // Считаем как обновление (добавление вариации)
                            
                            // Сохраняем ID товара
                            if (!empty($sku)) {
                                $results['goodIds'][$sku] = $existingGood->id;
                            }
                            
                            if (isset($goodData['_row'])) {
                                $results['goodIds'][$goodData['_row']] = $existingGood->id;
                            }
                            
                            // Сохраняем ID вариации для связи с изображениями
                            if ($variationId) {
                                // Используем _row как ключ для связи с изображениями (самый надежный способ)
                                if (isset($goodData['_row'])) {
                                    $results['variationIds'][$goodData['_row']] = $variationId;
                                }

                                // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                                // когда несколько вариаций имеют одинаковый SKU
                                if (isset($goodData['sku']) && !empty($goodData['sku']) &&
                                    isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                    is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0) {

                                    $sku = trim($goodData['sku']);
                                    $attributes = $goodData['variation']['attributes'];

                                    // Сортируем атрибуты для консистентности ключа
                                    $sortedAttributes = collect($attributes)->sortBy(function($attr) {
                                        return $attr['name'] ?? '';
                                    })->map(function($attr) {
                                        return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                    })->join('|');

                                    $variationKey = $sku . ':::' . $sortedAttributes;
                                    $results['variationIds'][$variationKey] = $variationId;
                                }
                            }
                            
                            // Добавляем в группу для обновления
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id];
                        } elseif ($duplicateAction === 'update') {
                            // Применяем обрезку названия к существующему товару, если указан символ обрезки
                            if ($nameTrimSymbol && !empty(trim($nameTrimSymbol)) && $existingGood->name && !in_array('name', $immutableFields) && !$searchByNameInVariations) {
                                $trimSymbol = trim($nameTrimSymbol);
                                $currentName = $existingGood->name;
                                $trimIndex = strpos($currentName, $trimSymbol);
                                if ($trimIndex !== false) {
                                    $trimmedName = trim(substr($currentName, 0, $trimIndex));
                                    if (!empty($trimmedName) && $trimmedName !== $currentName) {
                                        $goodData['name'] = $trimmedName;
                                        }
                                }
                            }

                            // Проверяем поле для удаления дубликатов (для варианта 2)
                            $duplicateCheckField = $request->input('duplicate_check_field');

                            // Проверяем уникальность значения, которое будет установлено товару
                            if (!empty($duplicateCheckField) && isset($goodData[$duplicateCheckField])) {
                                $fieldValue = $goodData[$duplicateCheckField];

                                // Проверяем, есть ли уже товар с таким значением поля (включая текущий товар)
                                $existingGoodWithField = ShopGood::where($duplicateCheckField, $fieldValue)->first();

                                // Если найден товар с таким значением поля, и это НЕ текущий товар
                                if ($existingGoodWithField && $existingGoodWithField->id !== $existingGood->id) {
                                    // Удаляем дубликат товара
                                    try {
                                        // Удаляем связанные данные
                                        $existingGoodWithField->categories()->detach();
                                        $existingGoodWithField->brands()->detach();
                                        $existingGoodWithField->tags()->detach();
                                        $existingGoodWithField->properties()->detach();
                                        $existingGoodWithField->images()->delete();
                                        $existingGoodWithField->variations()->delete();

                                        // Удаляем сам товар
                                        $existingGoodWithField->delete();

                                        } catch (\Exception $deleteException) {
                                        Log::error("Ошибка при удалении дубликата товара (вариант 2)", [
                                            'duplicate_good_id' => $existingGoodWithField->id,
                                            'error' => $deleteException->getMessage()
                                        ]);
                                        // Продолжаем импорт, но логируем ошибку
                                    }
                                } else {
                                    }
                            }


                            $updateResult = $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $immutableFields, $searchByNameInVariations, $hasVariation, $supplierStockFields);
                            $results['imagesDownloaded'] += $updateResult['imageStats']['downloaded'];
                            $results['imagesFailed'] += $updateResult['imageStats']['failed'];

                            // Для логики "Изменить вариацию" также обновляем остатки сопоставленных вариаций
                            if (isset($goodData['variation_ids']) && is_array($goodData['variation_ids'])) {
                                foreach ($goodData['variation_ids'] as $variationId) {
                                    $variation = ShopGoodVariation::where('id', $variationId)
                                        ->where('good_id', $existingGood->id)
                                        ->first();

                                    if ($variation) {
                                        // Обновляем остатки вариации данными из товара с конвертацией типов
                                        if (isset($goodData['stock_quantity']) && !in_array('stock_quantity', $immutableFields)) {
                                            $variation->stock_quantity = is_numeric($goodData['stock_quantity']) ? (float)$goodData['stock_quantity'] : 0;
                                        }

                                        if (isset($goodData['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                                            $variation->remote_stock_quantity = $goodData['remote_stock_quantity'] !== null && is_numeric($goodData['remote_stock_quantity']) ? (string)$goodData['remote_stock_quantity'] : $goodData['remote_stock_quantity'];
                                        }

                                        if (isset($goodData['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                                            $variation->fast_remote_stock_quantity = $goodData['fast_remote_stock_quantity'] !== null && is_numeric($goodData['fast_remote_stock_quantity']) ? (string)$goodData['fast_remote_stock_quantity'] : $goodData['fast_remote_stock_quantity'];
                                        }

                                        $variation->save();

                                        }
                                }
                            }

                            $results['updated']++;
                            
                            // Сохраняем ID товара
                            // Если SKU не пустой, сохраняем по SKU
                            if (!empty($sku)) {
                                $results['goodIds'][$sku] = $existingGood->id;
                            }
                            
                            // Также добавляем ID по _row для связи с изображениями
                            if (isset($goodData['_row'])) {
                                $results['goodIds'][$goodData['_row']] = $existingGood->id;
                            }
                            
                            // Добавляем в группу для обновления
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id];
                        } else {
                            $results['skipped']++;
                            
                            // Добавляем в группу для пропуска
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $foundByText = !empty($foundByFields) ? ' (найден по: ' . implode(', ', $foundByFields) . ')' : '';
                            $reason = 'Дубликат (настройка: пропустить)' . $foundByText . '. Найденный товар ID: ' . $existingGood->id . ', SKU: "' . ($existingGood->sku ?? 'пустой') . '", название: "' . $existingGood->name . '"';
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];
                        }
                    } else {
                        // Товар не найден при первоначальном поиске
                        // Если есть вариация, делаем дополнительную проверку перед созданием товара
                        // чтобы избежать ошибки дублирования SKU
                        if ($hasVariation) {
                            // Дополнительная проверка: ищем товар еще раз более тщательно
                            // перед созданием, чтобы избежать дублирования
                            $doubleCheckGood = null;
                            
                            // Проверяем по SKU (если указан)
                            if (!empty($sku)) {
                                $doubleCheckGood = ShopGood::where('sku', $sku)->first();
                            }
                            
                            // Если не найден по SKU, проверяем по имени
                            if (!$doubleCheckGood) {
                                $doubleCheckGood = ShopGood::where('name', $name)->first();
                            }
                            
                            // Если все еще не найден, проверяем по имени с пустым SKU
                            if (!$doubleCheckGood && empty($sku)) {
                                $doubleCheckGood = ShopGood::whereNull('sku')->where('name', $name)->first();
                            }
                            
                            if ($doubleCheckGood) {
                                // Товар найден при дополнительной проверке - обрабатываем только вариацию
                                $variationId = $this->processVariation($doubleCheckGood, $goodData['variation'], $goodData, $supplierStockFields);
                                
                                // Обрабатываем категории товара (даже если обрабатывается только вариация)
                                $categoryIds = [];
                                if (isset($goodData['category']) && !empty($goodData['category'])) {
                                    $categoryIds = [(int)$goodData['category']];
                                } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                                    $categoryIds = array_filter(array_map('intval', $goodData['categories']), function($id) {
                                        return $id > 0;
                                    });
                                }

                                // Обрабатываем категории товара при двойной проверке
                                // ВАЖНО: Проверяем существующие категории ПЕРЕД применением категорий из файла
                                $existingCategoryIds = $doubleCheckGood->categories()->pluck('shop_categories.id')->toArray();
                                
                                // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
                                if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                                    $defaultCategoryId = (int)$defaultCategory;
                                    // Если единственная категория - это defaultCategory, и у товара уже есть другие категории
                                    if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                        // Вероятно, категория была применена по умолчанию - оставляем существующие категории
                                        $categoryIds = $existingCategoryIds;
                                    }
                                } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                                    // Если категорий нет в файле, но включена категория по умолчанию
                                    // При обновлении товара: применяем категорию по умолчанию только если у товара нет категорий
                                    if (empty($existingCategoryIds)) {
                                        // У товара нет категорий - применяем категорию по умолчанию
                                        $categoryIds = [(int)$defaultCategory];
                                    } else {
                                        // У товара уже есть категории - оставляем их без изменений
                                        $categoryIds = $existingCategoryIds;
                                    }
                                }
                                
                                // Синхронизируем категории только если они были указаны в файле или применена категория по умолчанию
                                // Если категорий нет и категория по умолчанию не применялась - не трогаем существующие категории
                                if (!empty($categoryIds)) {
                                    $doubleCheckGood->categories()->sync($categoryIds);
                                }
                                // Если $categoryIds пустой и категория по умолчанию не применялась - не вызываем sync, оставляем существующие категории
                                
                                $results['updated']++; // Считаем как обновление (добавление вариации)
                                
                                // Сохраняем ID товара
                                if (!empty($sku)) {
                                    $results['goodIds'][$sku] = $doubleCheckGood->id;
                                }
                                
                                if (isset($goodData['_row'])) {
                                    $results['goodIds'][$goodData['_row']] = $doubleCheckGood->id;
                                }

                                // Сохраняем ID вариации для связи с изображениями
                                if ($variationId) {
                                    if (isset($goodData['_row'])) {
                                        $results['variationIds'][$goodData['_row']] = $variationId;
                                    }

                                    // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                                    // когда несколько вариаций имеют одинаковый SKU
                                    if (isset($goodData['sku']) && !empty($goodData['sku']) &&
                                        isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                        is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0) {

                                        $sku = trim($goodData['sku']);
                                        $attributes = $goodData['variation']['attributes'];

                                        // Сортируем атрибуты для консистентности ключа
                                        $sortedAttributes = collect($attributes)->sortBy(function($attr) {
                                            return $attr['name'] ?? '';
                                        })->map(function($attr) {
                                            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                        })->join('|');

                                        $variationKey = $sku . ':::' . $sortedAttributes;
                                        $results['variationIds'][$variationKey] = $variationId;
                                    }
                                }

                                // Добавляем в группу для обновления
                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $doubleCheckGood->id];
                                
                                // Пропускаем создание товара, так как он уже существует
                                continue;
                            }
                        }

                        // Проверяем режим "Только обновлять"
                        $onlyUpdateMode = $request->input('only_update_mode', false);
                        if ($onlyUpdateMode) {
                            // В режиме "Только обновлять" пропускаем создание новых товаров
                            $results['skipped']++;
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $searchDetails = [];
                            if (!empty($duplicateFields)) {
                                $searchDetails[] = 'поля поиска: ' . implode(', ', $duplicateFields);
                            }
                            if (!empty($sku)) {
                                $searchDetails[] = 'SKU: "' . $sku . '"';
                            }
                            if (!empty($name)) {
                                $searchDetails[] = 'название: "' . $name . '"';
                            }
                            $searchText = !empty($searchDetails) ? ' (' . implode(', ', $searchDetails) . ')' : '';
                            $reason = 'Режим "только обновлять" - товар не найден в базе данных' . $searchText . '. Включен поиск по полям: ' . implode(', ', $duplicateFields);
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];
                            continue;
                        }

                        // Товар действительно не существует - создаем новый (с вариацией или без)
                        $createResult = $this->createGood($goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $supplierStockFields);
                        $newGood = $createResult['good'];
                        $results['imagesDownloaded'] += $createResult['imageStats']['downloaded'];
                        $results['imagesFailed'] += $createResult['imageStats']['failed'];
                        $results['imported']++;

                        // Сохраняем ID товара
                        // Если SKU не пустой, сохраняем по SKU
                        if (!empty($sku)) {
                            $results['goodIds'][$sku] = $newGood->id;
                        }

                        // Также добавляем ID по _row для связи с изображениями
                        if (isset($goodData['_row'])) {
                            $results['goodIds'][$goodData['_row']] = $newGood->id;
                        }
                        
                        // Сохраняем ID вариации, если она была создана
                        if (isset($newGood->lastVariationId) && $newGood->lastVariationId) {
                            // Используем _row как ключ для связи с изображениями (самый надежный способ)
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $newGood->lastVariationId;
                            }

                            // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                            // когда несколько вариаций имеют одинаковый SKU
                            if (isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // Сортируем атрибуты для консистентности ключа
                                $sortedAttributes = collect($attributes)->sortBy(function($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $newGood->lastVariationId;
                            }
                        }
                        
                        // Добавляем в группу для загрузки
                        $sheet = $goodData['_sheet'] ?? 'неизвестно';
                        $loadItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet];
                    }
                } catch (\Exception $e) {
                    // Проверяем, не является ли это ошибкой дублирования при создании товара с вариацией
                    $isDuplicateError = strpos($e->getMessage(), 'Duplicate entry') !== false || 
                                       strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                                       strpos($e->getMessage(), 'duplicate') !== false ||
                                       $e->getCode() == 23000;
                    
                    // Если есть вариация и ошибка дублирования - это значит товар уже существует
                    // Нужно найти его и обработать вариацию
                    if ($hasVariation && $isDuplicateError && !$existingGood) {
                        // Пробуем найти товар более тщательно
                        if (!empty($sku)) {
                            $existingGood = ShopGood::where('sku', $sku)->first();
                        }
                        if (!$existingGood) {
                            $existingGood = ShopGood::where('name', $name)->first();
                        }
                        if (!$existingGood && empty($sku)) {
                            $existingGood = ShopGood::whereNull('sku')->where('name', $name)->first();
                        }
                        
                        if ($existingGood) {
                            // Товар найден - обрабатываем только вариацию
                            try {
                                $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields);
                                $results['updated']++; // Считаем как обновление (добавление вариации)
                                
                                // Сохраняем ID товара
                                if (!empty($sku)) {
                                    $results['goodIds'][$sku] = $existingGood->id;
                                }
                                
                                if (isset($goodData['_row'])) {
                                    $results['goodIds'][$goodData['_row']] = $existingGood->id;
                                }

                                // Сохраняем ID вариации для связи с изображениями
                                if ($variationId) {
                                    if (isset($goodData['_row'])) {
                                        $results['variationIds'][$goodData['_row']] = $variationId;
                                    }

                                    // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                                    // когда несколько вариаций имеют одинаковый SKU
                                    if (isset($goodData['sku']) && !empty($goodData['sku']) &&
                                        isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                        is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0) {

                                        $sku = trim($goodData['sku']);
                                        $attributes = $goodData['variation']['attributes'];

                                        // Сортируем атрибуты для консистентности ключа
                                        $sortedAttributes = collect($attributes)->sortBy(function($attr) {
                                            return $attr['name'] ?? '';
                                        })->map(function($attr) {
                                            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                        })->join('|');

                                        $variationKey = $sku . ':::' . $sortedAttributes;
                                        $results['variationIds'][$variationKey] = $variationId;
                                    }
                                }
                                
                                // Добавляем в группу для обновления
                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id];
                                
                                // Пропускаем обработку ошибки, так как вариация успешно обработана
                                continue;
                            } catch (\Exception $variationError) {
                                // Если обработка вариации тоже не удалась - логируем ошибку
                                $results['failed']++;
                                $results['errors'][] = [
                                    'row' => $count,
                                    'sku' => $sku,
                                    'error' => 'Товар найден, но не удалось обработать вариацию: ' . $variationError->getMessage()
                                ];
                                
                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $errorItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'error' => $variationError->getMessage()];
                                continue;
                            }
                        }
                    }
                    
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

            $response = [
                'success' => true,
                'message' => 'Импорт завершен',
                'results' => $results
                // 'backup_id' => $backupId // временно отключено
            ];

            // Добавляем статистику обнуления, если она была выполнена
            if ($resetStats !== null) {
                $resetFieldsList = isset($resetStats['reset_fields']) ? implode(', ', $resetStats['reset_fields']) : '';
                $response['reset_stats'] = [
                    'supplier_name' => $resetStats['supplier_name'],
                    'updated_goods' => $resetStats['updated_goods'],
                    'updated_variations' => $resetStats['updated_variations'],
                    'reset_fields' => $resetStats['reset_fields'] ?? [],
                    'message' => "Обнулены остатки {$resetStats['updated_goods']} товаров и {$resetStats['updated_variations']} вариаций поставщика '{$resetStats['supplier_name']}'"
                ];
            }

            return response()->json($response);

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


    private function createGood($goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory = null, $useDefaultCategory = false, $supplierStockFields = null)
    {
        $imageStats = ['downloaded' => 0, 'failed' => 0];

        $good = new ShopGood();
        // Если SKU пустой, устанавливаем null вместо пустой строки
        $good->sku = !empty($goodData['sku']) ? $goodData['sku'] : null;
        $good->name = $goodData['name'];
        // Используем slug из данных, если он есть и не пустой, иначе генерируем автоматически
        if (!empty($goodData['slug']) && trim($goodData['slug']) !== '') {
            $good->slug = trim($goodData['slug']);
        } else {
            $good->slug = $this->generateSlug($goodData['name'], $goodData['sku']);
        }
        $good->description = $goodData['description'] ?? null;
        $good->short_description = $goodData['short_description'] ?? null;

        // Для товаров с вариациями устанавливаем временные значения цен и остатков перед сохранением
        // После обработки вариаций значения будут обновлены
        if (!isset($goodData['variation']) || !is_array($goodData['variation'])) {
            // Применяем модификацию цены
            $priceModification = $goodData['price_modification'] ?? null;
            $good->price = $this->applyPriceModification($goodData['price'] ?? 0, $priceModification['regular'] ?? null);
            // Применяем модификацию акционной цены (даже если sale_price не передана в файле, но есть модификация)
            if (isset($priceModification) && isset($priceModification['sale'])) {
                $good->sale_price = $this->applySalePriceModification($goodData, $priceModification);
            } else {
                $good->sale_price = $goodData['sale_price'] ?? null;
            }
            // Устанавливаем остатки из данных импорта
            if ((isset($goodData['stock_quantity']) || isset($goodData['stock']))) {
                $stockValue = is_numeric($goodData['stock_quantity'] ?? $goodData['stock'] ?? 0) ? (float)($goodData['stock_quantity'] ?? $goodData['stock'] ?? 0) : 0;
                $good->stock_quantity = $stockValue;
            }

            if (isset($goodData['remote_stock_quantity'])) {
                $remoteValue = ($goodData['remote_stock_quantity'] ?? null) !== null && is_numeric($goodData['remote_stock_quantity'] ?? null) ? (string)($goodData['remote_stock_quantity'] ?? null) : ($goodData['remote_stock_quantity'] ?? null);
                $good->remote_stock_quantity = $remoteValue;
            }

            if (isset($goodData['fast_remote_stock_quantity'])) {
                $fastRemoteValue = ($goodData['fast_remote_stock_quantity'] ?? null) !== null && is_numeric($goodData['fast_remote_stock_quantity'] ?? null) ? (string)($goodData['fast_remote_stock_quantity'] ?? null) : ($goodData['fast_remote_stock_quantity'] ?? null);
                $good->fast_remote_stock_quantity = $fastRemoteValue;
            }
        } else {
            // Для товаров с вариациями устанавливаем временные значения (будут обновлены после создания вариации)
            $good->price = 0; // Временное значение, будет обновлено после обработки вариации
            $good->sale_price = null;

            // Остатки НЕ устанавливаем - они будут взяты из вариаций или оставлены без изменений
        }

        $good->weight = $goodData['weight'] ?? 0;
        $good->width = $goodData['width'] ?? 0;
        $good->height = $goodData['height'] ?? 0;
        // Поддерживаем и depth, и length для обратной совместимости
        $good->depth = $goodData['depth'] ?? $goodData['length'] ?? 0;
        $good->is_active = $goodData['is_active'] ?? true;
        $good->is_featured = $goodData['is_featured'] ?? false;
        $good->meta_title = $goodData['meta_title'] ?? null;
        $good->meta_description = $goodData['meta_description'] ?? null;
        
        // Устанавливаем поставщика (текстовое поле), если передан в запросе
        // И создаем запись в таблице shop_suppliers, если её нет
        if (isset($goodData['supplier_name']) && $goodData['supplier_name'] !== null && trim($goodData['supplier_name']) !== '') {
            $supplierName = trim($goodData['supplier_name']);
            $good->supplier = $supplierName;
            
            // Находим или создаем поставщика в таблице shop_suppliers
            $supplier = ShopSupplier::firstOrCreate(
                ['name' => $supplierName],
                [
                    'slug' => \Illuminate\Support\Str::slug($supplierName),
                    'is_active' => true,
                    'sort_order' => 0
                ]
            );
            
            }
        
        $good->save();

        // Обрабатываем категории
        // ВАЖНО: категории уже обработаны в applyCategoryAndBrandIds, где:
        // - Категории из файла найдены/созданы и преобразованы в ID
        // - Категория по умолчанию применена, если нужно
        // Здесь мы просто извлекаем уже обработанные категории
        $categoryIds = [];

        if (isset($goodData['category']) && !empty($goodData['category'])) {
            // Одиночная категория - значение уже должно быть ID после applyCategoryAndBrandIds
            $categoryIds = [(int)$goodData['category']];
        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
            // Множественные категории - значения уже должны быть ID после applyCategoryAndBrandIds
            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function($id) {
                return $id > 0;
            });
        }

        // Синхронизируем категории (уже обработанные, включая категорию по умолчанию, если она была применена)
        if (!empty($categoryIds)) {
            $good->categories()->sync($categoryIds);
        } else {
            // Если категорий нет, отвязываем все категории
            $good->categories()->sync([]);
        }

        // Обрабатываем бренды
        if (isset($goodData['brand']) && is_numeric($goodData['brand'])) {
            // Одиночный бренд по ID
            $good->brands()->sync([$goodData['brand']]);
        } elseif (isset($goodData['brands']) && is_array($goodData['brands'])) {
            // Множественные бренды - ID уже применены в applyCategoryAndBrandIds
            $brandIds = array_filter($goodData['brands'], 'is_numeric');
            $good->brands()->sync($brandIds);
        }

        // Обрабатываем изображения
        if (isset($goodData['images']) && is_array($goodData['images'])) {
            $imageStats = $this->processImages($good, $goodData['images']);
        }

        // Обрабатываем свойства товаров
        if (isset($goodData['properties']) && is_array($goodData['properties'])) {
            $this->processProperties($good, $goodData['properties']);
        }

        // Обрабатываем вариации товара
        $variationId = null;
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            $variationId = $this->processVariation($good, $goodData['variation'], $goodData, $supplierStockFields);

            if ($variationId) {
                // Для товаров с вариациями устанавливаем цены и остатки из вариации с учетом настроек поставщика
                // Обычно берем данные из первой вариации или оставляем пустыми (null)
                $good->price = $goodData['variation']['price'] ?? 0;
                $good->sale_price = $goodData['variation']['sale_price'] ?? null;


                // Обновляем остатки товарами из вариации
                $stockValue = is_numeric($goodData['variation']['stock_quantity'] ?? 0) ? (float)($goodData['variation']['stock_quantity'] ?? 0) : 0;
                $good->stock_quantity = $stockValue;

                $remoteValue = $goodData['variation']['remote_stock_quantity'] ?? null;
                // Обновляем только если значение не пустое
                if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                    $good->remote_stock_quantity = is_numeric($remoteValue) ? (string)$remoteValue : $remoteValue;
                }

                $fastRemoteValue = $goodData['variation']['fast_remote_stock_quantity'] ?? null;
                // Обновляем только если значение не пустое
                if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                    $good->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string)$fastRemoteValue : $fastRemoteValue;
                }

                $good->save(); // Сохраняем обновленные данные после обработки вариации
                }
        } else {
            }

        // Сохраняем ID вариации в объекте товара для последующего использования
        if ($variationId) {
            $good->lastVariationId = $variationId;
        }

        return ['good' => $good, 'imageStats' => $imageStats];
    }

    private function updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory = null, $useDefaultCategory = false, $immutableFields = [], $searchByNameInVariations = false, $hasVariation = false, $supplierStockFields = null)
    {
        $imageStats = ['downloaded' => 0, 'failed' => 0];

        // Обновляем все поля из goodData, которые переданы в импорте
        // Это позволяет обновлять все поля, отмеченные для импорта, а не только те, что используются для поиска
        
        // Обновляем SKU (артикул) - если передан и не в списке неизменяемых, обновляем
        if (isset($goodData['sku']) && !in_array('sku', $immutableFields)) {
            $existingGood->sku = !empty($goodData['sku']) ? $goodData['sku'] : null;
        }
        
        // Обновляем название только если оно не в списке неизменяемых полей
        // Обновляем название только если оно передано, не пустое и не в списке неизменяемых
        // Если включен поиск по именам в вариациях, поле name нельзя изменять
        if (isset($goodData['name']) && !empty(trim($goodData['name'])) && !in_array('name', $immutableFields) && !$searchByNameInVariations) {
            $nameToSet = $goodData['name'];

            // Применяем обрезку названия к существующему товару, если указан символ обрезки
            // Это применяется ко всем товарам, а не только к тем, которые имеют variation_ids
            if (!empty($nameTrimSymbol) && !in_array('name', $immutableFields) && !$searchByNameInVariations) {
                $trimSymbol = trim($nameTrimSymbol);
                $currentName = $existingGood->name;
                $trimIndex = strpos($currentName, $trimSymbol);
                if ($trimIndex !== false) {
                    $trimmedName = trim(substr($currentName, 0, $trimIndex));
                    if (!empty($trimmedName) && $trimmedName !== $currentName) {
                        $nameToSet = $trimmedName;
                        }
                }
            }

            $existingGood->name = $nameToSet;
        }
        // Обновляем slug только если он явно передан в данных (выбран в маппинге) и не в списке неизменяемых
        // При обновлении существующей записи не создаем slug автоматически
        if (isset($goodData['slug']) && !in_array('slug', $immutableFields)) {
            if (!empty($goodData['slug']) && trim($goodData['slug']) !== '') {
                $existingGood->slug = trim($goodData['slug']);
            }
            // Если slug передан, но пустой, не обновляем существующий slug
        }
        // Если slug не передан в данных (не выбран в маппинге), оставляем существующий slug без изменений

        // Обновляем описание только если оно передано и не в списке неизменяемых
        if (isset($goodData['description']) && !in_array('description', $immutableFields)) {
            $existingGood->description = $goodData['description'];
        }

        // Обновляем короткое описание только если оно передано и не в списке неизменяемых
        if (isset($goodData['short_description']) && !in_array('short_description', $immutableFields)) {
            $existingGood->short_description = $goodData['short_description'];
        }

        // Применяем модификацию цены (только если поле не в списке неизменяемых)
        $priceModification = $goodData['price_modification'] ?? null;

        // Для товаров с вариациями цены берутся из вариации
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            // Обновляем цены из вариации
            if (isset($goodData['variation']['price']) && !in_array('price', $immutableFields)) {
                $existingGood->price = $this->applyPriceModification($goodData['variation']['price'], $priceModification['regular'] ?? null);
            }
            if ((isset($goodData['variation']['sale_price']) || isset($priceModification)) && !in_array('sale_price', $immutableFields)) {
                $goodData['sale_price'] = $goodData['variation']['sale_price'] ?? null;
                $newSalePrice = $this->applySalePriceModification($goodData, $priceModification);
                // Если есть модификация акционной цены, всегда применяем результат (даже если null)
                if (isset($priceModification) && isset($priceModification['sale'])) {
                    $existingGood->sale_price = $newSalePrice;
                } else {
                    // Если модификации нет, используем значение из файла или оставляем существующее
                    $existingGood->sale_price = $newSalePrice ?? $existingGood->sale_price;
                }
            }
        } else {
            // Для товаров без вариаций обновляем цены из goodData
            if (isset($goodData['price']) && !in_array('price', $immutableFields)) {
                $existingGood->price = $this->applyPriceModification($goodData['price'], $priceModification['regular'] ?? null);
            }
            if ((isset($goodData['sale_price']) || isset($priceModification)) && !in_array('sale_price', $immutableFields)) {
                $newSalePrice = $this->applySalePriceModification($goodData, $priceModification);
                // Если есть модификация акционной цены, всегда применяем результат (даже если null)
                if (isset($priceModification) && isset($priceModification['sale'])) {
                    $existingGood->sale_price = $newSalePrice;
                } else {
                    // Если модификации нет, используем значение из файла или оставляем существующее
                    $existingGood->sale_price = $newSalePrice ?? $existingGood->sale_price;
                }
            }
        }
        
        // Обновляем демпинг цену, если передана и не в списке неизменяемых
        if (isset($goodData['demping_price']) && !in_array('demping_price', $immutableFields)) {
            $existingGood->demping_price = $goodData['demping_price'];
        }
        if (isset($goodData['show_demping']) && !in_array('show_demping', $immutableFields)) {
            $existingGood->show_demping = $goodData['show_demping'];
        }

        // Обновляем остатки только если они переданы и не в списке неизменяемых
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            // Для товаров с вариациями остатки берутся из вариации
            if ((isset($goodData['variation']['stock_quantity']) || isset($goodData['variation']['stock'])) && !in_array('stock_quantity', $immutableFields)) {
                $stockValue = $goodData['variation']['stock_quantity'] ?? $goodData['variation']['stock'] ?? $existingGood->stock_quantity;
                $existingGood->stock_quantity = is_numeric($stockValue) ? (float)$stockValue : $existingGood->stock_quantity;
            }

            if (!in_array('remote_stock_quantity', $immutableFields)) {
                $remoteValue = $goodData['variation']['remote_stock_quantity'] ?? null;
                // Обновляем только если значение не пустое
                if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                    $existingGood->remote_stock_quantity = is_numeric($remoteValue) ? (string)$remoteValue : $remoteValue;
                }
            }

            if (!in_array('fast_remote_stock_quantity', $immutableFields)) {
                $fastRemoteValue = $goodData['variation']['fast_remote_stock_quantity'] ?? null;
                // Обновляем только если значение не пустое
                if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                    $existingGood->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string)$fastRemoteValue : $fastRemoteValue;
                }
            }
        } else {
            // Для товаров без вариаций остатки берутся из goodData с конвертацией типов
            // Обновляем остатки из данных импорта, если они присутствуют
            if ((isset($goodData['stock_quantity']) || isset($goodData['stock'])) && !in_array('stock_quantity', $immutableFields)) {
                $stockValue = $goodData['stock_quantity'] ?? $goodData['stock'] ?? $existingGood->stock_quantity;
                $existingGood->stock_quantity = is_numeric($stockValue) ? (float)$stockValue : $existingGood->stock_quantity;
            }

            if (isset($goodData['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                $remoteValue = $goodData['remote_stock_quantity'];
                // Обновляем только если значение не пустое
                if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                    $existingGood->remote_stock_quantity = is_numeric($remoteValue) ? (string)$remoteValue : $remoteValue;
                }
            }

            if (isset($goodData['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                $fastRemoteValue = $goodData['fast_remote_stock_quantity'];
                // Обновляем только если значение не пустое
                if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                    $existingGood->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string)$fastRemoteValue : $fastRemoteValue;
                }
            }
        }
        
        // Обновляем размеры и вес, если переданы и не в списке неизменяемых
        if (isset($goodData['weight']) && !in_array('weight', $immutableFields)) {
            $existingGood->weight = $goodData['weight'];
        }
        if (isset($goodData['width']) && !in_array('width', $immutableFields)) {
            $existingGood->width = $goodData['width'];
        }
        if (isset($goodData['height']) && !in_array('height', $immutableFields)) {
            $existingGood->height = $goodData['height'];
        }
        // Поддерживаем и depth, и length для обратной совместимости
        if (isset($goodData['depth']) && !in_array('depth', $immutableFields)) {
            $existingGood->depth = $goodData['depth'];
        } elseif (isset($goodData['length']) && !in_array('depth', $immutableFields)) {
            $existingGood->depth = $goodData['length'];
        }
        
        // Обновляем флаги, если переданы и не в списке неизменяемых
        if (isset($goodData['is_active']) && !in_array('is_active', $immutableFields)) {
            $existingGood->is_active = $goodData['is_active'];
        }
        if (isset($goodData['is_featured']) && !in_array('is_featured', $immutableFields)) {
            $existingGood->is_featured = $goodData['is_featured'];
        }
        if (isset($goodData['is_new']) && !in_array('is_new', $immutableFields)) {
            $existingGood->is_new = $goodData['is_new'];
        }
        if (isset($goodData['is_sale']) && !in_array('is_sale', $immutableFields)) {
            $existingGood->is_sale = $goodData['is_sale'];
        }
        if (isset($goodData['is_preorder']) && !in_array('is_preorder', $immutableFields)) {
            $existingGood->is_preorder = $goodData['is_preorder'];
        }
        
        // Обновляем мета-теги, если переданы и не в списке неизменяемых
        if (isset($goodData['meta_title']) && !in_array('meta_title', $immutableFields)) {
            $existingGood->meta_title = $goodData['meta_title'];
        }
        if (isset($goodData['meta_description']) && !in_array('meta_description', $immutableFields)) {
            $existingGood->meta_description = $goodData['meta_description'];
        }
        
        // Обновляем label_id, если передан и не в списке неизменяемых
        if (isset($goodData['label_id']) && !in_array('label_id', $immutableFields)) {
            $existingGood->label_id = $goodData['label_id'];
        }
        
        // Обновляем sort_order, если передан и не в списке неизменяемых
        if (isset($goodData['sort_order']) && !in_array('sort_order', $immutableFields)) {
            $existingGood->sort_order = $goodData['sort_order'];
        }
        
        // Обновляем supplier (текстовое поле), если передан supplier_name и не в списке неизменяемых
        if (isset($goodData['supplier_name']) && !in_array('supplier', $immutableFields)) {
            $supplierName = trim($goodData['supplier_name'] ?? '');
            if ($supplierName !== '') {
                // Находим или создаем поставщика в таблице shop_suppliers
                $supplier = ShopSupplier::firstOrCreate(
                    ['name' => $supplierName],
                    [
                        'slug' => \Illuminate\Support\Str::slug($supplierName),
                        'is_active' => true,
                        'sort_order' => 0
                    ]
                );
                
                // Обновляем текстовое поле supplier в товаре
                $existingGood->supplier = $supplierName;
                
            } else {
                $existingGood->supplier = null;
            }
        }
        
        // Обновляем все остальные поля из goodData, которые есть в fillable модели
        // Это гарантирует, что все поля, отмеченные для импорта, будут обновлены
        // Используем список fillable полей из модели ShopGood
        $fillableFields = $existingGood->getFillable();
        
        // Список полей, которые уже обработаны выше (чтобы не дублировать)
        $alreadyProcessed = [
            'name', 'slug', 'sku', 'description', 'short_description',
            'price', 'sale_price', 'demping_price', 'show_demping', 'label_id',
            'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'width', 'height', 'depth',
            'weight', 'meta_title', 'meta_description',
            'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'sort_order'
        ];
        
        // Обновляем все поля из goodData, которые есть в fillable и не были обработаны выше
        foreach ($fillableFields as $field) {
            // Пропускаем поля, которые уже обработаны выше или являются служебными (начинаются с _)
            if (in_array($field, $alreadyProcessed) || strpos($field, '_') === 0) {
                continue;
            }

            // Пропускаем поля, которые находятся в списке неизменяемых
            if (in_array($field, $immutableFields)) {
                continue;
            }

            // Если поле есть в goodData, обновляем его
            if (isset($goodData[$field])) {
                $existingGood->$field = $goodData[$field];
            }
        }
        
        $existingGood->save();

        // Если товар имеет вариации, не обрабатывается конкретная вариация и были обновлены остатки,
        // обновляем остатки всех активных вариаций данными из товара
        // При импорте НЕ копируем остатки между товаром и вариациями
        // Каждый объект обновляется только своими данными из файла

        // Обрабатываем категории
        // ВАЖНО: категории уже обработаны в processCategoriesAndBrandsBatch, где:
        // - Категории из файла найдены/созданы и преобразованы в ID
        // Здесь мы извлекаем уже обработанные категории
        $categoryIds = [];

        if (isset($goodData['category']) && !empty($goodData['category'])) {
            // Одиночная категория - значение уже должно быть ID после processCategoriesAndBrandsBatch
            $categoryIds = [(int)$goodData['category']];
        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
            // Множественные категории - значения уже должны быть ID после processCategoriesAndBrandsBatch
            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function($id) {
                return $id > 0;
            });
        }

        // Обрабатываем категории в функции updateGood
        // ВАЖНО: Проверяем существующие категории ПЕРЕД применением категорий из файла
        $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();
        
        // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
            $defaultCategoryId = (int)$defaultCategory;
            // Если единственная категория - это defaultCategory, и у товара уже есть другие категории
            if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                // Вероятно, категория была применена по умолчанию - оставляем существующие категории
                $categoryIds = $existingCategoryIds;
            }
        } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
            // Если категорий нет в файле, но включена категория по умолчанию
            // При обновлении товара: применяем категорию по умолчанию только если у товара нет категорий
            if (empty($existingCategoryIds)) {
                // У товара нет категорий - применяем категорию по умолчанию
                $categoryIds = [(int)$defaultCategory];
            } else {
                // У товара уже есть категории - оставляем их без изменений
                $categoryIds = $existingCategoryIds;
            }
        }

        // Синхронизируем категории только если они были указаны в файле или применена категория по умолчанию
        // ВАЖНО: При обновлении товара, если категорий нет в файле и категория по умолчанию не применялась,
        // не отвязываем существующие категории - оставляем их без изменений
        if (!empty($categoryIds)) {
            $existingGood->categories()->sync($categoryIds);
        }
        // Если $categoryIds пустой и категория по умолчанию не применялась - не вызываем sync, оставляем существующие категории

        // Обрабатываем бренды
        if (isset($goodData['brand']) && is_numeric($goodData['brand'])) {
            // Одиночный бренд по ID
            $existingGood->brands()->sync([$goodData['brand']]);
        } elseif (isset($goodData['brands']) && is_array($goodData['brands'])) {
            // Множественные бренды - ID уже применены в applyCategoryAndBrandIds
            $brandIds = array_filter($goodData['brands'], 'is_numeric');
            $existingGood->brands()->sync($brandIds);
        }

        // Обрабатываем изображения
        if (isset($goodData['images']) && is_array($goodData['images'])) {
            $imageStats = $this->processImages($existingGood, $goodData['images']);
        }

        // Обрабатываем свойства товаров
        if (isset($goodData['properties']) && is_array($goodData['properties'])) {
            $this->processProperties($existingGood, $goodData['properties']);
        }

        // Обрабатываем вариации товара
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields);
        }

        // Если товар имеет вариации, обновляем все его вариации данными из товара
        // Это применяется когда товар найден по артикулу и обновляется, но вариации тоже должны получить обновленные данные
        // НЕ применяется когда включен поиск в вариациях - в этом случае обновляется конкретная вариация
        if (!$hasVariation && !$searchByNameInVariations && $existingGood->variations()->count() > 0) {
            $variations = $existingGood->variations;

            foreach ($variations as $variation) {
                // Обновляем цены вариации данными из товара (если они переданы)
                if (isset($goodData['price']) && !in_array('price', $immutableFields)) {
                    $variation->price = $this->applyPriceModification($goodData['price'], $priceModification['regular'] ?? null);
                }

                // Обновляем акционную цену
                if ((isset($goodData['sale_price']) || isset($priceModification)) && !in_array('sale_price', $immutableFields)) {
                    if (isset($priceModification) && isset($priceModification['sale'])) {
                        $variation->sale_price = $this->applySalePriceModification($goodData, $priceModification);
                    } else {
                        $variation->sale_price = $goodData['sale_price'] ?? $variation->sale_price;
                    }
                }

                // Обновляем остатки вариации данными из товара с конвертацией типов
                if (isset($goodData['stock_quantity']) && !in_array('stock_quantity', $immutableFields)) {
                    $stockValue = $goodData['stock_quantity'];
                    // Обновляем только если значение не пустое
                    if ($stockValue !== null && $stockValue !== '' && trim($stockValue) !== '') {
                        $variation->stock_quantity = is_numeric($stockValue) ? (float)$stockValue : 0;
                    }
                }
                if (!in_array('remote_stock_quantity', $immutableFields)) {
                    $remoteValue = $goodData['remote_stock_quantity'] ?? null;
                    // Обновляем только если значение не пустое
                    if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                        $variation->remote_stock_quantity = is_numeric($remoteValue) ? (string)$remoteValue : $remoteValue;
                    }
                }
                if (!in_array('fast_remote_stock_quantity', $immutableFields)) {
                    $fastRemoteValue = $goodData['fast_remote_stock_quantity'] ?? null;
                    // Обновляем только если значение не пустое
                    if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                        $variation->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string)$fastRemoteValue : $fastRemoteValue;
                    }
                }

                $variation->save();

                }
        }

        return ['good' => $existingGood, 'imageStats' => $imageStats];
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
                $categorySlug = Str::slug($category);
                $existingCategory = ShopCategory::where('name', $category)
                    ->orWhere('slug', $categorySlug)
                    ->first();

                if ($existingCategory) {
                    $categoryIds[] = $existingCategory->id;
                } elseif ($autoCreate) {
                    // Дополнительная проверка на случай, если slug уже существует
                    $existingCategoryBySlug = ShopCategory::where('slug', $categorySlug)->first();
                    if ($existingCategoryBySlug) {
                        $categoryIds[] = $existingCategoryBySlug->id;
                    } else {
                        $newCategory = ShopCategory::create([
                            'name' => $category,
                            'slug' => $categorySlug,
                            'is_active' => true
                        ]);
                        $categoryIds[] = $newCategory->id;
                    }
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
                $brandSlug = Str::slug($brand);
                $existingBrand = ShopBrand::where('name', $brand)
                    ->orWhere('slug', $brandSlug)
                    ->first();

                if ($existingBrand) {
                    $brandIds[] = $existingBrand->id;
                } elseif ($autoCreate) {
                    // Проверяем, не существует ли уже бренд с таким slug
                    $existingBrandBySlug = ShopBrand::where('slug', $brandSlug)->first();
                    if ($existingBrandBySlug) {
                        $brandIds[] = $existingBrandBySlug->id;
                    } else {
                        $newBrand = ShopBrand::create([
                            'name' => $brand,
                            'slug' => $brandSlug,
                            'is_active' => true
                        ]);
                        $brandIds[] = $newBrand->id;
                    }
                } else {
                }
            }
        }

        return array_unique($brandIds);
    }

    private function processImages($good, $images)
    {
        $stats = ['downloaded' => 0, 'failed' => 0];

        // Проверяем, есть ли среди новых изображений изображения из Excel
        $hasExcelImages = false;
        foreach ($images as $imageUrl) {
            if (str_starts_with($imageUrl, '/images/shop/goods/excel_')) {
                $hasExcelImages = true;
                break;
            }
        }

        // Если есть изображения из Excel, удаляем существующие изображения из Excel для этого товара
        if ($hasExcelImages) {
            // Получаем все вариации товара
            $variationIds = $good->variations()->pluck('id')->toArray();

            // Удаляем изображения из Excel, привязанные к товару
            $existingExcelImages = ShopGoodImage::where('good_id', $good->id)
                ->where('file_path', 'like', '/images/shop/goods/excel_%')
                ->get();

            // Также удаляем изображения из Excel, привязанные к вариациям
            if (!empty($variationIds)) {
                $existingVariationExcelImages = ShopGoodImage::whereIn('variation_id', $variationIds)
                    ->where('file_path', 'like', '/images/shop/goods/excel_%')
                    ->get();

                $existingExcelImages = $existingExcelImages->merge($existingVariationExcelImages);
            }

            // Физически удаляем файлы и записи из БД
            foreach ($existingExcelImages as $existingImage) {
                $filePath = str_replace('/images/', '', $existingImage->file_path);
                $fullPath = base_path('../admin.skateandsnow.ru/public/images/' . $filePath);
                if (file_exists($fullPath)) {
                    unlink($fullPath); // Удаляем файл
                }
                $existingImage->delete(); // Удаляем запись
            }
        }

        foreach ($images as $imageUrl) {
            if (!empty($imageUrl)) {
                // Если это уже локальный путь (начинается с /), сохраняем как есть
                if (str_starts_with($imageUrl, '/')) {
                    ShopGoodImage::create([
                        'good_id' => $good->id,
                        'file_path' => $imageUrl,
                        'is_main' => false,
                        'sort_order' => 0
                    ]);
                    $stats['downloaded']++;
                } else {
                    // Если это внешний URL, скачиваем изображение
                    try {
                        $downloadResponse = $this->downloadImage($imageUrl);
                        if ($downloadResponse && isset($downloadResponse['data']['path'])) {
                            ShopGoodImage::create([
                                'good_id' => $good->id,
                                'file_path' => $downloadResponse['data']['path'],
                                'is_main' => false,
                                'sort_order' => 0
                            ]);
                            $stats['downloaded']++;
                        } else {
                            // Если не удалось скачать, сохраняем оригинальный URL
                            ShopGoodImage::create([
                                'good_id' => $good->id,
                                'file_path' => $imageUrl,
                                'is_main' => false,
                                'sort_order' => 0
                            ]);
                            $stats['failed']++;
                        }
                    } catch (\Exception $e) {
                        // В случае ошибки сохраняем оригинальный URL
                        ShopGoodImage::create([
                            'good_id' => $good->id,
                            'file_path' => $imageUrl,
                            'is_main' => false,
                            'sort_order' => 0
                        ]);
                        $stats['failed']++;
                    }
                }
            }
        }

        return $stats;
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
    private function processCategoriesAndBrandsBatch(&$goods, $autoCreateCategories, $autoCreateBrands, $defaultCategory = null, $useDefaultCategory = false)
    {
        // Собираем все уникальные названия категорий и брендов
        $allCategories = collect();
        $allBrands = collect();

        foreach ($goods as $good) {
            // Собираем категории
            if (isset($good['category']) && is_string($good['category']) && !empty($good['category'])) {
                // Сначала разделяем по запятым (если есть несколько категорий)
                $categoryStrings = array_map('trim', explode(',', $good['category']));
                
                foreach ($categoryStrings as $categoryString) {
                    if (empty($categoryString)) {
                        continue;
                    }
                    
                    // Если категория содержит иерархию (>), разбиваем и собираем все части отдельно
                    // НО саму строку с > не добавляем в список для создания
                    if (strpos($categoryString, '>') !== false) {
                        $categoryParts = array_map('trim', explode('>', $categoryString));
                        // Добавляем только отдельные части, не строку целиком
                        foreach ($categoryParts as $part) {
                            if (!empty($part)) {
                                $allCategories->push($part);
                            }
                        }
                    } else {
                        // Обычная категория без иерархии
                        $allCategories->push($categoryString);
                    }
                }
            } elseif (isset($good['categories'])) {
                if (is_string($good['categories'])) {
                    // Сначала разделяем по разделителям (запятая, точка с запятой и т.д.)
                    $categoryNames = $this->parseDelimitedString($good['categories']);
                    
                    foreach ($categoryNames as $categoryName) {
                        if (empty($categoryName)) {
                            continue;
                        }
                        
                        // Если категория содержит иерархию (>), разбиваем и собираем все части отдельно
                        // НО саму строку с > не добавляем в список для создания
                        if (strpos($categoryName, '>') !== false) {
                            $categoryParts = array_map('trim', explode('>', $categoryName));
                            // Добавляем только отдельные части, не строку целиком
                            foreach ($categoryParts as $part) {
                                if (!empty($part)) {
                                    $allCategories->push($part);
                                }
                            }
                        } else {
                            // Обычная категория без иерархии
                            $allCategories->push($categoryName);
                        }
                    }
                } elseif (is_array($good['categories'])) {
                    foreach ($good['categories'] as $categoryName) {
                        if (empty($categoryName) || !is_string($categoryName)) {
                            continue;
                        }
                        
                        // Если категория содержит иерархию (>), разбиваем и собираем все части отдельно
                        // НО саму строку с > не добавляем в список для создания
                        if (strpos($categoryName, '>') !== false) {
                            $categoryParts = array_map('trim', explode('>', $categoryName));
                            // Добавляем только отдельные части, не строку целиком
                            foreach ($categoryParts as $part) {
                                if (!empty($part)) {
                                    $allCategories->push($part);
                                }
                            }
                        } else {
                            // Обычная категория без иерархии
                            $allCategories->push($categoryName);
                        }
                    }
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
        $this->applyCategoryAndBrandIds($goods, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory);
    }

    /**
     * Пакетная обработка категорий
     */
    private function processCategoriesBatch($allCategories, $autoCreate)
    {
        // Загружаем существующие категории по имени и slug
        $existingCategories = ShopCategory::where(function($query) use ($allCategories) {
            $query->whereIn('name', $allCategories->toArray())
                  ->orWhereIn('slug', $allCategories->map(function($name) {
                      return Str::slug($name);
                  })->toArray());
        })->get();

        $categoryMap = collect();

        // Создаем карту по имени и slug
        foreach ($existingCategories as $category) {
            $categoryMap[strtolower($category->name)] = $category->id;
            $categoryMap[strtolower($category->slug)] = $category->id;
        }

        // Создаем недостающие категории
        // ВАЖНО: Фильтруем категории, которые содержат > - такие категории не должны создаваться напрямую
        // Они обрабатываются через processCategoryHierarchy в applyCategoryAndBrandIds
        $createdCategories = [];
        if ($autoCreate) {
            $categoriesToCreate = $allCategories->filter(function($name) use ($categoryMap) {
                // Пропускаем категории, которые содержат > - это иерархия, а не название категории
                if (strpos($name, '>') !== false) {
                    return false;
                }
                
                $nameLower = strtolower($name);
                $slugLower = strtolower(Str::slug($name));
                return !$categoryMap->has($nameLower) && !$categoryMap->has($slugLower);
            });

            foreach ($categoriesToCreate as $categoryName) {
                // Дополнительная проверка на случай, если категория все еще содержит >
                if (strpos($categoryName, '>') !== false) {
                    continue;
                }
                
                $categorySlug = Str::slug($categoryName);

                // Дополнительная проверка на случай, если slug уже существует
                $existingCategoryBySlug = ShopCategory::where('slug', $categorySlug)->first();
                if ($existingCategoryBySlug) {
                    $categoryMap[strtolower($categoryName)] = $existingCategoryBySlug->id;
                    continue;
                }

                $category = ShopCategory::create([
                    'name' => $categoryName,
                    'slug' => $categorySlug,
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


            foreach ($brandsToCreate as $brandName) {
                $brandSlug = Str::slug($brandName);

                // Дополнительная проверка на случай, если slug уже существует
                $existingBrandBySlug = ShopBrand::where('slug', $brandSlug)->first();
                if ($existingBrandBySlug) {
                    $brandMap[strtolower($brandName)] = $existingBrandBySlug->id;
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
            }
        }

        // Сохраняем информацию о созданных брендах в кэше
        cache(['created_brands_' . auth()->id() => $createdBrands], 300);


        // Сохраняем карту в кэше для использования в applyCategoryAndBrandIds
        cache(['brand_map_' . auth()->id() => $brandMap], 300); // 5 минут
    }

    /**
     * Применение найденных ID к товарам
     */
    private function applyCategoryAndBrandIds(&$goods, $autoCreateCategories = true, $autoCreateBrands = true, $defaultCategory = null, $useDefaultCategory = false)
    {
        $categoryMap = cache('category_map_' . auth()->id(), collect());
        // Преобразуем коллекцию в массив для удобства работы
        $categoryMapArray = $categoryMap instanceof \Illuminate\Support\Collection ? $categoryMap->toArray() : (array)$categoryMap;
        $brandMap = cache('brand_map_' . auth()->id(), collect());


        foreach ($goods as &$good) {
            // Запоминаем, была ли категория в исходных данных (до обработки)
            $hadCategoryInFile = false;
            if (isset($good['category']) && !empty($good['category'])) {
                $hadCategoryInFile = true;
            } elseif (isset($good['categories'])) {
                if (is_array($good['categories']) && !empty($good['categories'])) {
                    $hadCategoryInFile = true;
                } elseif (is_string($good['categories']) && trim($good['categories']) !== '') {
                    $hadCategoryInFile = true;
                } elseif (is_numeric($good['categories']) && (int)$good['categories'] > 0) {
                    $hadCategoryInFile = true;
                }
            }
            
            // Обрабатываем категории
            if (isset($good['category']) && !empty($good['category'])) {
                // Если категория - строка или число, преобразуем в строку для обработки
                $categoryValue = (string)$good['category'];
                if (!empty($categoryValue)) {
                    // Проверяем, содержит ли строка символ > (иерархия категорий)
                    if (strpos($categoryValue, '>') !== false) {
                        // Разбиваем строку по символу > и обрабатываем иерархию
                        $categoryPath = array_map('trim', explode('>', $categoryValue));
                    $categoryId = $this->processCategoryHierarchy($categoryPath, $categoryMap, $autoCreateCategories);
                    if ($categoryId) {
                        $good['category'] = $categoryId;
                    } else {
                        if (!$autoCreateCategories) {
                            unset($good['category']);
                        }
                    }
                    } else {
                        // Обычная обработка одной категории
                        $categoryId = $categoryMapArray[strtolower($categoryValue)] ?? null;
                        if ($categoryId) {
                            $good['category'] = $categoryId;
                        } else {
                            if ($autoCreateCategories) {
                                // Создаем новую категорию, если она не найдена
                                $categoryName = trim($categoryValue);
                            $categorySlug = \Illuminate\Support\Str::slug($categoryName);
                            
                            // Проверяем уникальность slug
                            $slugCounter = 1;
                            $baseSlug = $categorySlug;
                            while (ShopCategory::where('slug', $categorySlug)->exists()) {
                                $categorySlug = $baseSlug . '-' . $slugCounter;
                                $slugCounter++;
                            }
                            
                            try {
                                $newCategory = ShopCategory::create([
                                    'name' => $categoryName,
                                    'slug' => $categorySlug,
                                    'parent_id' => null,
                                    'is_active' => true
                                ]);
                                $categoryId = $newCategory->id;
                                
                                // Добавляем в карту
                                $categoryMapArray[strtolower($categoryName)] = $categoryId;
                                
                                // Обновляем кеш карты
                                cache(['category_map_' . auth()->id() => $categoryMapArray], 300);
                                
                                // Добавляем в список созданных категорий
                                $createdCategories = cache('created_categories_' . auth()->id(), []);
                                if (!in_array($categoryId, $createdCategories)) {
                                    $createdCategories[] = $categoryId;
                                    cache(['created_categories_' . auth()->id() => $createdCategories], 300);
                                }
                                
                                $good['category'] = $categoryId;
                            } catch (\Exception $e) {
                                // Если ошибка при создании, удаляем категорию
                                unset($good['category']);
                            }
                        } else {
                            unset($good['category']);
                        }
                        }
                    }
                }
            }
            elseif (isset($good['categories'])) {
                if (is_string($good['categories'])) {
                    $categoryNames = $this->parseDelimitedString($good['categories']);
                    $categoryIds = [];

                    foreach ($categoryNames as $categoryName) {
                        // Преобразуем в строку на всякий случай
                        $categoryName = (string)$categoryName;
                        // Проверяем, содержит ли категория иерархию
                        if (strpos($categoryName, '>') !== false) {
                            $categoryPath = array_map('trim', explode('>', $categoryName));
                            $categoryId = $this->processCategoryHierarchy($categoryPath, $categoryMapArray, $autoCreateCategories);
                        } else {
                            $categoryId = $categoryMapArray[strtolower($categoryName)] ?? null;
                            
                            // Если категория не найдена и разрешено создание, создаем её
                            if (!$categoryId && $autoCreateCategories) {
                                $categoryNameTrimmed = trim($categoryName);
                                $categorySlug = \Illuminate\Support\Str::slug($categoryNameTrimmed);
                                
                                // Проверяем уникальность slug
                                $slugCounter = 1;
                                $baseSlug = $categorySlug;
                                while (ShopCategory::where('slug', $categorySlug)->exists()) {
                                    $categorySlug = $baseSlug . '-' . $slugCounter;
                                    $slugCounter++;
                                }
                                
                                try {
                                    $newCategory = ShopCategory::create([
                                        'name' => $categoryNameTrimmed,
                                        'slug' => $categorySlug,
                                        'parent_id' => null,
                                        'is_active' => true
                                    ]);
                                    $categoryId = $newCategory->id;
                                    
                                    // Добавляем в карту
                                    $categoryMapArray[strtolower($categoryNameTrimmed)] = $categoryId;
                                    
                                    // Обновляем кеш карты
                                    cache(['category_map_' . auth()->id() => $categoryMapArray], 300);
                                    
                                    // Добавляем в список созданных категорий
                                    $createdCategories = cache('created_categories_' . auth()->id(), []);
                                    if (!in_array($categoryId, $createdCategories)) {
                                        $createdCategories[] = $categoryId;
                                        cache(['created_categories_' . auth()->id() => $createdCategories], 300);
                                    }
                                } catch (\Exception $e) {
                                    // Если ошибка при создании, пропускаем категорию
                                    $categoryId = null;
                                }
                            }
                        }

                        if ($categoryId) {
                            $categoryIds[] = $categoryId;
                        }
                    }

                    $good['categories'] = $categoryIds;
                } elseif (is_array($good['categories'])) {
                    $categoryIds = [];

                    foreach ($good['categories'] as $category) {
                        if (is_string($category) && !empty($category)) {
                            $categoryId = $categoryMapArray[strtolower($category)] ?? null;
                            
                            // Если категория не найдена и разрешено создание, создаем её
                            if (!$categoryId && $autoCreateCategories) {
                                $categoryNameTrimmed = trim($category);
                                $categorySlug = \Illuminate\Support\Str::slug($categoryNameTrimmed);
                                
                                // Проверяем уникальность slug
                                $slugCounter = 1;
                                $baseSlug = $categorySlug;
                                while (ShopCategory::where('slug', $categorySlug)->exists()) {
                                    $categorySlug = $baseSlug . '-' . $slugCounter;
                                    $slugCounter++;
                                }
                                
                                try {
                                    $newCategory = ShopCategory::create([
                                        'name' => $categoryNameTrimmed,
                                        'slug' => $categorySlug,
                                        'parent_id' => null,
                                        'is_active' => true
                                    ]);
                                    $categoryId = $newCategory->id;
                                    
                                    // Добавляем в карту
                                    $categoryMapArray[strtolower($categoryNameTrimmed)] = $categoryId;
                                    
                                    // Обновляем кеш карты
                                    cache(['category_map_' . auth()->id() => $categoryMapArray], 300);
                                    
                                    // Добавляем в список созданных категорий
                                    $createdCategories = cache('created_categories_' . auth()->id(), []);
                                    if (!in_array($categoryId, $createdCategories)) {
                                        $createdCategories[] = $categoryId;
                                        cache(['created_categories_' . auth()->id() => $createdCategories], 300);
                                    }
                                } catch (\Exception $e) {
                                    // Если ошибка при создании, пропускаем категорию
                                    $categoryId = null;
                                }
                            }
                            
                            if ($categoryId) {
                                $categoryIds[] = $categoryId;
                            }
                        }
                    }

                    $good['categories'] = $categoryIds;
                }
            }
            
            // Применяем категорию по умолчанию ТОЛЬКО если после обработки нет валидной категории
            // ВАЖНО: категория по умолчанию НЕ должна применяться, если в файле была категория и она найдена/создана
            if ($useDefaultCategory && $defaultCategory !== null) {
                $hasValidCategory = false;
                
                // Проверяем category (после обработки это должен быть ID или null)
                if (isset($good['category'])) {
                    // Если category - это число (ID) и больше 0, значит категория найдена/создана из файла
                    if (is_numeric($good['category']) && (int)$good['category'] > 0) {
                        $hasValidCategory = true;
                    }
                    // Если category - это строка (не обработана), проверяем что она не пустая
                    elseif (is_string($good['category']) && trim($good['category']) !== '') {
                        $hasValidCategory = true;
                    }
                }
                
                // Проверяем массив categories (после обработки должны быть только ID)
                if (!$hasValidCategory && isset($good['categories'])) {
                    if (is_array($good['categories'])) {
                        // Фильтруем только валидные ID (числа > 0)
                        $validCategoryIds = array_filter($good['categories'], function($cat) {
                            return is_numeric($cat) && (int)$cat > 0;
                        });
                        if (!empty($validCategoryIds)) {
                            $hasValidCategory = true;
                        }
                    } elseif (is_numeric($good['categories']) && (int)$good['categories'] > 0) {
                        $hasValidCategory = true;
                    } elseif (is_string($good['categories']) && trim($good['categories']) !== '') {
                        $hasValidCategory = true;
                    }
                }
                
                // Применяем категорию по умолчанию ТОЛЬКО если:
                // - В файле НЕ было категории (hadCategoryInFile = false), ИЛИ
                // - В файле была категория, но она не найдена/не создана (hasValidCategory = false И hadCategoryInFile = true)
                // НО НЕ применяем, если в файле была категория и она найдена/создана (hasValidCategory = true)
                // Главное правило: если категория из файла найдена или создана, она имеет приоритет над категорией по умолчанию
                // Если категория была в файле, но не найдена/не создана - НЕ применяем категорию по умолчанию
                if (!$hasValidCategory && !$hadCategoryInFile) {
                    // Инициализируем массив categories, если его нет
                    if (!isset($good['categories']) || !is_array($good['categories'])) {
                        $good['categories'] = [];
                    }
                    
                    // Убеждаемся, что категория по умолчанию еще не добавлена
                    $defaultCategoryId = (int)$defaultCategory;
                    $existingIds = array_map('intval', array_filter($good['categories'], 'is_numeric'));
                    if (!in_array($defaultCategoryId, $existingIds)) {
                        $good['categories'][] = $defaultCategoryId;
                    }
                }
            }

            // Обрабатываем бренды
            if (isset($good['brand']) && is_string($good['brand']) && !empty($good['brand'])) {
                $brandId = $brandMap[strtolower($good['brand'])] ?? null;
                if ($brandId) {
                    $good['brand'] = $brandId;
                } else {
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

        // Сохраняем обновленную карту категорий обратно в кэш
        cache(['category_map_' . auth()->id() => collect($categoryMapArray)], 300);
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
     * Обработка иерархии категорий (Категория>Подкатегория1>Подкатегория2)
     * Создает категории с правильной иерархией, если их нет
     */
    private function processCategoryHierarchy($categoryPath, &$categoryMapArray, $autoCreate = true)
    {
        if (empty($categoryPath) || !is_array($categoryPath)) {
            return null;
        }

        $parentId = null;
        $lastCategoryId = null;

        foreach ($categoryPath as $index => $categoryName) {
            $categoryName = trim($categoryName);
            if (empty($categoryName)) {
                continue;
            }
            
            // Пропускаем категории, которые содержат > - это не название категории, а ошибка парсинга
            if (strpos($categoryName, '>') !== false) {
                continue;
            }

            // Ищем категорию по имени и родителю
            $categoryKey = $parentId 
                ? strtolower($categoryName) . '_parent_' . $parentId 
                : strtolower($categoryName);

            // Сначала проверяем в карте
            $categoryId = $categoryMapArray[$categoryKey] ?? null;

            if (!$categoryId) {
                // Ищем в базе данных по имени и родителю (приоритет - точное совпадение)
                $query = ShopCategory::where('name', $categoryName);
                if ($parentId) {
                    $query->where('parent_id', $parentId);
                } else {
                    $query->whereNull('parent_id');
                }
                $existingCategory = $query->first();

                if ($existingCategory) {
                    $categoryId = $existingCategory->id;
                    $categoryMapArray[$categoryKey] = $categoryId;
                    // Также добавляем в карту по имени для обратной совместимости
                    $categoryMapArray[strtolower($categoryName)] = $categoryId;
                } elseif ($autoCreate) {
                    // Создаем новую категорию
                    $categoryNameSlug = \Illuminate\Support\Str::slug($categoryName);
                    
                    // Если есть родитель, формируем slug как slug_родителя-slug_категории
                    if ($parentId) {
                        $parentCategory = ShopCategory::find($parentId);
                        if ($parentCategory && $parentCategory->slug) {
                            $baseSlug = $parentCategory->slug . '-' . $categoryNameSlug;
                        } else {
                            $baseSlug = $categoryNameSlug;
                        }
                    } else {
                        $baseSlug = $categoryNameSlug;
                    }
                    
                    $categorySlug = $baseSlug;
                    $slugCounter = 1;
                    
                    // Проверяем уникальность slug глобально (slug должен быть уникальным в таблице)
                    while (ShopCategory::where('slug', $categorySlug)->exists()) {
                        // Если slug уже существует, проверяем, подходит ли существующая категория
                        $existingBySlug = ShopCategory::where('slug', $categorySlug)->first();
                        
                        // Если существующая категория имеет правильное имя и родителя - используем её
                        if ($existingBySlug && 
                            $existingBySlug->name === $categoryName && 
                            $existingBySlug->parent_id == $parentId) {
                            $categoryId = $existingBySlug->id;
                            break;
                        }
                        
                        // Если не подходит, создаем уникальный slug с суффиксом
                        $categorySlug = $baseSlug . '-' . $slugCounter;
                        $slugCounter++;
                    }
                    
                    // Если не нашли подходящую категорию, создаем новую
                    if (!$categoryId) {
                        try {
                            $newCategory = ShopCategory::create([
                                'name' => $categoryName,
                                'slug' => $categorySlug,
                                'parent_id' => $parentId,
                                'is_active' => true
                            ]);
                            $categoryId = $newCategory->id;
                            
                            // Добавляем в список созданных категорий
                            $createdCategories = cache('created_categories_' . auth()->id(), []);
                            if (!in_array($categoryId, $createdCategories)) {
                                $createdCategories[] = $categoryId;
                                cache(['created_categories_' . auth()->id() => $createdCategories], 300);
                            }
                        } catch (\Exception $e) {
                            // Если ошибка при создании (например, дубликат slug), пытаемся найти существующую
                            
                            // Пытаемся найти категорию по имени и родителю еще раз
                            $fallbackQuery = ShopCategory::where('name', $categoryName);
                            if ($parentId) {
                                $fallbackQuery->where('parent_id', $parentId);
                            } else {
                                $fallbackQuery->whereNull('parent_id');
                            }
                            $fallbackCategory = $fallbackQuery->first();
                            
                            if ($fallbackCategory) {
                                $categoryId = $fallbackCategory->id;
                            } else {
                                // Если не нашли, пропускаем эту категорию
                                continue;
                            }
                        }
                    }

                    if ($categoryId) {
                        $categoryMapArray[$categoryKey] = $categoryId;
                        // Также добавляем в карту по имени для обратной совместимости
                        $categoryMapArray[strtolower($categoryName)] = $categoryId;
                    }
                } else {
                    // Не создаем категорию, возвращаем null
                    return null;
                }
            }

            $lastCategoryId = $categoryId;
            $parentId = $categoryId; // Следующая категория будет дочерней для текущей
        }

        return $lastCategoryId;
    }

    /**
     * Собирает информацию о новых категориях и брендах
     */
    private function collectNewCategoriesAndBrands($goods, &$results)
    {
        $newCategories = [];
        $newBrands = [];


        // Получаем кэш карт для определения новых элементов
        $categoryMap = cache('category_map_' . auth()->id(), collect());
        $brandMap = cache('brand_map_' . auth()->id(), collect());

        // Получаем информацию о том, какие элементы были созданы в этом сеансе
        $createdCategories = cache('created_categories_' . auth()->id(), []);
        $createdBrands = cache('created_brands_' . auth()->id(), []);

        foreach ($goods as $index => $goodData) {

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
                }
            }
        }


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
     * Не удаляет существующие свойства, только обновляет или добавляет новые
     */
    private function processProperties($good, $properties)
    {
        if (empty($properties)) {
            return;
        }

        // Получаем текущие свойства товара
        // Колонка variation_id была удалена из таблицы, поэтому фильтруем только по good_id
        $existingProperties = ShopGoodProperty::where('good_id', $good->id)
            ->get()
            ->keyBy('property_id');

        foreach ($properties as $propertyId => $value) {
            if (is_numeric($propertyId) && !empty($value)) {
                $valueString = trim((string) $value);
                
                if (empty($valueString)) {
                    continue;
                }

                try {
                    // Ищем значение в таблице shop_property_values
                    $propertyValue = ShopPropertyValue::where('property_id', $propertyId)
                        ->whereRaw('LOWER(value) = ?', [strtolower($valueString)])
                        ->first();

                    // Если не найдено - создаем новое значение
                    if (!$propertyValue) {
                        $propertyValue = ShopPropertyValue::create([
                            'property_id' => $propertyId,
                            'value' => $valueString,
                            'is_active' => true
                        ]);
                    }

                    // Проверяем, существует ли уже это свойство у товара
                    $existingProperty = $existingProperties->get($propertyId);

                    if ($existingProperty) {
                        // Свойство уже существует - проверяем значение
                        $currentValueId = $existingProperty->shop_property_value_id;
                        
                        // Сравниваем значения (учитываем null)
                        if ($currentValueId === null || $currentValueId != $propertyValue->id) {
                            // Значение изменилось или было null - обновляем
                            $existingProperty->shop_property_value_id = $propertyValue->id;
                            $existingProperty->save();
                            
                        } else {
                            // Значение не изменилось - пропускаем
                        }
                    } else {
                        // Свойства нет - создаем новое
                        ShopGoodProperty::create([
                            'good_id' => $good->id,
                            'property_id' => $propertyId,
                            'shop_property_value_id' => $propertyValue->id
                        ]);
                        
                    }
                } catch (\Exception $e) {
                    // Пропускаем это свойство при ошибке
                    continue;
                }
            }
        }

        // Старые свойства, которых нет в новых данных, не трогаем (не удаляем)
    }
    
    /**
     * Обработка вариации товара при импорте
     * @return int|null ID вариации или null, если вариация не была создана/обновлена
     */
    private function processVariation($good, $variationData, $goodData, $supplierStockFields = null)
    {
        try {
            // Проверяем наличие данных вариации
            if (!isset($variationData['attributes']) || !is_array($variationData['attributes']) || count($variationData['attributes']) === 0) {
                return null;
            }
            
            $attributes = $variationData['attributes'];
            $variationPrice = $variationData['price'] ?? $goodData['price'] ?? $good->price ?? 0;

            // Конвертируем остатки из строк в числа
            $variationStockQuantity = $variationData['stock_quantity'] ?? $goodData['stock_quantity'] ?? 0;
            $variationStockQuantity = is_numeric($variationStockQuantity) ? (float)$variationStockQuantity : 0;

            $variationRemoteStockQuantity = $variationData['remote_stock_quantity'] ?? $goodData['remote_stock_quantity'] ?? null;
            $variationRemoteStockQuantity = $variationRemoteStockQuantity !== null && is_numeric($variationRemoteStockQuantity) ? (string)$variationRemoteStockQuantity : $variationRemoteStockQuantity;

            $variationFastRemoteStockQuantity = $variationData['fast_remote_stock_quantity'] ?? $goodData['fast_remote_stock_quantity'] ?? null;
            $variationFastRemoteStockQuantity = $variationFastRemoteStockQuantity !== null && is_numeric($variationFastRemoteStockQuantity) ? (string)$variationFastRemoteStockQuantity : $variationFastRemoteStockQuantity;
            
            // Проверяем, есть ли у товара существующие вариации
            $hasExistingVariations = ShopGoodVariation::where('good_id', $good->id)->exists();
            
            // Получаем список существующих атрибутов товара (если есть вариации)
            $existingAttributeIds = [];
            if ($hasExistingVariations) {
                // Получаем все ID атрибутов, используемых в вариациях этого товара
                $variationIds = ShopGoodVariation::where('good_id', $good->id)->pluck('id')->toArray();
                $existingAttributeValueIds = DB::table('shop_variation_attributes_values')
                    ->whereIn('variation_id', $variationIds)
                    ->pluck('attribute_value_id')
                    ->unique()
                    ->toArray();
                
                // Получаем ID атрибутов из значений
                if (!empty($existingAttributeValueIds)) {
                    $existingAttributeIds = DB::table('shop_variation_attribute_values')
                        ->whereIn('id', $existingAttributeValueIds)
                        ->pluck('attribute_id')
                        ->unique()
                        ->toArray();
                }
            }
            
            // Находим или создаем атрибуты и их значения
            $attributeValueIds = [];
            $hasErrors = false;
            foreach ($attributes as $index => $attr) {
                if (!isset($attr['name']) || !isset($attr['value'])) {
                    continue;
                }

                $attributeName = trim($attr['name']);
                $attributeValue = trim($attr['value']);

                if (empty($attributeName) || empty($attributeValue)) {
                    continue;
                }
                
                // Находим атрибут (не создаем новые)
                $attribute = $this->findOrCreateVariationAttribute($attributeName, $hasExistingVariations, $existingAttributeIds);
                if (!$attribute) {
                    // Атрибут не найден - пропускаем эту характеристику
                    continue;
                }

                // Проверяем, что атрибут используется в существующих вариациях (если они есть)
                if ($hasExistingVariations && !in_array($attribute->id, $existingAttributeIds)) {
                    // Атрибут не используется в существующих вариациях - пропускаем
                    continue;
                }
                
                // Находим или создаем значение атрибута
                $attributeValueId = $this->findOrCreateAttributeValue($attribute->id, $attributeValue);
                if (!$attributeValueId) {
                    $hasErrors = true;
                    continue;
                }
                
                $attributeValueIds[] = $attributeValueId;
            }
            
            // Если были ошибки или нет валидных атрибутов - не создаем вариацию
            if ($hasErrors || empty($attributeValueIds)) {
                // Если нет валидных атрибутов - просто пропускаем вариацию без логирования
                // (характеристики не найдены, это нормальное поведение)
                return null;
            }

            // Сортируем ID атрибутов для сравнения
            sort($attributeValueIds);

            // Ищем существующую вариацию с такой же комбинацией атрибутов
            $existingVariation = $this->findVariationByAttributes($good->id, $attributeValueIds);

            if ($existingVariation) {
                // Вариация существует - обновляем все поля из goodData
                
                // Обновляем цену
                $existingVariation->price = $variationPrice;
                
                // Обновляем акционную цену, если передана
                if (isset($variationData['sale_price'])) {
                    $existingVariation->sale_price = $variationData['sale_price'];
                } elseif (isset($goodData['sale_price'])) {
                    $existingVariation->sale_price = $goodData['sale_price'];
                }
                
                // Обновляем остатки - только разрешенные поля согласно настройкам поставщика
                if ($supplierStockFields && isset($supplierStockFields['stock_quantity']) && $supplierStockFields['stock_quantity']) {
                    $existingVariation->stock_quantity = $variationStockQuantity;
                }
                if ($supplierStockFields && isset($supplierStockFields['remote_stock_quantity']) && $supplierStockFields['remote_stock_quantity'] &&
                    (isset($variationData['remote_stock_quantity']) || isset($goodData['remote_stock_quantity']))) {
                    $existingVariation->remote_stock_quantity = $variationRemoteStockQuantity;
                }
                if ($supplierStockFields && isset($supplierStockFields['fast_remote_stock_quantity']) && $supplierStockFields['fast_remote_stock_quantity'] &&
                    (isset($variationData['fast_remote_stock_quantity']) || isset($goodData['fast_remote_stock_quantity']))) {
                    $existingVariation->fast_remote_stock_quantity = $variationFastRemoteStockQuantity;
                }
                
                // Обновляем SKU вариации из данных товара или из goodData
                // Дублирование SKU разрешено для вариаций, поэтому не проверяем уникальность
                if (isset($goodData['sku']) && !empty($goodData['sku'])) {
                    $existingVariation->sku = $goodData['sku'];
                } elseif ($good->sku) {
                    $existingVariation->sku = $good->sku;
                }
                
                // Обновляем размеры и вес, если переданы
                if (isset($goodData['weight'])) {
                    $existingVariation->weight = $goodData['weight'];
                }
                if (isset($goodData['width'])) {
                    $existingVariation->width = $goodData['width'];
                }
                if (isset($goodData['height'])) {
                    $existingVariation->height = $goodData['height'];
                }
                if (isset($goodData['depth'])) {
                    $existingVariation->length = $goodData['depth'];
                } elseif (isset($goodData['length'])) {
                    $existingVariation->length = $goodData['length'];
                }
                
                // Обновляем флаг активности, если передан
                if (isset($goodData['is_active'])) {
                    $existingVariation->is_active = $goodData['is_active'];
                }
                
                try {
                    $existingVariation->save();
                } catch (QueryException $e) {
                    // Игнорируем ошибки дублирования SKU для вариаций (дублирование разрешено)
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                        strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                        $e->getCode() == 23000) {
                        // Пропускаем ошибку дублирования SKU - это разрешено для вариаций
                    } else {
                        // Если это другая ошибка - пробрасываем дальше
                        throw $e;
                    }
                }
                
                // Логируем обновление вариации
                $this->importLogService->logVariation(
                    'ОБНОВЛЕНА ВАРИАЦИЯ',
                    $good->name,
                    $good->sku ?? 'нет SKU',
                    $attributes,
                    $existingVariation->id,
                    "Цена: {$variationPrice}, Остаток: {$variationStockQuantity}" . ($variationRemoteStockQuantity !== null ? ", Удаленный склад: {$variationRemoteStockQuantity}" : '')
                );
                
                // Возвращаем ID вариации для связи с изображениями
                return $existingVariation->id;
            } else {
                // Вариация не существует - создаем новую
                // Используем SKU из goodData, если есть, иначе из good
                $variationSku = isset($goodData['sku']) && !empty($goodData['sku']) ? $goodData['sku'] : ($good->sku ?? null);
                
                // Определяем sort_order для новой вариации
                $maxSortOrder = $good->variations()->max('sort_order');
                $sortOrder = $maxSortOrder !== null ? ($maxSortOrder + 1) : 0;
                
                // Дублирование SKU разрешено для вариаций, поэтому не проверяем уникальность
                try {
                    $variationDataToCreate = [
                        'good_id' => $good->id,
                        'name' => $good->name,
                        'sku' => $variationSku,
                        'price' => $variationPrice,
                        'sale_price' => null,
                        'weight' => $good->weight ?? null,
                        'length' => $good->length ?? null,
                        'height' => $good->height ?? null,
                        'width' => $good->width ?? null,
                        'is_active' => true,
                        'sort_order' => $sortOrder
                    ];

                    // Добавляем остатки только для разрешенных полей
                    // Устанавливаем остатки для вариации
                    if (isset($variationData['remote_stock_quantity']) || isset($goodData['remote_stock_quantity'])) {
                        $variationDataToCreate['remote_stock_quantity'] = $variationRemoteStockQuantity;
                    }
                    // Если поле не установлено, оставляем значение по умолчанию (не устанавливаем null)

                    if (isset($variationData['fast_remote_stock_quantity']) || isset($goodData['fast_remote_stock_quantity'])) {
                        $variationDataToCreate['fast_remote_stock_quantity'] = $variationFastRemoteStockQuantity;
                    }
                    // Если поле не установлено, оставляем значение по умолчанию (не устанавливаем null)

                    $variationDataToCreate['stock_quantity'] = $variationStockQuantity;

                    $variation = ShopGoodVariation::create($variationDataToCreate);
                } catch (QueryException $e) {
                    // Игнорируем ошибки дублирования SKU для вариаций (дублирование разрешено)
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                        strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                        $e->getCode() == 23000) {
                        // Пропускаем ошибку дублирования SKU - это разрешено для вариаций
                        // Пробуем найти существующую вариацию с таким же SKU и обновить её
                        
                        // Ищем существующую вариацию с таким же SKU
                        $existingVariationBySku = ShopGoodVariation::where('good_id', $good->id)
                            ->where('sku', $variationSku)
                            ->first();
                        
                        if ($existingVariationBySku) {
                            // Обновляем существующую вариацию - все поля из goodData
                            $existingVariationBySku->price = $variationPrice;
                            
                            // Обновляем акционную цену, если передана
                            if (isset($variationData['sale_price'])) {
                                $existingVariationBySku->sale_price = $variationData['sale_price'];
                            } elseif (isset($goodData['sale_price'])) {
                                $existingVariationBySku->sale_price = $goodData['sale_price'];
                            }
                            
                            // Обновляем остатки только если значения не пустые
                            if ($variationStockQuantity !== null && $variationStockQuantity !== '' && trim($variationStockQuantity) !== '') {
                                $existingVariationBySku->stock_quantity = $variationStockQuantity;
                            }
                            if ((isset($variationData['remote_stock_quantity']) || isset($goodData['remote_stock_quantity'])) &&
                                $variationRemoteStockQuantity !== null && $variationRemoteStockQuantity !== '' && trim($variationRemoteStockQuantity) !== '') {
                                $existingVariationBySku->remote_stock_quantity = $variationRemoteStockQuantity;
                            }
                            if ((isset($variationData['fast_remote_stock_quantity']) || isset($goodData['fast_remote_stock_quantity'])) &&
                                $variationFastRemoteStockQuantity !== null && $variationFastRemoteStockQuantity !== '' && trim($variationFastRemoteStockQuantity) !== '') {
                                $existingVariationBySku->fast_remote_stock_quantity = $variationFastRemoteStockQuantity;
                            }
                            
                            // Обновляем размеры и вес, если переданы
                            if (isset($goodData['weight'])) {
                                $existingVariationBySku->weight = $goodData['weight'];
                            }
                            if (isset($goodData['width'])) {
                                $existingVariationBySku->width = $goodData['width'];
                            }
                            if (isset($goodData['height'])) {
                                $existingVariationBySku->height = $goodData['height'];
                            }
                            if (isset($goodData['depth'])) {
                                $existingVariationBySku->length = $goodData['depth'];
                            } elseif (isset($goodData['length'])) {
                                $existingVariationBySku->length = $goodData['length'];
                            }
                            
                            // Обновляем флаг активности, если передан
                            if (isset($goodData['is_active'])) {
                                $existingVariationBySku->is_active = $goodData['is_active'];
                            }
                            
                            try {
                                $existingVariationBySku->save();
                            } catch (QueryException $saveException) {
                                // Игнорируем ошибки дублирования SKU при сохранении
                                if (strpos($saveException->getMessage(), 'Duplicate entry') !== false || 
                                    strpos($saveException->getMessage(), 'UNIQUE constraint') !== false ||
                                    $saveException->getCode() == 23000) {
                                    // Пропускаем ошибку дублирования SKU
                                } else {
                                    throw $saveException;
                                }
                            }
                            
                            $variation = $existingVariationBySku;
                        } else {
                            // Если не нашли - пробрасываем ошибку дальше
                            throw $e;
                        }
                    } else {
                        // Если это другая ошибка - пробрасываем дальше
                        throw $e;
                    }
                }
                
                // Привязываем значения атрибутов к вариации
                foreach ($attributeValueIds as $attributeValueId) {
                    DB::table('shop_variation_attributes_values')->insert([
                        'variation_id' => $variation->id,
                        'attribute_value_id' => $attributeValueId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                
                // Логируем создание вариации
                $this->importLogService->logVariation(
                    'СОЗДАНА ВАРИАЦИЯ',
                    $good->name,
                    $variationSku ?? 'нет SKU',
                    $attributes,
                    $variation->id,
                    "Цена: {$variationPrice}, Остаток: {$variationStockQuantity}" . ($variationRemoteStockQuantity ? ", Удаленный склад: {$variationRemoteStockQuantity}" : '')
                );
                
                // Возвращаем ID вариации для связи с изображениями
                return $variation->id;
            }
            
            return null;
        } catch (\Exception $e) {
            
            // Логируем ошибку
            $this->importLogService->logVariation(
                'ОШИБКА ВАРИАЦИИ',
                $good->name ?? 'неизвестно',
                $good->sku ?? 'нет SKU',
                $variationData['attributes'] ?? [],
                null,
                "Ошибка: {$e->getMessage()}"
            );
        }


        return $results;
    }

    /**
     * Обновить вариацию из данных goodData
     * 
     * @param ShopGoodVariation $variation Вариация для обновления
     * @param array $goodData Данные товара
     * @param bool $searchByNameInVariations Если true, поле name не обновляется
     * @return int ID вариации
     */
    private function updateVariationFromGoodData($variation, $goodData, $searchByNameInVariations = false)
    {
        // Обновляем цену
        if (isset($goodData['price'])) {
            $variation->price = $goodData['price'];
        }
        
        // Обновляем акционную цену, если передана
        if (isset($goodData['sale_price'])) {
            $variation->sale_price = $goodData['sale_price'];
        }
        
        // Обновляем остатки с конвертацией типов
        if (isset($goodData['stock_quantity'])) {
            $variation->stock_quantity = is_numeric($goodData['stock_quantity']) ? (float)$goodData['stock_quantity'] : 0;
        }
        if (isset($goodData['remote_stock_quantity'])) {
            $variation->remote_stock_quantity = $goodData['remote_stock_quantity'] !== null && is_numeric($goodData['remote_stock_quantity']) ? (string)$goodData['remote_stock_quantity'] : $goodData['remote_stock_quantity'];
        }
        if (isset($goodData['fast_remote_stock_quantity'])) {
            $variation->fast_remote_stock_quantity = $goodData['fast_remote_stock_quantity'] !== null && is_numeric($goodData['fast_remote_stock_quantity']) ? (string)$goodData['fast_remote_stock_quantity'] : $goodData['fast_remote_stock_quantity'];
        }
        
        // Обновляем SKU вариации из данных товара или из goodData
        if (isset($goodData['sku']) && !empty($goodData['sku'])) {
            $variation->sku = $goodData['sku'];
        }
        
        // Обновляем размеры и вес, если переданы
        if (isset($goodData['weight'])) {
            $variation->weight = $goodData['weight'];
        }
        if (isset($goodData['width'])) {
            $variation->width = $goodData['width'];
        }
        if (isset($goodData['height'])) {
            $variation->height = $goodData['height'];
        }
        if (isset($goodData['depth'])) {
            $variation->length = $goodData['depth'];
        } elseif (isset($goodData['length'])) {
            $variation->length = $goodData['length'];
        }
        
        // Обновляем флаг активности, если передан
        if (isset($goodData['is_active'])) {
            $variation->is_active = $goodData['is_active'];
        }
        
        // Обновляем имя вариации, если передано и не включен поиск по именам в вариациях
        // Если включен поиск по именам, поле name нельзя изменять
        if (isset($goodData['name']) && !empty($goodData['name']) && !$searchByNameInVariations) {
            $variation->name = $goodData['name'];
        }

        // Обновляем атрибуты вариации, если они переданы
        if (isset($goodData['variation']) && isset($goodData['variation']['attributes']) && is_array($goodData['variation']['attributes'])) {
            $newAttributes = $goodData['variation']['attributes'];

            // Удаляем существующие связи с атрибутами
            DB::table('shop_variation_attributes_values')->where('variation_id', $variation->id)->delete();

            // Добавляем новые связи с атрибутами
            foreach ($newAttributes as $attr) {
                if (isset($attr['name']) && isset($attr['value'])) {
                    $attributeName = trim($attr['name']);
                    $attributeValue = trim($attr['value']);

                    if (!empty($attributeName) && !empty($attributeValue)) {
                        // Находим или создаем атрибут
                        $attribute = DB::table('shop_variation_attributes')
                            ->where('name', $attributeName)
                            ->first();

                        if ($attribute) {
                            // Находим или создаем значение атрибута
                            $attributeValueRecord = DB::table('shop_variation_attribute_values')
                                ->where('attribute_id', $attribute->id)
                                ->where('value', $attributeValue)
                                ->first();

                            if (!$attributeValueRecord) {
                                $attributeValueRecord = DB::table('shop_variation_attribute_values')->insertGetId([
                                    'attribute_id' => $attribute->id,
                                    'value' => $attributeValue,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]);
                                $attributeValueRecord = (object)['id' => $attributeValueRecord];
                            }

                            // Добавляем связь вариации с значением атрибута
                            DB::table('shop_variation_attributes_values')->insert([
                                'variation_id' => $variation->id,
                                'attribute_value_id' => $attributeValueRecord->id,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                    }
                }
            }
        }

        try {
            $variation->save();
        } catch (QueryException $e) {
            // Игнорируем ошибки дублирования SKU для вариаций (дублирование разрешено)
            if (strpos($e->getMessage(), 'Duplicate entry') === false && 
                strpos($e->getMessage(), 'UNIQUE constraint') === false &&
                $e->getCode() != 23000) {
                // Если это другая ошибка - пробрасываем дальше
                throw $e;
            }
        }
        
        return $variation->id;
    }
    
    /**
     * Найти или создать атрибут вариации
     * 
     * @param string $attributeName Название атрибута
     * @param bool $hasExistingVariations Есть ли у товара существующие вариации
     * @param array $existingAttributeIds Массив ID существующих атрибутов товара
     * @return object|null Объект атрибута или null, если не найден и не может быть создан
     */
    private function findOrCreateVariationAttribute($attributeName, $hasExistingVariations = false, $existingAttributeIds = [])
    {
        $attribute = DB::table('shop_variation_attributes')
            ->where('name', $attributeName)
            ->first();
        
        if ($attribute) {
            return $attribute;
        }
        
        // Не создаем новые атрибуты - просто возвращаем null, если атрибут не найден
        return null;
    }
    
    /**
     * Найти или создать значение атрибута
     */
    private function findOrCreateAttributeValue($attributeId, $value)
    {
        $attributeValue = DB::table('shop_variation_attribute_values')
            ->where('attribute_id', $attributeId)
            ->where('value', $value)
            ->first();
        
        if ($attributeValue) {
            return $attributeValue->id;
        }
        
        // Создаем новое значение атрибута
        $id = DB::table('shop_variation_attribute_values')->insertGetId([
            'attribute_id' => $attributeId,
            'value' => $value,
            'color' => null,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        return $id;
    }
    
    /**
     * Найти вариацию по комбинации атрибутов
     */
    private function findVariationByAttributes($goodId, $attributeValueIds)
    {
        // Получаем все вариации товара
        $variations = ShopGoodVariation::where('good_id', $goodId)->get();
        
        foreach ($variations as $variation) {
            // Получаем атрибуты существующей вариации
            $existingAttributeValueIds = DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->pluck('attribute_value_id')
                ->map(function($id) {
                    return (int)$id;
                })
                ->toArray();
            
            sort($existingAttributeValueIds);
            
            // Сравниваем комбинации атрибутов
            if ($existingAttributeValueIds === $attributeValueIds) {
                return $variation;
            }
        }
        
        return null;
    }

    /**
     * Обнулить остатки у всех товаров указанного поставщика
     */
    private function resetSupplierStock($supplierId)
    {
        try {
            // Обнуляем остатки на удаленном складе и остатки у/с быстро у товаров с указанным поставщиком
            ShopGood::where('supplier_id', $supplierId)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null
                ]);

            // Также обнуляем остатки у вариаций товаров с указанным поставщиком
            ShopGoodVariation::whereHas('good', function($query) use ($supplierId) {
                $query->where('supplier_id', $supplierId);
            })->update([
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null
            ]);
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем импорт
            Log::error('Ошибка при обнулении остатков поставщика: ' . $e->getMessage());
        }
    }


    /**
     * Обнуляет остатки товаров и вариаций поставщика
     */
    private function resetSupplierStocks($supplierName, $supplierStockFields = null)
    {
        $updatedGoods = 0;
        $updatedVariations = 0;


        // Если не указаны поля для обнуления, обнуляем все (обратная совместимость)
        if ($supplierStockFields === null) {
            $supplierStockFields = [
                'stock_quantity' => true,
                'remote_stock_quantity' => true,
                'fast_remote_stock_quantity' => true
            ];
        } else {
            }

        // Логируем полученные настройки полей остатков
        try {
            // Сначала проверим, сколько товаров у поставщика
            $goodsCount = ShopGood::where('supplier', $supplierName)->count();


            // Получаем товары перед обновлением для проверки
            $goodsBefore = ShopGood::where('supplier', $supplierName)
                ->select('id', 'name', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity')
                ->limit(5)
                ->get()
                ->toArray();

            // Формируем массив полей для обновления на основе настроек
            $updateFields = [];

            // Преобразуем значения в boolean для надежности
            $stockQuantityEnabled = isset($supplierStockFields['stock_quantity']) &&
                                  filter_var($supplierStockFields['stock_quantity'], FILTER_VALIDATE_BOOLEAN);
            $remoteStockEnabled = isset($supplierStockFields['remote_stock_quantity']) &&
                                 filter_var($supplierStockFields['remote_stock_quantity'], FILTER_VALIDATE_BOOLEAN);
            $fastRemoteStockEnabled = isset($supplierStockFields['fast_remote_stock_quantity']) &&
                                     filter_var($supplierStockFields['fast_remote_stock_quantity'], FILTER_VALIDATE_BOOLEAN);

            if ($stockQuantityEnabled) {
                $updateFields['stock_quantity'] = 0; // Числовое поле - устанавливаем 0
                }
            if ($remoteStockEnabled) {
                $updateFields['remote_stock_quantity'] = null; // Строковое поле - устанавливаем null
                }
            if ($fastRemoteStockEnabled) {
                $updateFields['fast_remote_stock_quantity'] = null; // Строковое поле - устанавливаем null
                }

            // Если нет полей для обновления, пропускаем
            if (empty($updateFields)) {
                return [
                    'goods_updated' => 0,
                    'variations_updated' => 0,
                    'message' => 'Нет полей остатков для обнуления'
                ];
            }

            // Обнуляем остатки ВСЕХ товаров поставщика только для выбранных полей
            $goodsUpdated = ShopGood::where('supplier', $supplierName)
                ->update($updateFields);

            $updatedGoods = $goodsUpdated;

            // Получаем товары после обновления для проверки
            $goodsAfter = ShopGood::where('supplier', $supplierName)
                ->select('id', 'name', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity')
                ->limit(5)
                ->get()
                ->toArray();

            // Проверяем товары после обновления
            $goodsAfter = ShopGood::where('supplier', $supplierName)
                ->select('id', 'name', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity')
                ->limit(5)
                ->get()
                ->toArray();


            // Проверяем вариации
            $variationsCount = ShopGoodVariation::whereHas('good', function($query) use ($supplierName) {
                $query->where('supplier', $supplierName);
            })->count();

            // Обнуляем остатки ВСЕХ вариаций товаров поставщика только для выбранных полей
            $variationsUpdated = ShopGoodVariation::whereHas('good', function($query) use ($supplierName) {
                $query->where('supplier', $supplierName);
            })->update($updateFields);

            $updatedVariations = $variationsUpdated;

            } catch (\Exception $e) {
            Log::error('Ошибка при обнулении остатков поставщика', [
                'supplier_name' => $supplierName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        return [
            'supplier_name' => $supplierName,
            'updated_goods' => $updatedGoods,
            'updated_variations' => $updatedVariations,
            'reset_fields' => array_keys($supplierStockFields)
        ];
    }

    /**
     * Обнуляет остатки всех товаров и вариаций
     */
    private function resetAllStocks()
    {
        $updatedGoods = 0;
        $updatedVariations = 0;


        try {
            // Обнуляем остатки всех товаров
            $goodsUpdated = ShopGood::query()->update([
                'stock_quantity' => 0,
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null
            ]);

            $updatedGoods = $goodsUpdated;

            // Обнуляем остатки всех вариаций
            $variationsUpdated = ShopGoodVariation::query()->update([
                'stock_quantity' => 0,
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null
            ]);

            $updatedVariations = $variationsUpdated;


        } catch (\Exception $e) {
            Log::error('Ошибка при обнулении остатков всех товаров', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        return [
            'reset_all' => true,
            'updated_goods' => $updatedGoods,
            'updated_variations' => $updatedVariations
        ];
    }

    /**
     * ОТКЛЮЧЕНО: Тестовый метод для проверки обнуления остатков поставщика
     */
    public function testResetSupplierStocks(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Обнуление остатков отключено',
            'reason' => 'Функция обнуления остатков полностью отключена по требованию'
        ], 403);
    }

    /**
     * ОТКЛЮЧЕНО: Тестовый метод для проверки обнуления остатков всех товаров
     */
    public function testResetAllStocks(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Обнуление остатков отключено',
            'reason' => 'Функция обнуления остатков полностью отключена по требованию'
        ], 403);
    }

    /**
     * Обнуляет остатки товаров поставщика по имени (текстовое поле)
     */
    private function resetSupplierStockByName($supplierName)
    {
        try {
            // Обнуляем остатки на удаленном складе и остатки у/с быстро у товаров с указанным поставщиком
            ShopGood::where('supplier', $supplierName)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null
                ]);

            // Также обнуляем остатки у вариаций товаров с указанным поставщиком
            ShopGoodVariation::whereHas('good', function($query) use ($supplierName) {
                $query->where('supplier', $supplierName);
            })->update([
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null
            ]);
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем импорт
            Log::error('Ошибка при обнулении остатков поставщика по имени: ' . $e->getMessage());
        }
    }

    /**
     * Проверяет, совпадают ли атрибуты вариации с атрибутами в goodData
     */
    private function checkVariationAttributesMatch($variation, $goodData)
    {
        if (!isset($goodData['variation']) || !isset($goodData['variation']['attributes'])) {
            return false;
        }

        $newAttributes = $goodData['variation']['attributes'];
        $existingAttributes = $this->getVariationAttributes($variation);

        if (count($newAttributes) !== count($existingAttributes)) {
            return false;
        }

        // Сортируем оба массива для сравнения
        $newAttrsSorted = collect($newAttributes)->sortBy(function($attr) {
            return $attr['name'] ?? '';
        })->map(function($attr) {
            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
        })->values()->toArray();

        $existingAttrsSorted = collect($existingAttributes)->sortBy(function($attr) {
            return $attr['name'] ?? '';
        })->map(function($attr) {
            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
        })->values()->toArray();

        return $newAttrsSorted === $existingAttrsSorted;
    }

    /**
     * Получает атрибуты вариации в формате массива
     */
    private function getVariationAttributes($variation)
    {
        if (!$variation->attributes) {
            return [];
        }

        return $variation->attributes->map(function($attr) {
            return [
                'name' => $attr->attribute->name,
                'value' => $attr->value
            ];
        })->toArray();
    }

    /**
     * Парсит загруженный YML файл по имени файла
     */
    public function parseUploadedYMLFile(Request $request)
    {
        try {
            $request->validate([
                'filename' => 'required|string'
            ]);

            $filename = $request->input('filename');

            // Валидация имени файла для безопасности
            if (!preg_match('/^temp_[a-f0-9\-]+\.(xml|yml|txt)$/i', $filename)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверное имя файла'
                ], 400);
            }

            $filePath = storage_path('app/temp/' . $filename);

            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл не найден'
                ], 404);
            }

            // Читаем содержимое файла
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось прочитать файл'
                ], 500);
            }

            // Парсим YML/XML
            $ymlData = $this->parseYMLContent($fileContent);


            return response()->json([
                'success' => true,
                'ymlData' => $ymlData
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка парсинга загруженного YML файла: ' . $e->getMessage());
            \Log::error('Стек вызовов: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка парсинга файла: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Парсит содержимое YML/XML строки
     */
    private function parseYMLContent($content)
    {
        try {
            // Загружаем XML
            $xml = simplexml_load_string($content);
            if ($xml === false) {
                throw new \Exception('Не удалось загрузить XML');
            }

            // Определяем структуру файла
            $isStandardYML = isset($xml->shop) && isset($xml->shop->offers);
            $isCustomFormat = isset($xml->shop) && isset($xml->shop->items);
            $alternativeStructure = false;


            if (!$isStandardYML && !$isCustomFormat) {
                // Попробуем найти другие возможные структуры
                $alternativeStructure = $this->detectAlternativeXMLStructure($xml);
                if (!$alternativeStructure) {
                    throw new \Exception('Файл не содержит корректную структуру YML или поддерживаемого формата XML');
                }
            }

            // Парсим товары и категории
            $categories = [];
            $offers = [];
            $shop = null;

            if ($isStandardYML) {
                // Стандартный YML формат
                $shop = $xml->shop;

                // Парсим категории
                if (isset($shop->categories) && isset($shop->categories->category)) {
                    foreach ($shop->categories->category as $category) {
                        $categories[] = [
                            'id' => (string)$category['id'],
                            'parentId' => (string)($category['parentId'] ?? null),
                            'name' => (string)$category
                        ];
                    }
                }

                // Парсим товары
                if (isset($shop->offers) && isset($shop->offers->offer)) {
                    foreach ($shop->offers->offer as $offer) {
                        $offers[] = $this->parseStandardYMLOffer($offer);
                    }
                }
            } elseif ($isCustomFormat) {
                // Кастомный формат (типа ВЕЛО_2025-12-22.xml)
                $shop = $xml->shop;

                if (isset($shop->items) && isset($shop->items->item)) {
                    foreach ($shop->items->item as $item) {
                        $offerData = $this->parseCustomFormatItem($item, $categories);
                        if ($offerData) {
                            $offers[] = $offerData;
                        }
                    }
                }
            } elseif ($alternativeStructure) {
                // Альтернативная структура XML
                [$categories, $offers] = $this->parseAlternativeXMLStructure($xml, $alternativeStructure);
                // Для альтернативных структур shop может быть в другом месте или отсутствовать
                $shop = isset($xml->shop) ? $xml->shop : null;
            }

            return [
                'shop' => [
                    'name' => $shop ? (string)($shop->name ?? 'Unknown Shop') : 'Unknown Shop',
                    'company' => $shop ? (string)($shop->company ?? 'Unknown Company') : 'Unknown Company',
                    'url' => $shop ? (string)($shop->url ?? null) : null
                ],
                'categories' => $categories,
                'offers' => $offers
            ];

        } catch (\Exception $e) {
            throw new \Exception('Ошибка парсинга YML: ' . $e->getMessage());
        }
    }

    /**
     * Парсит товар в стандартном YML формате
     */
    private function parseStandardYMLOffer($offer)
    {
        $rawXmlPrice = (string)($offer->price ?? 0);
        $parsedPrice = (float)preg_replace('/\s+/', '', str_replace(["\xC2\xA0", 'руб.', 'руб', 'р.', 'р'], '', $rawXmlPrice));

        return [
            'id' => (string)$offer['id'],
            'name' => (string)$offer->name,
            'categoryId' => (string)($offer->categoryId ?? null),
            'price' => $parsedPrice,
            'currencyId' => (string)($offer->currencyId ?? 'RUB'),
            'available' => (string)($offer['available'] ?? 'true') === 'true',
            'url' => (string)($offer->url ?? null),
            'picture' => isset($offer->picture) ? (string)$offer->picture : null,
            'description' => isset($offer->description) ? (string)$offer->description : null,
            'vendor' => isset($offer->vendor) ? (string)$offer->vendor : null,
            'vendorCode' => isset($offer->vendorCode) ? (string)$offer->vendorCode : null,
            'params' => $this->parseYMLParams($offer)
        ];
    }

    /**
     * Парсит товар в кастомном формате (ВЕЛО_2025-12-22.xml)
     */
    private function parseCustomFormatItem($item, &$categories)
    {
        $rawPrice = (string)$item->price;

        // Используем расширенную очистку цены
        $cleanedPrice = str_replace("\xC2\xA0", '', $rawPrice); // Удаляем неразрывный пробел (UTF-8: C2 A0)
        $cleanedPrice = preg_replace('/\s+/', '', $cleanedPrice); // Удаляем остальные пробельные символы
        $cleanedPrice = str_replace(['руб.', 'руб', 'р.', 'р'], '', $cleanedPrice); // Удаляем обозначения рублей

        $parsedPrice = (float)$cleanedPrice;

        // Извлекаем данные товара
        $offerData = [
            'id' => (string)$item->id,
            'name' => (string)$item->name,
            'categoryId' => (string)($item->level_id_3 ?? $item->level_id_2 ?? $item->level_id_1 ?? null),
            'price' => $parsedPrice,
            'currencyId' => 'RUB',
            'available' => (string)$item->available === 'true',
            'url' => null,
            'picture' => isset($item->picture) ? (string)$item->picture : null,
            'description' => isset($item->description) && !empty((string)$item->description) ? (string)$item->description : null,
            'vendor' => null,
            'vendorCode' => (string)($item->code ?? null),
            'params' => []
        ];

        // Парсим параметры
        if (isset($item->param)) {
            foreach ($item->param as $param) {
                $paramName = (string)$param['name'];
                $paramValue = (string)$param;

                $offerData['params'][] = [
                    'name' => $paramName,
                    'value' => $paramValue
                ];

                // Извлекаем производителя из параметров
                if ($paramName === 'Производитель') {
                    $offerData['vendor'] = $paramValue;
                }
            }
        }

        // Создаем категории из level данных
        $this->extractCategoriesFromItem($item, $categories);

        return $offerData;
    }

    /**
     * Парсит параметры YML товара
     */
    private function parseYMLParams($offer)
    {
        $params = [];
        if (isset($offer->param)) {
            foreach ($offer->param as $param) {
                $params[] = [
                    'name' => (string)$param['name'],
                    'value' => (string)$param
                ];
            }
        }
        return $params;
    }

    /**
     * Извлекает категории из level полей товара
     */
    private function extractCategoriesFromItem($item, &$categories)
    {
        // Проходим по всем level полям (от 0 до 3)
        for ($level = 0; $level <= 3; $level++) {
            $idField = "level_id_$level";
            $nameField = "level_name_$level";

            if (isset($item->$idField) && isset($item->$nameField)) {
                $categoryId = (string)$item->$idField;
                $categoryName = (string)$item->$nameField;

                // Проверяем, не добавлена ли уже эта категория
                $existingCategory = array_filter($categories, function($cat) use ($categoryId) {
                    return $cat['id'] === $categoryId;
                });

                if (empty($existingCategory)) {
                    // Определяем parentId (предыдущий уровень)
                    $parentId = null;
                    if ($level > 0) {
                        $parentField = "level_id_" . ($level - 1);
                        if (isset($item->$parentField)) {
                            $parentId = (string)$item->$parentField;
                        }
                    }

                    $categories[] = [
                        'id' => $categoryId,
                        'parentId' => $parentId,
                        'name' => $categoryName
                    ];
                }
            }
        }
    }

    /**
     * Определяет альтернативные структуры XML файлов
     */
    private function detectAlternativeXMLStructure($xml)
    {
        // Проверяем различные возможные структуры

        // Структура: <yml_catalog><shop><items><item>...</item></items></shop></yml_catalog>
        if ($xml->getName() === 'yml_catalog' && isset($xml->shop) && isset($xml->shop->items)) {
            return 'yml_catalog_custom';
        }

        // Структура: <offers><offer>...</offer></offers> (без shop)
        if ($xml->getName() === 'offers' && isset($xml->offer)) {
            return 'offers_only';
        }

        // Структура: <items><item>...</item></items> (без shop)
        if ($xml->getName() === 'items' && isset($xml->item)) {
            return 'items_only';
        }

        // Другие возможные структуры можно добавить здесь

        return false;
    }

    /**
     * Парсит товары из альтернативных структур XML
     */
    private function parseAlternativeXMLStructure($xml, $structureType)
    {
        $categories = [];
        $offers = [];


        switch ($structureType) {
            case 'yml_catalog_custom':
                // Структура: <yml_catalog><shop><items><item>...</item></items></shop></yml_catalog>
                if (isset($xml->shop->items->item)) {
                    foreach ($xml->shop->items->item as $item) {
                        $offerData = $this->parseCustomFormatItem($item, $categories);
                        if ($offerData) {
                            $offers[] = $offerData;
                        }
                    }
                }
                break;

            case 'offers_only':
                // Структура: <offers><offer>...</offer></offers>
                if (isset($xml->offer)) {
                    foreach ($xml->offer as $offer) {
                        $offers[] = $this->parseStandardYMLOffer($offer);
                    }
                }
                break;

            case 'items_only':
                // Структура: <items><item>...</item></items>
                if (isset($xml->item)) {
                    foreach ($xml->item as $item) {
                        $offerData = $this->parseCustomFormatItem($item, $categories);
                        if ($offerData) {
                            $offers[] = $offerData;
                        }
                    }
                }
                break;
        }

        return [$categories, $offers];
    }

    /**
     * Парсит YML/XML по URL
     */
    public function parseYMLFromUrl(Request $request)
    {
        try {
            $request->validate([
                'url' => 'required|url|max:2048',
                'username' => 'nullable|string|max:255',
                'password' => 'nullable|string|max:255'
            ]);

            $url = $request->input('url');
            $username = $request->input('username');
            $password = $request->input('password');

            // Загружаем XML по URL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            // HTTP Basic Authentication, если указаны учетные данные
            if (!empty($username) && !empty($password)) {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
            }

            // Настройки SSL (аналогично методу proxyYMLFeed)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_ALLOW_BEAST | CURLSSLOPT_NO_REVOKE);

            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 10);
            curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/xml, text/xml, */*',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7'
            ]);

            $xmlContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($xmlContent === false || $curlErrno !== 0) {
                $errorMessage = $curlError ?: 'Неизвестная ошибка cURL';
                if ($curlErrno) {
                    $errorMessage .= ' (код ошибки: ' . $curlErrno . ')';
                }

                \Log::error('Ошибка cURL при загрузке YML по URL', [
                    'url' => $url,
                    'curl_error' => $curlError,
                    'curl_errno' => $curlErrno,
                    'http_code' => $httpCode
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Ошибка загрузки фида: ' . $errorMessage
                ], 500);
            }

            if ($httpCode !== 200) {
                return response()->json([
                    'success' => false,
                    'error' => "Ошибка загрузки фида: HTTP {$httpCode}"
                ], $httpCode);
            }

            if (empty($xmlContent)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Фид пуст'
                ], 400);
            }

            // Парсим XML содержимое
            $ymlData = $this->parseYMLContent($xmlContent);

            return response()->json([
                'success' => true,
                'ymlData' => $ymlData
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка парсинга YML по URL: ' . $e->getMessage());
            \Log::error('Стек вызовов: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка парсинга файла: ' . $e->getMessage()
            ], 500);
        }
    }
}
