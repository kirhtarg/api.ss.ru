<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopCategory;
use App\Models\ShopBrand;
use App\Models\ShopGoodImage;
use App\Models\ShopPropertyValue;
use App\Models\ShopGoodProperty;
use App\Models\ShopGoodVariation;
use App\Services\ImportLogService;
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

            // Получаем информацию о листе
            $sheet = $good['_sheet'] ?? 'неизвестно';

            // Создаем уникальный идентификатор для товара
            $itemId = $sheet . '_' . $index . '_' . substr(md5($sku . $name), 0, 8);

            // Пропускаем только полностью пустые строки (где И SKU И название пустые)
            if (empty($sku) && empty($name)) {
                $reason = 'Пустая строка';

                // Логируем пропущенную строку с уникальным ID
                \Log::info("Пропущенная строка {$itemId}: SKU='{$sku}', Name='{$name}', Reason={$reason}");

                $skippedRows[] = [
                    'count' => $index + 1,
                    'sku' => $sku,
                    'name' => $name,
                    'sheet' => $sheet,
                    'reason' => $reason
                ];

                continue;
            }

            // SKU необязателен, но название обязательно
            if (empty($name)) {
                $reason = 'Отсутствует название';

                // Логируем ошибку валидации с уникальным ID
                \Log::info("Ошибка валидации {$itemId}: SKU='{$sku}', Name='{$name}', Reason={$reason}");

                $skippedRows[] = [
                    'count' => $index + 1,
                    'sku' => $sku,
                    'name' => $name,
                    'sheet' => $sheet,
                    'reason' => $reason
                ];
                continue;
            }

            // Нормализуем данные для непустых товаров
            // SKU может быть пустым
            $good['sku'] = $sku;
            $good['name'] = $name;
            $goods[] = $good;

            // Логируем валидный товар с уникальным ID
            \Log::info("Валидный товар {$itemId}: SKU='{$sku}', Name='{$name}', Лист='{$sheet}'");
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
            'variationIds' => [], // ID вариаций для связи с изображениями
            'newCategories' => [],
            'newBrands' => []
        ];

        // Пакетная обработка категорий и брендов
        if ($processCategoriesAndBrands && ($autoCreateCategories || $autoCreateBrands)) {
            \Log::info('Starting batch processing', [
                'goods_count' => count($goods),
                'autoCreateCategories' => $autoCreateCategories,
                'autoCreateBrands' => $autoCreateBrands
            ]);
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

                    // Определяем поле для поиска совпадений
                    $duplicateFields = $request->input('duplicate_fields', ['sku']);
                    $existingGood = null;
                    
                    // Если есть поле variation, всегда ищем товар по имени
                    $hasVariation = isset($goodData['variation']) && is_array($goodData['variation']) && 
                                    isset($goodData['variation']['attributes']) && 
                                    is_array($goodData['variation']['attributes']) && 
                                    count($goodData['variation']['attributes']) > 0;
                    
                    \Log::info('Проверка наличия вариации', [
                        'row' => $count,
                        'sku' => $sku,
                        'name' => $name,
                        'has_variation' => $hasVariation,
                        'variation_data' => $hasVariation ? $goodData['variation'] : null
                    ]);
                    
                    if ($hasVariation) {
                        // При наличии вариации ищем товар более тщательно
                        // Важно: для вариаций товар должен существовать, иначе будет ошибка дублирования
                        // Проверяем все возможные варианты поиска
                        
                        // 1. Сначала по SKU (если указан) - самый надежный способ
                        if (!empty($sku)) {
                            $existingGood = ShopGood::where('sku', $sku)->first();
                        }
                        
                        // 2. Если не найден по SKU, ищем по имени (точное совпадение)
                        if (!$existingGood) {
                            $existingGood = ShopGood::where('name', $name)->first();
                        }
                        
                        // 3. Если не найден, пробуем по имени с пустым SKU
                        if (!$existingGood) {
                            $existingGood = ShopGood::whereNull('sku')->where('name', $name)->first();
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

                    if ($existingGood) {
                        // Товар существует
                        // Если у строки есть вариация, обрабатываем её независимо от duplicateAction
                        if ($hasVariation) {
                            // Обрабатываем только вариацию, не обновляя сам товар
                            $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData);
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
                            }
                            
                            // Добавляем в группу для обновления
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id];
                        } elseif ($duplicateAction === 'update') {
                            $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands);
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
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => 'Дубликат (настройка: пропустить)'];
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
                                $variationId = $this->processVariation($doubleCheckGood, $goodData['variation'], $goodData);
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
                                }
                                
                                // Добавляем в группу для обновления
                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $doubleCheckGood->id];
                                
                                // Пропускаем создание товара, так как он уже существует
                                continue;
                            }
                        }
                        
                        // Товар действительно не существует - создаем новый (с вариацией или без)
                        $newGood = $this->createGood($goodData, $autoCreateCategories, $autoCreateBrands);
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
                                $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData);
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

        // Применяем модификацию цены
        $priceModification = $goodData['price_modification'] ?? null;
        $good->price = $this->applyPriceModification($goodData['price'] ?? 0, $priceModification['regular'] ?? null);
        $good->sale_price = $this->applySalePriceModification($goodData, $priceModification);
        $good->stock_quantity = $goodData['stock_quantity'] ?? $goodData['stock'] ?? 0;
        $good->remote_stock_quantity = $goodData['remote_stock_quantity'] ?? null;
        $good->weight = $goodData['weight'] ?? 0;
        $good->width = $goodData['width'] ?? 0;
        $good->height = $goodData['height'] ?? 0;
        // Поддерживаем и depth, и length для обратной совместимости
        $good->depth = $goodData['depth'] ?? $goodData['length'] ?? 0;
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

        // Обрабатываем вариации товара
        $variationId = null;
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            $variationId = $this->processVariation($good, $goodData['variation'], $goodData);
        }

        // Сохраняем ID вариации в объекте товара для последующего использования
        if ($variationId) {
            $good->lastVariationId = $variationId;
        }

        return $good;
    }

    private function updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands)
    {
        // Обновляем все поля из goodData, которые переданы в импорте
        // Это позволяет обновлять все поля, отмеченные для импорта, а не только те, что используются для поиска
        
        // Обновляем SKU (артикул) - если передан, обновляем
        if (isset($goodData['sku'])) {
            $existingGood->sku = !empty($goodData['sku']) ? $goodData['sku'] : null;
        }
        
        $existingGood->name = $goodData['name'];
        // Обновляем slug только если он явно передан в данных (выбран в маппинге)
        // При обновлении существующей записи не создаем slug автоматически
        if (isset($goodData['slug'])) {
            if (!empty($goodData['slug']) && trim($goodData['slug']) !== '') {
                $existingGood->slug = trim($goodData['slug']);
            }
            // Если slug передан, но пустой, не обновляем существующий slug
        }
        // Если slug не передан в данных (не выбран в маппинге), оставляем существующий slug без изменений

        // Обновляем описание только если оно передано
        if (isset($goodData['description'])) {
            $existingGood->description = $goodData['description'];
        }

        // Обновляем короткое описание только если оно передано
        if (isset($goodData['short_description'])) {
            $existingGood->short_description = $goodData['short_description'];
        }

        // Применяем модификацию цены
        $priceModification = $goodData['price_modification'] ?? null;
        if (isset($goodData['price'])) {
            $existingGood->price = $this->applyPriceModification($goodData['price'], $priceModification['regular'] ?? null);
        }
        if (isset($goodData['sale_price']) || isset($priceModification)) {
            $existingGood->sale_price = $this->applySalePriceModification($goodData, $priceModification) ?? $existingGood->sale_price;
        }
        
        // Обновляем демпинг цену, если передана
        if (isset($goodData['demping_price'])) {
            $existingGood->demping_price = $goodData['demping_price'];
        }
        if (isset($goodData['show_demping'])) {
            $existingGood->show_demping = $goodData['show_demping'];
        }

        // Обновляем остатки только если они переданы
        if (isset($goodData['stock_quantity']) || isset($goodData['stock'])) {
            $existingGood->stock_quantity = $goodData['stock_quantity'] ?? $goodData['stock'] ?? $existingGood->stock_quantity;
        }

        if (isset($goodData['remote_stock_quantity'])) {
            $existingGood->remote_stock_quantity = $goodData['remote_stock_quantity'];
        }
        
        // Обновляем размеры и вес, если переданы
        if (isset($goodData['weight'])) {
            $existingGood->weight = $goodData['weight'];
        }
        if (isset($goodData['width'])) {
            $existingGood->width = $goodData['width'];
        }
        if (isset($goodData['height'])) {
            $existingGood->height = $goodData['height'];
        }
        // Поддерживаем и depth, и length для обратной совместимости
        if (isset($goodData['depth'])) {
            $existingGood->depth = $goodData['depth'];
        } elseif (isset($goodData['length'])) {
            $existingGood->depth = $goodData['length'];
        }
        
        // Обновляем флаги, если переданы
        if (isset($goodData['is_active'])) {
            $existingGood->is_active = $goodData['is_active'];
        }
        if (isset($goodData['is_featured'])) {
            $existingGood->is_featured = $goodData['is_featured'];
        }
        if (isset($goodData['is_new'])) {
            $existingGood->is_new = $goodData['is_new'];
        }
        if (isset($goodData['is_sale'])) {
            $existingGood->is_sale = $goodData['is_sale'];
        }
        if (isset($goodData['is_preorder'])) {
            $existingGood->is_preorder = $goodData['is_preorder'];
        }
        
        // Обновляем мета-теги, если переданы
        if (isset($goodData['meta_title'])) {
            $existingGood->meta_title = $goodData['meta_title'];
        }
        if (isset($goodData['meta_description'])) {
            $existingGood->meta_description = $goodData['meta_description'];
        }
        
        // Обновляем label_id, если передан
        if (isset($goodData['label_id'])) {
            $existingGood->label_id = $goodData['label_id'];
        }
        
        // Обновляем sort_order, если передан
        if (isset($goodData['sort_order'])) {
            $existingGood->sort_order = $goodData['sort_order'];
        }
        
        // Обновляем все остальные поля из goodData, которые есть в fillable модели
        // Это гарантирует, что все поля, отмеченные для импорта, будут обновлены
        // Используем список fillable полей из модели ShopGood
        $fillableFields = $existingGood->getFillable();
        
        // Список полей, которые уже обработаны выше (чтобы не дублировать)
        $alreadyProcessed = [
            'name', 'slug', 'sku', 'description', 'short_description',
            'price', 'sale_price', 'demping_price', 'show_demping', 'label_id',
            'stock_quantity', 'remote_stock_quantity', 'width', 'height', 'depth',
            'weight', 'meta_title', 'meta_description',
            'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'sort_order'
        ];
        
        // Обновляем все поля из goodData, которые есть в fillable и не были обработаны выше
        foreach ($fillableFields as $field) {
            // Пропускаем поля, которые уже обработаны выше или являются служебными (начинаются с _)
            if (in_array($field, $alreadyProcessed) || strpos($field, '_') === 0) {
                continue;
            }
            
            // Если поле есть в goodData, обновляем его
            if (isset($goodData[$field])) {
                $existingGood->$field = $goodData[$field];
            }
        }
        
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

        // Обрабатываем вариации товара
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            $this->processVariation($existingGood, $goodData['variation'], $goodData);
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
        $this->applyCategoryAndBrandIds($goods, $autoCreateCategories, $autoCreateBrands);
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
                    \Log::info('Found existing category by slug during batch', ['category_name' => $categoryName, 'slug' => $categorySlug, 'category_id' => $existingCategoryBySlug->id]);
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
        // Преобразуем коллекцию в массив для удобства работы
        $categoryMapArray = $categoryMap instanceof \Illuminate\Support\Collection ? $categoryMap->toArray() : (array)$categoryMap;
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
                // Проверяем, содержит ли строка символ > (иерархия категорий)
                if (strpos($good['category'], '>') !== false) {
                    // Разбиваем строку по символу > и обрабатываем иерархию
                    $categoryPath = array_map('trim', explode('>', $good['category']));
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
                }
            } elseif (isset($good['categories'])) {
                if (is_string($good['categories'])) {
                    $categoryNames = $this->parseDelimitedString($good['categories']);
                    $categoryIds = [];

                    foreach ($categoryNames as $categoryName) {
                        // Проверяем, содержит ли категория иерархию
                        if (strpos($categoryName, '>') !== false) {
                            $categoryPath = array_map('trim', explode('>', $categoryName));
                            $categoryId = $this->processCategoryHierarchy($categoryPath, $categoryMapArray, $autoCreateCategories);
                        } else {
                            $categoryId = $categoryMapArray[strtolower($categoryName)] ?? null;
                        }
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

        // Сохраняем обновленную карту категорий обратно в кэш
        cache(['category_map_' . auth()->id() => collect($categoryMapArray)], 300);

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
                            \Log::error('Ошибка создания категории', [
                                'name' => $categoryName,
                                'slug' => $categorySlug,
                                'parent_id' => $parentId,
                                'error' => $e->getMessage()
                            ]);
                            
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
     * Не удаляет существующие свойства, только обновляет или добавляет новые
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
                            
                            \Log::info('Updated property value', [
                                'good_id' => $good->id,
                                'property_id' => $propertyId,
                                'old_value_id' => $currentValueId,
                                'new_value_id' => $propertyValue->id
                            ]);
                        } else {
                            // Значение не изменилось - пропускаем
                            \Log::info('Skipped unchanged property', [
                                'good_id' => $good->id,
                                'property_id' => $propertyId,
                                'value_id' => $propertyValue->id
                            ]);
                        }
                    } else {
                        // Свойства нет - создаем новое
                        ShopGoodProperty::create([
                            'good_id' => $good->id,
                            'property_id' => $propertyId,
                            'shop_property_value_id' => $propertyValue->id
                        ]);
                        
                        \Log::info('Created new property', [
                            'good_id' => $good->id,
                            'property_id' => $propertyId,
                            'value_id' => $propertyValue->id
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Ошибка обработки свойства товара', [
                        'good_id' => $good->id,
                        'property_id' => $propertyId,
                        'value' => $valueString,
                        'error' => $e->getMessage()
                    ]);
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
    private function processVariation($good, $variationData, $goodData)
    {
        \Log::info('processVariation: ВХОД В МЕТОД', [
            'good_id' => $good->id,
            'good_name' => $good->name,
            'variation_data_keys' => is_array($variationData) ? array_keys($variationData) : 'not_array',
            'variation_data' => $variationData,
            'good_data_keys' => array_keys($goodData)
        ]);
        
        try {
            // Проверяем наличие данных вариации
            if (!isset($variationData['attributes']) || !is_array($variationData['attributes']) || count($variationData['attributes']) === 0) {
                \Log::warning('processVariation: Нет данных вариации', [
                    'good_id' => $good->id,
                    'good_name' => $good->name,
                    'variation_data' => $variationData,
                    'has_attributes_key' => isset($variationData['attributes']),
                    'attributes_is_array' => isset($variationData['attributes']) && is_array($variationData['attributes']),
                    'attributes_count' => isset($variationData['attributes']) && is_array($variationData['attributes']) ? count($variationData['attributes']) : 0
                ]);
                return null;
            }
            
            $attributes = $variationData['attributes'];
            \Log::info('processVariation: Начало обработки вариации', [
                'good_id' => $good->id,
                'good_name' => $good->name,
                'attributes_count' => count($attributes),
                'attributes' => $attributes
            ]);
            $variationPrice = $variationData['price'] ?? $goodData['price'] ?? $good->price ?? 0;
            $variationStockQuantity = $variationData['stock_quantity'] ?? $goodData['stock_quantity'] ?? 0;
            $variationRemoteStockQuantity = $variationData['remote_stock_quantity'] ?? $goodData['remote_stock_quantity'] ?? null;
            
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
            foreach ($attributes as $attr) {
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
                    \Log::error('Не удалось найти или создать значение атрибута', [
                        'good_id' => $good->id,
                        'attribute_id' => $attribute->id,
                        'value' => $attributeValue
                    ]);
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
                
                // Обновляем остатки
                $existingVariation->stock_quantity = $variationStockQuantity;
                // Обновляем remote_stock_quantity всегда, даже если null (чтобы можно было сбросить значение)
                $existingVariation->remote_stock_quantity = $variationRemoteStockQuantity;
                
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
                        \Log::info('Пропущена ошибка дублирования SKU для вариации (разрешено)', [
                            'variation_id' => $existingVariation->id,
                            'sku' => $existingVariation->sku
                        ]);
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
                    $variation = ShopGoodVariation::create([
                        'good_id' => $good->id,
                        'name' => $good->name,
                        'sku' => $variationSku,
                        'price' => $variationPrice,
                        'sale_price' => null,
                        'stock_quantity' => $variationStockQuantity,
                        'remote_stock_quantity' => $variationRemoteStockQuantity,
                        'weight' => $good->weight ?? null,
                        'length' => $good->length ?? null,
                        'height' => $good->height ?? null,
                        'width' => $good->width ?? null,
                        'is_active' => true,
                        'sort_order' => $sortOrder
                    ]);
                } catch (QueryException $e) {
                    // Игнорируем ошибки дублирования SKU для вариаций (дублирование разрешено)
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                        strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                        $e->getCode() == 23000) {
                        // Пропускаем ошибку дублирования SKU - это разрешено для вариаций
                        // Пробуем найти существующую вариацию с таким же SKU и обновить её
                        \Log::info('Обнаружено дублирование SKU при создании вариации, ищем существующую', [
                            'good_id' => $good->id,
                            'sku' => $variationSku
                        ]);
                        
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
                            
                            $existingVariationBySku->stock_quantity = $variationStockQuantity;
                            // Обновляем remote_stock_quantity всегда, даже если null
                            $existingVariationBySku->remote_stock_quantity = $variationRemoteStockQuantity;
                            
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
            \Log::error('Ошибка обработки вариации при импорте', [
                'good_id' => $good->id,
                'good_name' => $good->name ?? 'неизвестно',
                'variation_data' => $variationData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
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
}
