<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopBrand;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;
use App\Models\ShopGoodProperty;
use App\Models\ShopGoodVariation;
use App\Models\ShopPropertyValue;
use App\Models\ShopSupplier;
use App\Services\GoodsBackupService;
use App\Services\ImportLogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BulkGoodsImportController extends Controller
{
    private $importLogService;

    private $backupService;

    public function __construct(ImportLogService $importLogService, GoodsBackupService $backupService)
    {
        $this->importLogService = $importLogService;
        $this->backupService = $backupService;
    }

    /**
     * Нормализует текст: удаляет двойные пробелы и лишние пробелы в начале/конце
     */
    private function normalizeText(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        // Убираем пробелы в начале и конце
        $text = trim($text);

        // Заменяем множественные пробелы на один
        $text = preg_replace('/\s+/', ' ', $text);

        return $text;
    }

    /**
     * Нормализует текст для поиска в базе данных (для совместимости со старыми записями)
     * Используется для поиска товаров с учетом двойных пробелов
     */
    private function normalizeTextForSearch(string $text): string
    {
        return $this->normalizeText($text);
    }

    private function normalizeStockSource($source): ?string
    {
        $source = trim((string) $source);
        if ($source === '') {
            return null;
        }

        return mb_strtolower($source);
    }

    private function hasImportedStockField(array $data, array $stockFields): bool
    {
        foreach ($stockFields as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    private function markStockImport($model, ?string $stockSource, ?string $stockImportRunId, array $stockFields, array $data): void
    {
        if (!$stockSource || !$stockImportRunId || !$this->hasImportedStockField($data, $stockFields)) {
            return;
        }

        $model->stock_source = $stockSource;
        $model->last_stock_import_run_id = $stockImportRunId;
        $model->last_stock_import_at = now();
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

        // ГАРАНТИРОВАННАЯ ПРОВЕРКА (Удалите после теста)
        // Получаем параметры импорта
        $nameTrimSymbol = $request->input('name_trim_symbol');
        $naming = trim((string) $request->input('images_naming', 'hash'));

        // Фильтруем пустые строки - оставляем только товары с заполненными SKU и названием
        $goods = [];
        $skippedRows = [];

        foreach ($allGoods as $index => $good) {
            $sku = isset($good['sku']) ? trim((string) $good['sku']) : '';
            $name = isset($good['name']) ? $this->normalizeText((string) $good['name']) : '';

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
                    'reason' => $reason,
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
                    'reason' => $reason,
                ];

                continue;
            }

            // Если есть только SKU без названия - это валидно для обновления существующего товара
            // Если есть только название без SKU - это валидно для создания нового товара
            // Если есть и SKU, и название - это валидно для создания или обновления

            // Нормализуем только те поля, которые были в запросе (пришли из маппинга колонок).
            // Не добавляем sku/name, если колонка не была сопоставлена — иначе при обновлении
            // мы бы перезаписывали значения в БД «пустыми» из хороших данных.
            if (array_key_exists('sku', $good)) {
                $good['sku'] = $sku;
            }
            if (array_key_exists('name', $good)) {
                $good['name'] = $name;
            }

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

        $stockSource = $this->normalizeStockSource($request->input('stock_source'));
        $stockImportRunId = $stockSource ? trim((string) $request->input('stock_import_run_id', '')) : null;
        if ($stockSource && $stockImportRunId === '') {
            $stockImportRunId = (string) Str::uuid();
        }
        $stockFullSync = $stockSource && filter_var($request->input('stock_full_sync', false), FILTER_VALIDATE_BOOLEAN);
        $stockSyncFinalize = $stockSource && filter_var($request->input('stock_sync_finalize', false), FILTER_VALIDATE_BOOLEAN);
        $stockSyncFieldsFromRequest = $request->input('stock_sync_fields', []);
        if (!is_array($stockSyncFieldsFromRequest)) {
            $stockSyncFieldsFromRequest = [];
        }
        $effectiveStockSyncFields = $request->has('stock_sync_fields')
            ? $stockSyncFieldsFromRequest
            : (!empty($supplierStockFields) ? array_keys($supplierStockFields) : ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity']);

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
            if ($stockSource && $stockFullSync && $stockSyncFinalize && $stockImportRunId) {
                $stockSync = $this->reconcileMissingStockSourceItems($stockSource, $stockImportRunId, $effectiveStockSyncFields);

                return response()->json([
                    'success' => true,
                    'message' => 'Полная синхронизация остатков завершена',
                    'results' => [
                        'imported' => 0,
                        'updated' => 0,
                        'skipped' => 0,
                        'failed' => 0,
                        'errors' => [],
                        'goodIds' => [],
                        'newCategories' => [],
                        'newBrands' => [],
                        'stockSync' => $stockSync,
                    ],
                ]);
            }

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
                    'newBrands' => [],
                ],
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
                'errors' => $validator->errors(),
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
        $useDefaultCategory = (bool) $useDefaultCategory;

        $skipImagesOnUpdateIfExists = $request->input('skip_images_if_exists_on_update', false);
        if (is_string($skipImagesOnUpdateIfExists)) {
            $skipImagesOnUpdateIfExists = in_array(strtolower($skipImagesOnUpdateIfExists), ['true', '1', 'yes', 'on']);
        }
        $skipImagesOnUpdateIfExists = (bool) $skipImagesOnUpdateIfExists;

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
                    'in_figure' => false,
                ]);
                $defaultCategory = $newCategory->id;
            }
        } elseif ($defaultCategory !== null && is_numeric($defaultCategory)) {
            // Если это число, используем как ID
            $defaultCategory = (int) $defaultCategory;
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
            'imagesFailed' => 0, // Количество неудачных загрузок изображений
            'stockSync' => null,
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
                // Для поиска: только значения из маппинга. Если колонка не сопоставлена — пустая строка
                $sku = isset($goodData['sku']) ? trim((string) $goodData['sku']) : '';
                $name = isset($goodData['name']) ? $this->normalizeText((string) $goodData['name']) : '';

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
                    $searchByNameInVariations = (bool) $searchByNameInVariations;

                    // Поле для поиска в вариациях ('name' или 'sku')
                    $searchByFieldInVariations = $request->input('search_by_field_in_variations', 'name');
                    if (!in_array($searchByFieldInVariations, ['name', 'sku'])) {
                        $searchByFieldInVariations = 'name'; // значение по умолчанию
                    }

                    // Получаем поставщика: из данных товара (добавлено при препроцессинге из request) или из request
                    $supplierName = null;
                    $supplierNameRaw = $goodData['supplier_name'] ?? $request->input('supplier_name', null);
                    if ($supplierNameRaw !== null && $supplierNameRaw !== '' && $supplierNameRaw !== '__reset_all__') {
                        $trimmed = trim((string) $supplierNameRaw);
                        if ($trimmed !== '') {
                            $supplierName = $trimmed;
                        }
                    }

                    if ($stockSource && $stockImportRunId) {
                        $goodData['_stock_source'] = $stockSource;
                        $goodData['_stock_import_run_id'] = $stockImportRunId;
                        $goodData['_stock_sync_fields'] = $effectiveStockSyncFields;
                    }

                    $existingGood = null;
                    $existingVariation = null;
                    $foundByFields = []; // Массив для хранения информации о том, по каким полям найден товар
                    $foundMainGoodByName = false; // Для «Поиск в вариациях» по SKU: true, если основной товар найден по названию (тогда добавляем вариацию)

                    // Инициализируем переменную hasVariation
                    $hasVariation = false;

                    // Если есть поле variation, всегда ищем товар по имени
                    $hasVariation = isset($goodData['variation']) && is_array($goodData['variation']) &&
                        isset($goodData['variation']['attributes']) &&
                        is_array($goodData['variation']['attributes']) &&
                        count($goodData['variation']['attributes']) > 0;

                    // Сначала проверяем режим поиска в вариациях (если включен)
                    // Поиск работает и без поставщика: при отсутствии поставщика ищем по всем вариациям
                    if ($searchByNameInVariations && !$hasVariation) {
                        // Поиск вариаций по имени отключаем для товаров с вариациями,
                        // потому это может быть несколько вариаций с одинаковым SKU
                        // Определяем значение для поиска в вариациях
                        $searchValue = ($searchByFieldInVariations === 'sku') ? $sku : $name;

                        if (!empty($searchValue)) {
                            // Ищем вариацию по выбранному полю и атрибутам
                            // Сначала пробуем найти с учетом поставщика
                            $existingVariation = null;
                            if ($supplierName) {
                                $existingVariation = $this->findVariationByNameAndAttributes(
                                    $searchByFieldInVariations,
                                    $searchValue,
                                    $goodData['variation']['attributes'] ?? [],
                                    $supplierName
                                );
                            }

                            // Если не нашли с поставщиком — пробуем без него
                            if (!$existingVariation) {
                                $existingVariation = $this->findVariationByNameAndAttributes(
                                    $searchByFieldInVariations,
                                    $searchValue,
                                    $goodData['variation']['attributes'] ?? [],
                                    null
                                );
                            }

                            if ($existingVariation) {
                                $existingGood = $existingVariation->good;
                            }
                        }
                    }

                    // При поиске в вариациях по SKU: ищем вариацию по артикулу также для строк С вариацией (hasVariation),
                    // т.к. в блоке выше поиск выполняется только при !hasVariation. Если вариация найдена по SKU —
                    // приоритет: только обновить вариацию, имя основного товара не затрагивать.
                    if (!$existingVariation && $searchByNameInVariations && $searchByFieldInVariations === 'sku' && !empty($sku) && $hasVariation) {
                        // Сначала пробуем найти с учетом поставщика
                        $existingVariation = null;
                        if ($supplierName) {
                            $existingVariation = $this->findVariationByNameAndAttributes(
                                'sku',
                                $sku,
                                $goodData['variation']['attributes'] ?? [],
                                $supplierName
                            );
                        }

                        // Если не нашли с поставщиком — пробуем без него
                        if (!$existingVariation) {
                            $existingVariation = $this->findVariationByNameAndAttributes(
                                'sku',
                                $sku,
                                $goodData['variation']['attributes'] ?? [],
                                null
                            );
                        }

                        if ($existingVariation) {
                            $existingGood = $existingVariation->good;
                            $searchValue = $sku;
                        }
                    }

                    // Если вариация не найдена (или поиск в вариациях отключен), ищем товар обычным способом
                    if (!$existingVariation) {
                        // Специальный режим: «Поиск в вариациях» по артикулу (SKU). Порядок: 1) по названию ? добавить вариацию, 2) по артикулу в основных ? обновить; поставщик учитывается.
                        // Присваиваем existingGood только при успешном нахождении (не перезаписываем null при неудаче).
                        if ($searchByNameInVariations && $searchByFieldInVariations === 'sku') {
                            // 1) По названию в основных товарах (с учётом поставщика)
                            if (!$existingGood && !empty($name)) {
                                $normalizedName = $this->normalizeTextForSearch($name);
                                $foundByName = ShopGood::where('name', $normalizedName);
                                if ($supplierName) {
                                    $foundByName->where('supplier', $supplierName);
                                }
                                $foundByName = $foundByName->first();
                                if (!$foundByName && $supplierName) {
                                    $foundByName = ShopGood::where('name', $normalizedName)->first();
                                }
                                if ($foundByName) {
                                    $existingGood = $foundByName;
                                    $foundMainGoodByName = true;
                                    $foundByFields[] = $supplierName ? "название: '{$name}' (поиск в вариациях по SKU, добавление вариации, поставщик: {$supplierName})" : "название: '{$name}' (поиск в вариациях по SKU, добавление вариации)";
                                }
                            }
                            // 2) Если по названию не нашли — по артикулу в основных товарах (с учётом поставщика), найденное — обновить
                            if (!$existingGood && !empty($sku)) {
                                if ($supplierName) {
                                    $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, поставщик: {$supplierName})";
                                    } else {
                                        $existingGood = ShopGood::where('sku', $sku)->first();
                                        if ($existingGood) {
                                            $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, без фильтра поставщика)";
                                        }
                                    }
                                } else {
                                    $existingGood = ShopGood::where('sku', $sku)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара)";
                                    }
                                }
                            }
                        } else {
                            // Обычный поиск товара по duplicateFields
                            if (!$existingGood) {
                                foreach ($duplicateFields as $field) {
                                    if ($field === 'sku') {
                                        if (!empty($sku)) {
                                            // Если указан поставщик, сначала ищем товар этого поставщика
                                            if ($supplierName) {
                                                $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = "SKU: '{$sku}' (поставщик: {$supplierName})";
                                                } else {
                                                    // Если не найден товар этого поставщика, проверяем есть ли товар с таким SKU вообще
                                                    $existingGoodAnySupplier = ShopGood::where('sku', $sku)->first();
                                                    if ($existingGoodAnySupplier) {
                                                        // Найден товар с таким SKU, но другого поставщика
                                                        // Это специальный случай - нужно обновить остатки и изменить привязку к поставщику
                                                        $existingGood = $existingGoodAnySupplier;
                                                        $foundByFields[] = "SKU: '{$sku}' (изменение поставщика с '{$existingGood->supplier}' на '{$supplierName}')";
                                                    }
                                                }
                                            } else {
                                                // Поставщик не указан - обычный поиск
                                                $existingGood = ShopGood::where('sku', $sku)->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = "SKU: '{$sku}'";
                                                }
                                            }
                                        } else {
                                            // При пустом SKU не ищем по name, если 'name' не в duplicate_fields
                                            if (in_array('name', $duplicateFields) && !empty($name)) {
                                                $goodQuery = ShopGood::whereNull('sku')->where('name', $name);
                                                if ($supplierName) {
                                                    $goodQuery->where('supplier', $supplierName);
                                                }
                                                $existingGood = $goodQuery->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = $supplierName ? "пустой SKU + название: '{$name}' (поставщик: {$supplierName})" : "пустой SKU + название: '{$name}'";
                                                }
                                            }
                                        }
                                    } elseif ($field === 'name') {
                                        if (!empty($name)) {
                                            $goodQuery = ShopGood::where('name', $this->normalizeTextForSearch($name));
                                            if ($supplierName) {
                                                $goodQuery->where('supplier', $supplierName);
                                            }
                                            $existingGood = $goodQuery->first();
                                            if ($existingGood) {
                                                $foundByFields[] = $supplierName ? "название: '{$name}' (поставщик: {$supplierName})" : "название: '{$name}'";
                                            }
                                        }
                                    }

                                    if ($existingGood) {
                                        break; // Найден товар, прекращаем поиск
                                    }
                                }
                            }
                        }

                        // FALLBACK_FIX: Search by variation SKU if not found by name/main SKU
                        if (!$existingGood && !empty($sku)) {
                            \Log::debug('Fallback search by variation SKU', ['sku' => $sku]);
                            $variationQuery = \App\Models\ShopGoodVariation::where('sku', $sku);
                            if ($supplierName) {
                                $variationQuery->where('supplier', $supplierName);
                            }
                            $foundVar = $variationQuery->first();
                            if (!$foundVar && $supplierName) {
                                $foundVar = \App\Models\ShopGoodVariation::where('sku', $sku)->first();
                            }
                            if ($foundVar && $foundVar->good) {
                                $existingGood = $foundVar->good;
                                $existingVariation = $foundVar;
                                $foundByFields[] = 'variation SKU (fallback): ' . $sku;
                            }
                        }
                    }

                    // ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА: При режиме "Искать в вариациях - по названию",
                    // если товар не найден после всех проверок, проверяем главные товары по SKU
                    if (!$existingGood && $searchByNameInVariations && $searchByFieldInVariations === 'name' && !empty($sku)) {
                        if ($supplierName) {
                            $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                            if ($existingGood) {
                                $foundByFields[] = "SKU: '{$sku}' (дополнительная проверка после поиска в вариациях по названию, поставщик: {$supplierName})";
                            } else {
                                $existingGood = ShopGood::where('sku', $sku)->first();
                                if ($existingGood) {
                                    $foundByFields[] = "SKU: '{$sku}' (дополнительная проверка после поиска в вариациях по названию, без фильтра поставщика)";
                                }
                            }
                        } else {
                            $existingGood = ShopGood::where('sku', $sku)->first();
                            if ($existingGood) {
                                $foundByFields[] = "SKU: '{$sku}' (дополнительная проверка после поиска в вариациях по названию)";
                            }
                        }
                    }

                    if ($hasVariation && !$existingVariation) {
                        // При наличии вариации ищем товар более тщательно (только если вариация ещё не найдена по SKU)
                        // Важно: для вариаций товар должен существовать, иначе будет ошибка дублирования
                        // Проверяем все возможные варианты поиска

                        // 1. Сначала по SKU (если указан) - самый надежный способ
                        if (!$existingGood && !empty($sku)) {
                            if ($supplierName) {
                                // Если указан поставщик, сначала ищем товар этого поставщика
                                $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                if ($existingGood) {
                                    $foundByFields[] = "SKU: '{$sku}' (для вариации, поставщик: {$supplierName})";
                                } else {
                                    // Если не найден товар этого поставщика, ищем любой товар с таким SKU
                                    $existingGood = ShopGood::where('sku', $sku)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (для вариации, существующий товар другого поставщика)";
                                    }
                                }
                            } else {
                                $existingGood = ShopGood::where('sku', $sku)->first();
                                if ($existingGood) {
                                    $foundByFields[] = "SKU: '{$sku}' (для вариации)";
                                }
                            }
                        }

                        // 2. Если не найден по SKU, ищем по имени — только если 'name' в duplicate_fields
                        if (!$existingGood && in_array('name', $duplicateFields) && !empty($name)) {
                            $goodQuery = ShopGood::where('name', $this->normalizeTextForSearch($name));
                            if ($supplierName) {
                                $goodQuery->where('supplier', $supplierName);
                            }
                            $existingGood = $goodQuery->first();
                            if ($existingGood) {
                                $foundByFields[] = $supplierName ? "название: '{$name}' (для вариации, поставщик: {$supplierName})" : "название: '{$name}' (для вариации)";
                            }
                        }

                        // 3. Если не найден, пробуем по имени с пустым SKU — только если 'name' в duplicate_fields
                        if (!$existingGood && in_array('name', $duplicateFields) && !empty($name)) {
                            $goodQuery = ShopGood::whereNull('sku')->where('name', $name);
                            if ($supplierName) {
                                $goodQuery->where('supplier', $supplierName);
                            }
                            $existingGood = $goodQuery->first();
                            if ($existingGood) {
                                $foundByFields[] = $supplierName ? "пустой SKU + название: '{$name}' (для вариации, поставщик: {$supplierName})" : "пустой SKU + название: '{$name}' (для вариации)";
                            }
                        }

                        // 4. Если все еще не найден, пробуем поиск по имени без учета регистра — только если 'name' в duplicate_fields
                        if (!$existingGood && in_array('name', $duplicateFields) && !empty($name)) {
                            // Нормализуем имя для поиска (убираем лишние пробелы, приводим к нижнему регистру)
                            $normalizedName = trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
                            $goodQuery = ShopGood::whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName]);
                            if ($supplierName) {
                                $goodQuery->where('supplier', $supplierName);
                            }
                            $allGoods = $goodQuery->get();
                            if ($allGoods->count() > 0) {
                                $existingGood = $allGoods->first();
                            }
                        }

                    } else {
                        // Ищем товар по указанным полям (обычная логика)
                        // Специальный режим: «Поиск в вариациях» по артикулу (SKU) — 1) по названию ? добавить вариацию, 2) по артикулу в основных ? обновить; поставщик учитывается.
                        // Критично: присваиваем existingGood только при успешном нахождении, иначе затирается результат из блока 407-465 (найденный по артикулу в основных).
                        if (!$existingGood) {
                            if ($searchByNameInVariations && $searchByFieldInVariations === 'sku') {
                                if (!empty($name) && !$existingGood) {
                                    $foundByName = ShopGood::where('name', $this->normalizeTextForSearch($name));
                                    if ($supplierName) {
                                        $foundByName->where('supplier', $supplierName);
                                    }
                                    $foundByName = $foundByName->first();
                                    if (!$foundByName && $supplierName) {
                                        $foundByName = ShopGood::where('name', $this->normalizeTextForSearch($name))->first();
                                    }
                                    if ($foundByName) {
                                        $existingGood = $foundByName;
                                        $foundMainGoodByName = true;
                                        $foundByFields[] = $supplierName ? "название: '{$name}' (поиск в вариациях по SKU, добавление вариации, поставщик: {$supplierName})" : "название: '{$name}' (поиск в вариациях по SKU, добавление вариации)";
                                    }
                                }
                                if (!$existingGood && !empty($sku)) {
                                    if ($supplierName) {
                                        $foundBySku = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                        if ($foundBySku) {
                                            $existingGood = $foundBySku;
                                            $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, поставщик: {$supplierName})";
                                        } else {
                                            $foundBySku = ShopGood::where('sku', $sku)->first();
                                            if ($foundBySku) {
                                                $existingGood = $foundBySku;
                                                $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, без фильтра поставщика)";
                                            }
                                        }
                                    } else {
                                        $foundBySku = ShopGood::where('sku', $sku)->first();
                                        if ($foundBySku) {
                                            $existingGood = $foundBySku;
                                            $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара)";
                                        }
                                    }
                                }
                            } else {
                                foreach ($duplicateFields as $field) {
                                    if ($field === 'sku') {
                                        if (!empty($sku)) {
                                            // Если указан поставщик, сначала ищем товар этого поставщика
                                            if ($supplierName) {
                                                $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = "SKU: '{$sku}' (поставщик: {$supplierName})";
                                                } else {
                                                    // Если не найден товар этого поставщика, проверяем есть ли товар с таким SKU вообще
                                                    $existingGoodAnySupplier = ShopGood::where('sku', $sku)->first();
                                                    if ($existingGoodAnySupplier) {
                                                        // Найден товар с таким SKU, но другого поставщика
                                                        // Это специальный случай - нужно обновить остатки и изменить привязку к поставщику
                                                        $existingGood = $existingGoodAnySupplier;
                                                        $foundByFields[] = "SKU: '{$sku}' (изменение поставщика с '{$existingGood->supplier}' на '{$supplierName}')";
                                                    }
                                                }
                                            } else {
                                                // Поставщик не указан - обычный поиск
                                                $existingGood = ShopGood::where('sku', $sku)->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = "SKU: '{$sku}'";
                                                }
                                            }
                                        } else {
                                            // При пустом SKU не ищем по name, если 'name' не в duplicate_fields
                                            if (in_array('name', $duplicateFields) && !empty($name)) {
                                                $goodQuery = ShopGood::whereNull('sku')->where('name', $name);
                                                if ($supplierName) {
                                                    $goodQuery->where('supplier', $supplierName);
                                                }
                                                $existingGood = $goodQuery->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = $supplierName ? "пустой SKU + название: '{$name}' (поставщик: {$supplierName})" : "пустой SKU + название: '{$name}'";
                                                }
                                            }
                                        }
                                    } elseif ($field === 'name') {
                                        if (!empty($name)) {
                                            $goodQuery = ShopGood::where('name', $this->normalizeTextForSearch($name));
                                            if ($supplierName) {
                                                $goodQuery->where('supplier', $supplierName);
                                            }
                                            $existingGood = $goodQuery->first();
                                            if ($existingGood) {
                                                $foundByFields[] = $supplierName ? "название: '{$name}' (поставщик: {$supplierName})" : "название: '{$name}'";
                                            }
                                        }
                                    }

                                    if ($existingGood) {
                                        break; // Найден товар, прекращаем поиск
                                    }
                                }
                            }
                        }
                    }

                    // Если найдена вариация по имени (при включенном поиске по именам в вариациях)
                    if ($existingVariation && $searchByNameInVariations) {
                        // Убеждаемся, это товар найден (если вариация найдена, товар должен быть)
                        if (!$existingGood && $existingVariation->good) {
                            $existingGood = $existingVariation->good;
                        }

                        // Если товар все еще не найден, пропускаем эту строку
                        if (!$existingGood) {
                            $results['skipped']++;
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $reason = 'Вариация найдена по ' . ($searchByFieldInVariations === 'sku' ? 'артикулу' : 'имени') . ' "' . ($searchValue ?? $sku) . '" (поле: ' . $searchByFieldInVariations . '), но связанный товар не найден в базе данных. Вариация ID: ' . $existingVariation->id;
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];

                            continue;
                        }

                        // КРИТИЧНО: Для товаров с вариациями, найденных по имени, отдельно обрезаем и обновляем название товара.
                        // При поиске по артикулу (SKU) имя основного товара не затрагиваем.
                        if ($searchByFieldInVariations !== 'sku') {
                            $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
                            $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);
                        }

                        // Обновляем вариацию (найденную по имени или по артикулу)
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
                                'value' => $row->value_value,
                            ];
                        }

                        $details = 'Найдена по ' . ($searchByFieldInVariations === 'sku' ? 'артикулу' : 'имени') . ' + совпадению атрибутов: ' . ($searchByFieldInVariations === 'sku' ? ($searchValue ?? $sku) : $name);
                        if ($sheet !== 'неизвестно') {
                            $details .= ", Лист: {$sheet}, Строка: {$count}";
                        }

                        $this->importLogService->logVariation(
                            $searchByFieldInVariations === 'sku' ? 'ОБНОВЛЕНА ВАРИАЦИЯ ПО АРТИКУЛУ + АТРИБУТАМ' : 'ОБНОВЛЕНА ВАРИАЦИЯ ПО ИМЕНИ + АТРИБУТАМ',
                            $existingGood->name ?? $name,
                            $existingGood->sku ?? 'нет SKU',
                            $variationAttributes,
                            $existingVariation->id,
                            $details
                        );

                        // Обрезка названия для товаров без вариаций будет обработана в updateGood

                        // Обновляем товар, если нужно
                        if ($duplicateAction === 'update') {
                            // КРИТИЧНО: Для товаров с вариациями отдельно обрезаем и обновляем название.
                            // При поиске по артикулу (SKU) имя основного товара не затрагиваем.
                            if ($hasVariation && $searchByFieldInVariations !== 'sku') {
                                $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
                                $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);
                            }

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

                                        // Удаляем нам товар
                                        $existingGoodWithField->delete();

                                    } catch (\Exception $deleteException) {
                                        Log::error('Ошибка при удалении дубликата товара', [
                                            'duplicate_good_id' => $existingGoodWithField->id,
                                            'error' => $deleteException->getMessage(),
                                        ]);
                                        // Продолжаем импорт, но логируем ошибку
                                    }
                                } else {
                                }
                            }

                            // Для «Поиск в вариациях»: изображения привязываем к вариации только если у товара есть вариации;
                            // если у товара нет вариаций — к основному товару
                            $attachImagesTo = ($existingGood->variations()->count() > 0) ? $existingVariation : null;
                            $updateResult = $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $immutableFields, $searchByNameInVariations, $hasVariation, $supplierStockFields, $nameTrimSymbol, $attachImagesTo, $searchByFieldInVariations, $skipImagesOnUpdateIfExists);
                            $results['imagesDownloaded'] += $updateResult['imageStats']['downloaded'];
                            $results['imagesFailed'] += $updateResult['imageStats']['failed'];
                            $safeGoodDataForVariationUpdate = $this->removeBlankValuesForPartialUpdate($goodData);

                            // Для логики "Изменить вариацию" также обновляем остатки сопоставленных вариаций
                            // Важно: используем $vid в цикле, чтобы не перезаписать $variationId (нужен для variationIds ниже)
                            if (isset($safeGoodDataForVariationUpdate['variation_ids']) && is_array($safeGoodDataForVariationUpdate['variation_ids'])) {
                                foreach ($safeGoodDataForVariationUpdate['variation_ids'] as $vid) {
                                    $variation = ShopGoodVariation::where('id', $vid)
                                        ->where('good_id', $existingGood->id)
                                        ->first();

                                    if ($variation) {
                                        // Обновляем остатки вариации данными из товара с конвертацией типов
                                        if (isset($safeGoodDataForVariationUpdate['stock_quantity']) && !in_array('stock_quantity', $immutableFields)) {
                                            $variation->stock_quantity = is_numeric($safeGoodDataForVariationUpdate['stock_quantity']) ? (float) $safeGoodDataForVariationUpdate['stock_quantity'] : 0;
                                        }
                                        if (isset($safeGoodDataForVariationUpdate['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                                            $variation->remote_stock_quantity = $safeGoodDataForVariationUpdate['remote_stock_quantity'] !== null && is_numeric($safeGoodDataForVariationUpdate['remote_stock_quantity']) ? (string) $safeGoodDataForVariationUpdate['remote_stock_quantity'] : $safeGoodDataForVariationUpdate['remote_stock_quantity'];
                                        }
                                        if (isset($safeGoodDataForVariationUpdate['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                                            $variation->fast_remote_stock_quantity = $safeGoodDataForVariationUpdate['fast_remote_stock_quantity'] !== null && is_numeric($safeGoodDataForVariationUpdate['fast_remote_stock_quantity']) ? (string) $safeGoodDataForVariationUpdate['fast_remote_stock_quantity'] : $safeGoodDataForVariationUpdate['fast_remote_stock_quantity'];
                                        }

                                        $this->markStockImport(
                                            $variation,
                                            $goodData['_stock_source'] ?? null,
                                            $goodData['_stock_import_run_id'] ?? null,
                                            $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
                                            $safeGoodDataForVariationUpdate
                                        );
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
                            $categoryIds = [(int) $goodData['category']];
                        } elseif (isset($goodData['categories']) && is_array($goodData['categories']) && !empty($goodData['categories'])) {
                            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                return $id > 0;
                            });
                        }

                        // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
                        // Если единственная категория совпадает с defaultCategory, и у товара уже есть другие категории - не перезаписываем
                        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                            $defaultCategoryId = (int) $defaultCategory;
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
                                $categoryIds = [(int) $defaultCategory];
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

                        // Сохраняем ID вариации для связи с изображениями (фронтенд: встроенные и связанные изображения)
                        if ($variationId) {
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }
                            // Для «Поиск в вариациях»: в строке нет variation.attributes, добавляем по sku и name для надёжного поиска на фронте
                            if (!empty($sku)) {
                                $results['variationIds'][$sku] = $variationId;
                            }
                            if (!empty($name)) {
                                $results['variationIds'][$name] = $variationId;
                            }

                            // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                            // когда несколько вариаций имеют одинаковый SKU
                            if (
                                isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                            ) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // Сортируем атрибуты для консистентности ключа
                                $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function ($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;

                            }
                        }

                        // Добавляем в группу для обновления
                        $sheet = $goodData['_sheet'] ?? 'неизвестно';
                        $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];

                        continue; // Пропускаем дальнейшую обработку
                    }

                    // Поиск в вариациях по SKU: в вариациях не нашли, нашли основной товар по названию.
                    // Если у товара уже есть вариации — добавляем новую (createSimpleVariation).
                    // Если у товара нет вариаций — не создаём вариацию; далее сработает «if ($existingGood)» и обновится основной товар (updateGood).
                    if ($searchByNameInVariations && !$hasVariation && !$existingVariation && $existingGood && $searchByFieldInVariations === 'sku' && $foundMainGoodByName) {
                        if ($existingGood->variations()->exists()) {
                            // Товар с вариациями — добавляем новую вариацию (артикул обязателен)
                            if (empty($sku)) {
                                $results['skipped']++;
                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $reason = 'Поиск в вариациях по SKU: товар найден по названию «' . $name . '», у товара есть вариации, но артикул в строке пустой — невозможно создать вариацию.';
                                $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];

                                continue;
                            }
                            if (!isset($goodData['_nameTrimSymbol'])) {
                                $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
                            }
                            $variationId = $this->createSimpleVariation($existingGood, $goodData, $supplierStockFields, $naming);
                            if (!$variationId) {
                                $results['skipped']++;
                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $reason = 'Поиск в вариациях по SKU: товар найден по названию «' . $name . '», не удалось создать вариацию (артикул: ' . $sku . ').';
                                $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];

                                continue;
                            }
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }
                            if (!empty($sku)) {
                                $results['variationIds'][$sku] = $variationId;
                            }
                            if (!empty($name)) {
                                $results['variationIds'][$name] = $variationId;
                            }
                            if (isset($goodData['images']) && is_array($goodData['images']) && $variationId) {
                                $variationForImages = ShopGoodVariation::find($variationId);
                                if ($variationForImages) {
                                    $imageStats = $this->processImages($existingGood, $goodData['images'], $variationForImages, $skipImagesOnUpdateIfExists, $naming);
                                    $results['imagesDownloaded'] += $imageStats['downloaded'];
                                    $results['imagesFailed'] += $imageStats['failed'];
                                }
                            }
                            $categoryIds = [];
                            if (isset($goodData['category']) && !empty($goodData['category'])) {
                                $categoryIds = [(int) $goodData['category']];
                            } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                                $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                    return $id > 0;
                                });
                            }
                            $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();
                            if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                                $defaultCategoryId = (int) $defaultCategory;
                                if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                    $categoryIds = $existingCategoryIds;
                                }
                            } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                                if (empty($existingCategoryIds)) {
                                    $categoryIds = [(int) $defaultCategory];
                                } else {
                                    $categoryIds = $existingCategoryIds;
                                }
                            }
                            if (!empty($categoryIds)) {
                                $existingGood->categories()->sync($categoryIds);
                            }
                            $results['updated']++;
                            if (!empty($sku)) {
                                $results['goodIds'][$sku] = $existingGood->id;
                            }
                            if (isset($goodData['_row'])) {
                                $results['goodIds'][$goodData['_row']] = $existingGood->id;
                            }
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];

                            continue;
                        }
                        // Товар без вариаций — не создаём вариацию, идём в «if ($existingGood)» ? updateGood (обновление основного товара)
                    }

                    // Логируем текущие значения переменных для отладки

                    // Если включен поиск в вариациях и товар имеет вариации, но вариация не найдена,
                    // а товар найден - создаем новую вариацию для существующего товара
                    if ($searchByNameInVariations && $hasVariation && !$existingVariation && $existingGood) {
                        // Создаем новую вариацию для найденного товара
                        // Передаем nameTrimSymbol в goodData для обрезки названия товара
                        if (!isset($goodData['_nameTrimSymbol'])) {
                            $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
                        }
                        $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields, $naming);

                        // Сохраняем ID вариации для связи с изображениями
                        if ($variationId) {
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }

                            // Также сохраняем по ключу на основе SKU + атрибутов вариации для случаев,
                            // когда несколько вариаций имеют одинаковый SKU
                            if (
                                isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                            ) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // Сортируем атрибуты для консистентности ключа
                                $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function ($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;
                            }
                        }

                        // Изображения привязываем к созданной вариации, а не к основному товару
                        if (isset($goodData['images']) && is_array($goodData['images']) && $variationId) {
                            $variationForImages = ShopGoodVariation::find($variationId);
                            if ($variationForImages) {
                                $imageStats = $this->processImages($existingGood, $goodData['images'], $variationForImages, $skipImagesOnUpdateIfExists, $naming);
                                $results['imagesDownloaded'] += $imageStats['downloaded'];
                                $results['imagesFailed'] += $imageStats['failed'];
                            }
                        }

                        // Обрабатываем категории товара (даже если обрабатывается только вариация)
                        $categoryIds = [];
                        if (isset($goodData['category']) && !empty($goodData['category'])) {
                            $categoryIds = [(int) $goodData['category']];
                        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                return $id > 0;
                            });
                        }

                        // Обрабатываем категории товара при обновлении ??ерез вариацию
                        // ВАЖНО: Проверяем существующие категории ПЕРЕД применением категорий из файла
                        $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();

                        // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
                        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                            $defaultCategoryId = (int) $defaultCategory;
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
                                $categoryIds = [(int) $defaultCategory];
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
                            if (
                                isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                            ) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // Сортируем атрибуты для консистентности ключа
                                $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function ($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;
                            }
                        }

                        // Добавляем в группу для обновления
                        $sheet = $goodData['_sheet'] ?? 'неизвестно';
                        $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];

                        continue; // Пропускаем дальнейшую обработку
                    }

                    if ($existingGood) {
                        // КРИТИЧНО: Если вариация уже найдена и обновлена в блоке выше (691-921),
                        // то $existingVariation установлен и код должен был сделать continue.
                        // Если мы попали ????да, но $existingVariation установлен - это ошибка логики.
                        // Пропускаем дальнейшую обработку, чтобы не создавать дубликат товара.
                        if ($existingVariation && $searchByNameInVariations) {
                            // Вариация уже обработана выше, пропускаем
                            continue;
                        }

                        // Товар существует
                        // Если у строки есть вариация, обрабатываем её независимо от duplicateAction
                        if ($hasVariation) {
                            // КРИТИЧНО: Отдельно обрезаем и обновляем название товара с вариацией
                            // Это должно происходить ДО обработки вариации
                            // ВАЖНО: Для товаров с вариациями название обновляется отдельно, независимо от searchByNameInVariations
                            // КРИТИЧНО: Для товаров с вариациями отдельно обрезаем и обновляем название
                            // Используем название из данных импорта, если оно есть, иначе проверяем название в БД
                            $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
                            $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);

                            // Обрабатываем только вариацию, но также обновляем категории товара, если нужно
                            // Передаем nameTrimSymbol в goodData для обрезки названия товара (на случай если обрезка не была применена выше)
                            if (!isset($goodData['_nameTrimSymbol'])) {
                                $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
                            }
                            $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields, $naming);

                            // Обрабатываем категории товара (даже если обрабатывается только вариация)
                            $categoryIds = [];
                            if (isset($goodData['category']) && !empty($goodData['category'])) {
                                $categoryIds = [(int) $goodData['category']];
                            } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                                $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                    return $id > 0;
                                });
                            }

                            // Обрабатываем категории товара при обновлении ??ерез вариацию
                            // ВАЖНО: Проверяем существующие категории ПЕРЕД применением категорий из файла
                            $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();

                            // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
                            if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                                $defaultCategoryId = (int) $defaultCategory;
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
                                    $categoryIds = [(int) $defaultCategory];
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
                                if (
                                    isset($goodData['sku']) && !empty($goodData['sku']) &&
                                    isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                    is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                                ) {

                                    $sku = trim($goodData['sku']);
                                    $attributes = $goodData['variation']['attributes'];

                                    // Сортируем атрибуты для консистентности ключа
                                    $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                        return $attr['name'] ?? '';
                                    })->map(function ($attr) {
                                        return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                    })->join('|');

                                    $variationKey = $sku . ':::' . $sortedAttributes;
                                    $results['variationIds'][$variationKey] = $variationId;
                                }
                            }

                            // Изображения привязываем к вариации, а не к основному товару
                            if (isset($goodData['images']) && is_array($goodData['images']) && $variationId) {
                                $variationForImages = ShopGoodVariation::find($variationId);
                                if ($variationForImages) {
                                    $imageStats = $this->processImages($existingGood, $goodData['images'], $variationForImages, $skipImagesOnUpdateIfExists, $naming);
                                    $results['imagesDownloaded'] += $imageStats['downloaded'];
                                    $results['imagesFailed'] += $imageStats['failed'];
                                }
                            }

                            // Добавляем в группу для обновления
                            $sheet = $goodData['_sheet'] ?? 'неизвестно';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];
                        } elseif ($duplicateAction === 'update') {
                            // КРИТИЧНО: Для товаров с вариациями отдельно обрезаем и обновляем название
                            // Это должно происходить ДО вызова updateGood
                            if ($hasVariation) {
                                $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
                                $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);
                            }
                            // Обрезка названия для товаров без вариаций будет обработана в updateGood

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

                                        // Удаляем нам товар
                                        $existingGoodWithField->delete();

                                    } catch (\Exception $deleteException) {
                                        Log::error('Ошибка при удалении дубликата товара (вариант 2)', [
                                            'duplicate_good_id' => $existingGoodWithField->id,
                                            'error' => $deleteException->getMessage(),
                                        ]);
                                        // Продолжаем импорт, но логируем ошибку
                                    }
                                } else {
                                }
                            }

                            // Если у товара есть вариации и в строке указан SKU — обновляем вариацию и привязываем изображения к ней
                            $attachImagesToVariation = null;
                            if ($existingGood->variations()->count() > 0 && !empty($sku)) {
                                $attachImagesToVariation = $existingGood->variations()->where('sku', $sku)->first();
                                // Критично: обновляем вариацию (остатки, цена, SKU и т.д.) из строки — иначе вариации «не затрагиваются»
                                if ($attachImagesToVariation) {
                                    $this->updateVariationFromGoodData($attachImagesToVariation, $goodData, $searchByNameInVariations);
                                }
                            }

                            $updateResult = $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $immutableFields, $searchByNameInVariations, $hasVariation, $supplierStockFields, $nameTrimSymbol, $attachImagesToVariation, $searchByFieldInVariations, $skipImagesOnUpdateIfExists, $naming);
                            $results['imagesDownloaded'] += $updateResult['imageStats']['downloaded'];
                            $results['imagesFailed'] += $updateResult['imageStats']['failed'];
                            $safeGoodDataForVariationUpdate = $this->removeBlankValuesForPartialUpdate($goodData);

                            // Для логики "Изменить вариацию" также обновляем остатки сопоставленных вариаций
                            if (isset($safeGoodDataForVariationUpdate['variation_ids']) && is_array($safeGoodDataForVariationUpdate['variation_ids'])) {
                                foreach ($safeGoodDataForVariationUpdate['variation_ids'] as $vid) {
                                    $variation = ShopGoodVariation::where('id', $vid)
                                        ->where('good_id', $existingGood->id)
                                        ->first();

                                    if ($variation) {
                                        // Обновляем остатки вариации данными из товара с конвертацией типов
                                        if (isset($safeGoodDataForVariationUpdate['stock_quantity']) && !in_array('stock_quantity', $immutableFields)) {
                                            $variation->stock_quantity = is_numeric($safeGoodDataForVariationUpdate['stock_quantity']) ? (float) $safeGoodDataForVariationUpdate['stock_quantity'] : 0;
                                        }

                                        if (isset($safeGoodDataForVariationUpdate['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                                            $variation->remote_stock_quantity = $safeGoodDataForVariationUpdate['remote_stock_quantity'] !== null && is_numeric($safeGoodDataForVariationUpdate['remote_stock_quantity']) ? (string) $safeGoodDataForVariationUpdate['remote_stock_quantity'] : $safeGoodDataForVariationUpdate['remote_stock_quantity'];
                                        }

                                        if (isset($safeGoodDataForVariationUpdate['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                                            $variation->fast_remote_stock_quantity = $safeGoodDataForVariationUpdate['fast_remote_stock_quantity'] !== null && is_numeric($safeGoodDataForVariationUpdate['fast_remote_stock_quantity']) ? (string) $safeGoodDataForVariationUpdate['fast_remote_stock_quantity'] : $safeGoodDataForVariationUpdate['fast_remote_stock_quantity'];
                                        }

                                        $this->markStockImport(
                                            $variation,
                                            $goodData['_stock_source'] ?? null,
                                            $goodData['_stock_import_run_id'] ?? null,
                                            $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
                                            $safeGoodDataForVariationUpdate
                                        );
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

                            // При привязке изображений к вариации — variationIds для встроенных/связанных изображений на фронте
                            if ($attachImagesToVariation) {
                                if (isset($goodData['_row'])) {
                                    $results['variationIds'][$goodData['_row']] = $attachImagesToVariation->id;
                                }
                                if (!empty($sku)) {
                                    $results['variationIds'][$sku] = $attachImagesToVariation->id;
                                }
                                if (!empty($name)) {
                                    $results['variationIds'][$name] = $attachImagesToVariation->id;
                                }
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
                        // КРИТИЧНО: Если вариация найдена по артикулу, но товар не найден в основном поиске,
                        // это означает, это вариация принадлежит существующему товару, который мы должны использовать.
                        // Не создаем новый товар, если вариация уже найдена и обработана.
                        if ($existingVariation && $searchByNameInVariations) {
                            // Вариация уже найдена и обработана выше, пропускаем создание нового товара
                            continue;
                        }

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

                            // Если не найден по SKU, проверяем по имени — только если 'name' в duplicate_fields
                            if (!$doubleCheckGood && in_array('name', $duplicateFields) && !empty($name)) {
                                $doubleCheckGood = ShopGood::where('name', $this->normalizeTextForSearch($name))->first();
                            }

                            // Если все еще не найден, проверяем по имени с пустым SKU — только если 'name' в duplicate_fields
                            if (!$doubleCheckGood && empty($sku) && in_array('name', $duplicateFields) && !empty($name)) {
                                $doubleCheckGood = ShopGood::whereNull('sku')->where('name', $name)->first();
                            }

                            if ($doubleCheckGood) {
                                // Товар найден при дополнительной проверке - обрабатываем только вариацию
                                $variationId = $this->processVariation($doubleCheckGood, $goodData['variation'], $goodData, $supplierStockFields, $naming);

                                // Обрабатываем категории товара (даже если обрабатывается только вариация)
                                $categoryIds = [];
                                if (isset($goodData['category']) && !empty($goodData['category'])) {
                                    $categoryIds = [(int) $goodData['category']];
                                } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                                    $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                        return $id > 0;
                                    });
                                }

                                // Обрабатываем категории товара при двойной проверке
                                // ВАЖНО: Проверяем существующие категории ПЕРЕД применением категорий из файла
                                $existingCategoryIds = $doubleCheckGood->categories()->pluck('shop_categories.id')->toArray();

                                // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
                                if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                                    $defaultCategoryId = (int) $defaultCategory;
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
                                        $categoryIds = [(int) $defaultCategory];
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
                                    if (
                                        isset($goodData['sku']) && !empty($goodData['sku']) &&
                                        isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                        is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                                    ) {

                                        $sku = trim($goodData['sku']);
                                        $attributes = $goodData['variation']['attributes'];

                                        // Сортируем атрибуты для консистентности ключа
                                        $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                            return $attr['name'] ?? '';
                                        })->map(function ($attr) {
                                            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                        })->join('|');

                                        $variationKey = $sku . ':::' . $sortedAttributes;
                                        $results['variationIds'][$variationKey] = $variationId;
                                    }
                                }

                                // Добавляем в группу для обновления
                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $doubleCheckGood->id, 'supplier' => ($doubleCheckGood->supplier ?? '')];

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

                        // КРИТИЧНО: Финальная проверка - если вариация найдена по артикулу, не создаем новый товар
                        // Это защита от создания дубликата, если вариация была найдена, но код по какой-то причине
                        // не попал в блок обработки вариации выше
                        // КРИТИЧНО: Финальная проверка - если товар или вариация найдены, не создаем новый товар
                        if ($existingGood || $existingVariation) {
                            // Если товар найден (в т.??. ??ерез fallback или поиск в вариациях), обновляем его
                            $goodToUpdate = $existingGood ?? ($existingVariation ? $existingVariation->good : null);

                            if ($goodToUpdate && $duplicateAction === 'update') {
                                if ($existingVariation) {
                                    $this->updateVariationFromGoodData($existingVariation, $goodData, $searchByNameInVariations);
                                }

                                $updateResult = $this->updateGood(
                                    $goodToUpdate,
                                    $goodData,
                                    $autoCreateCategories,
                                    $autoCreateBrands,
                                    $defaultCategory,
                                    $useDefaultCategory,
                                    $immutableFields,
                                    $searchByNameInVariations,
                                    $hasVariation,
                                    $supplierStockFields,
                                    $nameTrimSymbol,
                                    $existingVariation,
                                    $searchByFieldInVariations,
                                    $skipImagesOnUpdateIfExists,
                                    $naming
                                );

                                $results['imagesDownloaded'] += $updateResult['imageStats']['downloaded'];
                                $results['imagesFailed'] += $updateResult['imageStats']['failed'];
                                $results['updated']++;

                                if (!empty($sku)) {
                                    $results['goodIds'][$sku] = $goodToUpdate->id;
                                }
                                if (isset($goodData['_row'])) {
                                    $results['goodIds'][$goodData['_row']] = $goodToUpdate->id;
                                }

                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $goodToUpdate->id, 'supplier' => ($goodToUpdate->supplier ?? '')];

                                continue;
                            }
                        }

                        // Товар действительно не существует - создаем новый (с вариацией или без)
                        $createResult = $this->createGood($goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $supplierStockFields, $nameTrimSymbol, $naming);
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
                            if (
                                isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                            ) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // Сортируем атрибуты для консистентности ключа
                                $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function ($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $newGood->lastVariationId;
                            }
                        }

                        // Добавляем в группу для загрузки
                        $sheet = $goodData['_sheet'] ?? 'неизвестно';
                        $loadItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'supplier' => ($newGood->supplier ?? ($goodData['supplier_name'] ?? ''))];
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
                        if (!$existingGood && in_array('name', $duplicateFields) && !empty($name)) {
                            $existingGood = ShopGood::where('name', $this->normalizeTextForSearch($name))->first();
                        }
                        if (!$existingGood && empty($sku) && in_array('name', $duplicateFields) && !empty($name)) {
                            $existingGood = ShopGood::whereNull('sku')->where('name', $name)->first();
                        }

                        if ($existingGood) {
                            // Товар найден - обрабатываем только вариацию
                            try {
                                // Передаем nameTrimSymbol в goodData для обрезки названия товара
                                if (!isset($goodData['_nameTrimSymbol'])) {
                                    $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
                                }
                                $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields, $naming);
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
                                    if (
                                        isset($goodData['sku']) && !empty($goodData['sku']) &&
                                        isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                        is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                                    ) {

                                        $sku = trim($goodData['sku']);
                                        $attributes = $goodData['variation']['attributes'];

                                        // Сортируем атрибуты для консистентности ключа
                                        $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                            return $attr['name'] ?? '';
                                        })->map(function ($attr) {
                                            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                        })->join('|');

                                        $variationKey = $sku . ':::' . $sortedAttributes;
                                        $results['variationIds'][$variationKey] = $variationId;
                                    }
                                }

                                // Добавляем в группу для обновления
                                $sheet = $goodData['_sheet'] ?? 'неизвестно';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];

                                // Пропускаем обработку ошибки, так как вариация успешно обработана
                                continue;
                            } catch (\Exception $variationError) {
                                // Если обработка вариации тоже не удалась - логируем ошибку
                                $results['failed']++;
                                $results['errors'][] = [
                                    'row' => $count,
                                    'sku' => $sku,
                                    'error' => 'Товар найден, но не удалось обработать вариацию: ' . $variationError->getMessage(),
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
                        'error' => $e->getMessage(),
                    ];

                    // Добавляем в группу для ошибок
                    $sheet = $goodData['_sheet'] ?? 'неизвестно';
                    $errorItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'error' => $e->getMessage()];
                }
            }

            if ($stockSource && $stockFullSync && $stockSyncFinalize && $stockImportRunId) {
                $results['stockSync'] = $this->reconcileMissingStockSourceItems($stockSource, $stockImportRunId, $effectiveStockSyncFields);
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
                'results' => $results,
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
                    'message' => "Обнулены остатки {$resetStats['updated_goods']} товаров и {$resetStats['updated_variations']} вариаций поставщика '{$resetStats['supplier_name']}'",
                ];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            DB::rollBack();

            // Логируем общую ошибку
            $this->importLogService->logGeneralError('Общая ошибка импорта: ' . $e->getMessage());

            // Логируем ошибки загрузки файлов отдельно
            $errorMessage = $e->getMessage();
            if (
                strpos($errorMessage, 'file') !== false ||
                strpos($errorMessage, 'upload') !== false ||
                strpos($errorMessage, 'parse') !== false ||
                strpos($errorMessage, 'read') !== false
            ) {
                $this->importLogService->logFileLoadingError($errorMessage);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при импорте: ' . $e->getMessage(),
                'results' => $results,
            ], 500);
        }
    }

    public function auditOneCDuplicates(Request $request)
    {
        $source = $this->normalizeStockSource($request->input('source', '1c')) ?? '1c';
        $supplier = trim((string) $request->input('supplier', $source));
        $limit = (int) $request->input('limit', 100);
        $limit = max(10, min($limit, 500));

        $productSkuGroups = DB::table('shop_goods as goods')
            ->select('goods.sku', DB::raw('COUNT(*) as total'))
            ->whereNotNull('goods.sku')
            ->where('goods.sku', '<>', '')
            ->whereExists(function ($query) use ($source, $supplier) {
                $query->select(DB::raw(1))
                    ->from('shop_goods as source_goods')
                    ->whereColumn('source_goods.sku', 'goods.sku')
                    ->where(function ($q) use ($source, $supplier) {
                        $q->where('source_goods.supplier', $supplier)
                            ->orWhere('source_goods.stock_source', $source);
                    });
            })
            ->groupBy('goods.sku')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $productDuplicates = $productSkuGroups->map(function ($group) {
            return [
                'sku' => $group->sku,
                'total' => (int) $group->total,
                'items' => ShopGood::query()
                    ->where('sku', $group->sku)
                    ->select('id', 'name', 'sku', 'supplier', 'stock_source', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'price', 'sale_price', 'updated_at')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($good) => $this->formatAuditGood($good))
                    ->values(),
            ];
        })->values();

        $variationSkuGroups = DB::table('shop_good_variations as variations')
            ->select('variations.good_id', 'variations.sku', DB::raw('COUNT(*) as total'))
            ->whereNotNull('variations.sku')
            ->where('variations.sku', '<>', '')
            ->whereExists(function ($query) use ($source, $supplier) {
                $query->select(DB::raw(1))
                    ->from('shop_good_variations as source_variations')
                    ->whereColumn('source_variations.good_id', 'variations.good_id')
                    ->whereColumn('source_variations.sku', 'variations.sku')
                    ->where(function ($q) use ($source, $supplier) {
                        $q->where('source_variations.supplier', $supplier)
                            ->orWhere('source_variations.stock_source', $source);
                    });
            })
            ->groupBy('variations.good_id', 'variations.sku')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $variationSkuDuplicates = $variationSkuGroups->map(function ($group) {
            $good = ShopGood::select('id', 'name', 'sku')->find($group->good_id);

            return [
                'good' => $good ? $this->formatAuditGood($good) : null,
                'sku' => $group->sku,
                'total' => (int) $group->total,
                'items' => ShopGoodVariation::query()
                    ->where('good_id', $group->good_id)
                    ->where('sku', $group->sku)
                    ->select('id', 'good_id', 'name', 'sku', 'supplier', 'stock_source', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'price', 'sale_price', 'updated_at')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($variation) => $this->formatAuditVariation($variation))
                    ->values(),
            ];
        })->values();

        $attributeDuplicates = $this->findVariationAttributeDuplicateGroups($source, $supplier, $limit);

        return response()->json([
            'success' => true,
            'data' => [
                'source' => $source,
                'supplier' => $supplier,
                'summary' => [
                    'product_sku_groups' => $productDuplicates->count(),
                    'variation_sku_groups' => $variationSkuDuplicates->count(),
                    'variation_attribute_groups' => count($attributeDuplicates),
                ],
                'product_sku_duplicates' => $productDuplicates,
                'variation_sku_duplicates' => $variationSkuDuplicates,
                'variation_attribute_duplicates' => $attributeDuplicates,
            ],
        ]);
    }

    public function resolveOneCDuplicates(Request $request)
    {
        $entityType = (string) $request->input('entity_type');
        $keepId = (int) $request->input('keep_id');
        $itemIds = collect($request->input('item_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if (!in_array($entityType, ['good', 'variation'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Некорректный тип сущности для разбора дублей.',
            ], 422);
        }

        if ($keepId <= 0 || !$itemIds->contains($keepId) || $itemIds->count() < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Выберите запись, которую нужно оставить, и группу дублей.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($entityType, $keepId, $itemIds) {
                if ($entityType === 'good') {
                    $items = ShopGood::query()
                        ->whereIn('id', $itemIds)
                        ->lockForUpdate()
                        ->get();

                    if ($items->count() !== $itemIds->count()) {
                        throw new \RuntimeException('Часть товаров из группы дублей не найдена.');
                    }

                    if ($items->pluck('sku')->filter()->unique()->count() !== 1) {
                        throw new \RuntimeException('Нельзя автоматически разбирать товары с разными артикулами.');
                    }
                } else {
                    $items = ShopGoodVariation::query()
                        ->whereIn('id', $itemIds)
                        ->lockForUpdate()
                        ->get();

                    if ($items->count() !== $itemIds->count()) {
                        throw new \RuntimeException('Часть вариаций из группы дублей не найдена.');
                    }

                    if ($items->pluck('good_id')->unique()->count() !== 1) {
                        throw new \RuntimeException('Нельзя автоматически разбирать вариации из разных товаров.');
                    }
                }

                $changed = [];
                foreach ($items as $item) {
                    if ((int) $item->id === $keepId) {
                        continue;
                    }

                    $changed[] = $this->zeroDuplicateStock($item);
                }

                $kept = $items->firstWhere('id', $keepId);

                return [
                    'kept' => $entityType === 'good'
                        ? $this->formatAuditGood($kept)
                        : $this->formatAuditVariation($kept),
                    'changed' => $changed,
                ];
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Дубли обнулены. Выбранная запись оставлена без изменений.',
            'data' => $result,
        ]);
    }

    /**
     * Обрезает название товара, если в нем найден указанный символ обрезки
     *
     * @param  ShopGood  $good  Товар для обрезки названия
     * @param  string|null  $nameTrimSymbol  Символ обрезки
     * @param  array  $immutableFields  Поля, которые нельзя изменять
     * @param  string|null  $nameFromData  Название из данных импорта (если отличается от названия в БД)
     * @return bool true если обрезка была применена, false если нет
     */
    private function trimGoodName($good, $nameTrimSymbol = null, $immutableFields = [], $nameFromData = null)
    {
        // Если символ обрезки не указан или товар не найден - ничего не делаем
        if (empty($nameTrimSymbol) || empty(trim($nameTrimSymbol)) || !$good || !$good->id) {
            return false;
        }

        // Если название в immutableFields - не обрезаем
        if (in_array('name', $immutableFields)) {
            return false;
        }

        // КРИТИЧНО: Проверяем название в БД, потому что на фронтенде название уже может быть обрезано
        // Если символ обрезки указан, всегда проверяем название в БД на наличие символа
        $nameInDb = $good->name ?? '';
        $nameToCheck = !empty($nameInDb) && !empty(trim($nameInDb)) ? trim($nameInDb) : null;

        // Если название в БД пустое, но есть название из данных импорта - используем его
        if (empty($nameToCheck) && !empty($nameFromData) && !empty(trim($nameFromData))) {
            $nameToCheck = trim($nameFromData);
        }

        // Если название пустое - ничего не делаем
        if (empty($nameToCheck)) {
            return false;
        }

        $trimSymbol = trim($nameTrimSymbol);

        // Проверяем наличие символа обрезки в названии
        $trimIndex = strpos($nameToCheck, $trimSymbol);

        if ($trimIndex === false) {
            // Символ не найден - обрезка не требуется
            return false;
        }

        // Символ найден - обрезаем название
        $trimmedName = trim(substr($nameToCheck, 0, $trimIndex));

        // Если после обрезки название пустое - не обновляем
        if (empty($trimmedName)) {
            return false;
        }

        // Обновляем название товара ??ерез прямое SQL-обновление
        // Это гарантирует обновление независимо от условий
        try {
            DB::table('shop_goods')
                ->where('id', $good->id)
                ->update(['name' => $trimmedName]);

            // Синхронизируем модель с базой данных
            $good->name = $trimmedName;
            $good->refresh();

            return true;
        } catch (\Exception $e) {
            \Log::error('Ошибка при обрезке названия товара', [
                'good_id' => $good->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function createGood($goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory = null, $useDefaultCategory = false, $supplierStockFields = null, $nameTrimSymbol = null, $naming = 'hash')
    {
        $imageStats = ['downloaded' => 0, 'failed' => 0];

        $good = new ShopGood;
        // Если SKU пустой, устанавливаем null вместо пустой строки
        $good->sku = (isset($goodData['sku']) && !empty($goodData['sku'])) ? $goodData['sku'] : null;

        // Применяем обрезку названия при создании нового товара, если указан символ обрезки
        $nameToSet = isset($goodData['name']) ? $this->normalizeText($goodData['name']) : '';
        if (!empty($nameTrimSymbol) && !empty(trim($nameTrimSymbol)) && !empty($nameToSet)) {
            $trimSymbol = trim($nameTrimSymbol);
            $trimIndex = strpos($nameToSet, $trimSymbol);
            if ($trimIndex !== false) {
                $trimmedName = trim(substr($nameToSet, 0, $trimIndex));
                if (!empty($trimmedName)) {
                    $nameToSet = $trimmedName;
                }
            }
        }
        $good->name = $nameToSet;
        // Используем slug из данных, если он есть и не пустой, иначе генерируем автоматически
        if (isset($goodData['slug']) && !empty($goodData['slug']) && trim($goodData['slug']) !== '') {
            $good->slug = trim($goodData['slug']);
        } else {
            $good->slug = $this->generateSlug($nameToSet, $goodData['sku'] ?? null);
        }
        $good->description = $goodData['description'] ?? null;
        $good->short_description = $goodData['short_description'] ?? null;

        // Для товаров с вариациями устанавливаем временные значения цен и остатков перед сохранением
        // После обработки вариаций значения будут обновлены
        if (!isset($goodData['variation']) || !is_array($goodData['variation'])) {
            // Применяем модификацию цены
            $priceModification = $goodData['price_modification'] ?? null;
            $rawPrice = $goodData['price'] ?? null;

            // Проверяем, это цена передана и не пустая
            if ($rawPrice === null || $rawPrice === '' || $rawPrice === 0) {
                // Логируем предупреждение, если цена не установлена для товара без вариаций
                \Log::warning('Товар создается без вариаций с пустой ценой', [
                    'good_name' => $goodData['name'] ?? 'неизвестно',
                    'good_sku' => $goodData['sku'] ?? 'нет SKU',
                    'price_in_data' => $rawPrice,
                    'has_variation_field' => isset($goodData['variation']),
                    'variation_is_array' => is_array($goodData['variation'] ?? null),
                ]);
            }

            $good->price = $this->applyPriceModification($rawPrice ?? 0, $priceModification['regular'] ?? null);
            // Применяем модификацию акционной цены (даже если sale_price не передана в файле, но есть модификация)
            if (isset($priceModification) && isset($priceModification['sale'])) {
                $good->sale_price = $this->applySalePriceModification($goodData, $priceModification);
            } else {
                $good->sale_price = $goodData['sale_price'] ?? null;
            }
            // Устанавливаем остатки из данных импорта
            if ((isset($goodData['stock_quantity']) || isset($goodData['stock']))) {
                $stockValue = is_numeric($goodData['stock_quantity'] ?? $goodData['stock'] ?? 0) ? (float) ($goodData['stock_quantity'] ?? $goodData['stock'] ?? 0) : 0;
                $good->stock_quantity = $stockValue;
            }

            if (isset($goodData['remote_stock_quantity'])) {
                $remoteValue = ($goodData['remote_stock_quantity'] ?? null) !== null && is_numeric($goodData['remote_stock_quantity'] ?? null) ? (string) ($goodData['remote_stock_quantity'] ?? null) : ($goodData['remote_stock_quantity'] ?? null);
                $good->remote_stock_quantity = $remoteValue;
            }

            if (isset($goodData['fast_remote_stock_quantity'])) {
                $fastRemoteValue = ($goodData['fast_remote_stock_quantity'] ?? null) !== null && is_numeric($goodData['fast_remote_stock_quantity'] ?? null) ? (string) ($goodData['fast_remote_stock_quantity'] ?? null) : ($goodData['fast_remote_stock_quantity'] ?? null);
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
                    'sort_order' => 0,
                ]
            );

        }

        $this->markStockImport(
            $good,
            $goodData['_stock_source'] ?? null,
            $goodData['_stock_import_run_id'] ?? null,
            $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
            $goodData
        );

        $good->save();

        // Обрабатываем категории
        // ВАЖНО: категории уже обработаны в applyCategoryAndBrandIds, где:
        // - Категории из файла найдены/созданы и преобразованы в ID
        // - Категория по умолчанию применена, если нужно
        // Здесь мы просто извлекаем уже обработанные категории
        $categoryIds = [];

        if (isset($goodData['category']) && !empty($goodData['category'])) {
            // Одиночная категория - значение уже должно быть ID после applyCategoryAndBrandIds
            $categoryIds = [(int) $goodData['category']];
        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
            // Множественные категории - значения уже должны быть ID после applyCategoryAndBrandIds
            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
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

        // Обрабатываем вариации товара ДО изображений: при создании с вариацией картинки вешаем на вариацию
        $variationId = null;
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            if (!isset($goodData['_nameTrimSymbol'])) {
                $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
            }
            $variationId = $this->processVariation($good, $goodData['variation'], $goodData, $supplierStockFields, $naming);

            if ($variationId) {
                // КРИТИЧНО: Берем цену из вариации, но если её нет - пробуем из goodData['price']
                // Это нужно, если цена не была передана в variation.price, но есть в основном товаре
                $variationPrice = $goodData['variation']['price'] ?? null;
                if ($variationPrice === null || $variationPrice === '' || $variationPrice === 0) {
                    // Если цена вариации не установлена, берем из goodData['price']
                    $variationPrice = $goodData['price'] ?? null;
                }
                // Если и там нет - используем цену из созданной вариации (если она была установлена при создании)
                if (($variationPrice === null || $variationPrice === '' || $variationPrice === 0) && $variationId) {
                    $createdVariation = ShopGoodVariation::find($variationId);
                    if ($createdVariation && $createdVariation->price && $createdVariation->price > 0) {
                        $variationPrice = $createdVariation->price;
                    }
                }
                $good->price = $variationPrice ?? 0;
                $good->sale_price = $goodData['variation']['sale_price'] ?? $goodData['sale_price'] ?? null;
                $stockValue = is_numeric($goodData['variation']['stock_quantity'] ?? 0) ? (float) ($goodData['variation']['stock_quantity'] ?? 0) : 0;
                $good->stock_quantity = $stockValue;
                $remoteValue = $goodData['variation']['remote_stock_quantity'] ?? null;
                if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                    $good->remote_stock_quantity = is_numeric($remoteValue) ? (string) $remoteValue : $remoteValue;
                }
                $fastRemoteValue = $goodData['variation']['fast_remote_stock_quantity'] ?? null;
                if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                    $good->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string) $fastRemoteValue : $fastRemoteValue;
                }
                $good->save();
            } else {
                // КРИТИЧНО: Если вариация не была создана, но товар создан с hasVariation=true,
                // устанавливаем цену из goodData['price'], чтобы не оставлять цену 0
                // Это может произойти, если вариация не создалась из-за ошибки или отсутствия атрибутов
                $priceModification = $goodData['price_modification'] ?? null;
                $rawPrice = $goodData['price'] ?? null;
                if ($rawPrice !== null && $rawPrice !== '' && $rawPrice !== 0) {
                    $good->price = $this->applyPriceModification($rawPrice, $priceModification['regular'] ?? null);
                    \Log::warning('Вариация не создана, но товар создан с hasVariation=true. Установлена цена из goodData[price]', [
                        'good_id' => $good->id,
                        'good_name' => $good->name,
                        'good_sku' => $good->sku,
                        'price_set' => $good->price,
                    ]);
                } else {
                    \Log::warning('Вариация не создана, и цена в goodData отсутствует. Товар создан с ценой 0', [
                        'good_id' => $good->id,
                        'good_name' => $good->name,
                        'good_sku' => $good->sku,
                    ]);
                }
            }
        }

        // Обрабатываем изображения: при создании с вариацией — к вариации, иначе к товару
        if (isset($goodData['images']) && is_array($goodData['images'])) {
            $variationForImages = $variationId ? ShopGoodVariation::find($variationId) : null;
            $imageStats = $this->processImages($good, $goodData['images'], $variationForImages, false, $naming);
        }

        // Обрабатываем свойства товаров
        if (isset($goodData['properties']) && is_array($goodData['properties'])) {
            $this->processProperties($good, $goodData['properties']);
        }

        // Сохраняем ID вариации в объекте товара для последующего использования
        if ($variationId) {
            $good->lastVariationId = $variationId;
        }

        return ['good' => $good, 'imageStats' => $imageStats];
    }

    private function updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory = null, $useDefaultCategory = false, $immutableFields = [], $searchByNameInVariations = false, $hasVariation = false, $supplierStockFields = null, $nameTrimSymbol = null, $attachImagesToVariation = null, $searchByFieldInVariations = null, $skipImagesIfExistsOnUpdate = false, $naming = 'hash')
    {
        $imageStats = ['downloaded' => 0, 'failed' => 0];
        $goodData = $this->removeBlankValuesForPartialUpdate($goodData);

        // Обновляем все поля из goodData, которые переданы в импорте
        // Это позволяет обновлять все поля, отмеченные для импорта, а не только те, это используются для поиска

        // Обновляем SKU (артикул) - если передан и не в списке неизменяемых, обновляем.
        // КРИТИЧНО: При поиске в вариациях по артикулу (SKU) и наличии вариаций у товара,
        // SKU из файла относится к вариации, а не к основному товару. Не обновляем SKU основного товара,
        // чтобы избежать конфликта с уникальным индексом (SKU уже установлен в вариации).
        // Не «восстанавливаем» артикул: если у товара в БД он пустой, а в файле — непустой,
        // не перезаписываем (пользователь мог намеренно очистить; значение в файле могло
        // остаться от вариации/старого значения).
        if (isset($goodData['sku']) && !in_array('sku', $immutableFields)) {
            // Пропускаем обновление SKU основного товара, если найден товар с вариациями по артикулу
            // (в этом случае SKU относится к вариации, а не к основному товару)
            $skipSkuUpdate = ($searchByNameInVariations && $searchByFieldInVariations === 'sku' && $existingGood->variations()->exists());

            if (!$skipSkuUpdate) {
                $newSku = !empty($goodData['sku']) ? trim($goodData['sku']) : null;
                $existingIsEmpty = ($existingGood->sku === null || trim((string) ($existingGood->sku ?? '')) === '');
                $wouldRestore = ($newSku !== null && $newSku !== '' && $existingIsEmpty);
                if (!$wouldRestore) {
                    $existingGood->sku = $newSku;
                }
            }
        }

        // КРИТИЧНО: Обрабатываем название. При поиске в вариациях по артикулу (SKU) поле name основного товара
        // не затрагиваем только у товаров С вариациями. У товаров без вариаций имя обновляется.
        if (!($searchByNameInVariations && $searchByFieldInVariations === 'sku' && $existingGood->variations()->exists())) {
            if (isset($goodData['name']) && !empty(trim($goodData['name']))) {
                $nameFromData = $this->normalizeText($goodData['name']);
                // Прямое обновление имени из файла (если name не в «Не изменять поля»)
                if (!in_array('name', $immutableFields)) {
                    $existingGood->name = $nameFromData;
                }
                // Применяем обрезку ??ерез trimGoodName, если в названии есть символ обрезки
                $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);
                unset($goodData['name']);
            } else {
                // Если название не передано в данных, но есть символ обрезки — проверяем название в БД
                if (!empty($nameTrimSymbol) && !empty(trim($nameTrimSymbol))) {
                    $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, null);
                }
            }
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
                $existingGood->stock_quantity = is_numeric($stockValue) ? (float) $stockValue : $existingGood->stock_quantity;
            }

            if (!in_array('remote_stock_quantity', $immutableFields)) {
                $remoteValue = $goodData['variation']['remote_stock_quantity'] ?? null;
                // Обновляем только если значение не пустое
                if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                    $existingGood->remote_stock_quantity = is_numeric($remoteValue) ? (string) $remoteValue : $remoteValue;
                }
            }

            if (!in_array('fast_remote_stock_quantity', $immutableFields)) {
                $fastRemoteValue = $goodData['variation']['fast_remote_stock_quantity'] ?? null;
                // Обновляем только если значение не пустое
                if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                    $existingGood->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string) $fastRemoteValue : $fastRemoteValue;
                }
            }
        } else {
            // Для товаров без вариаций остатки берутся из goodData с конвертацией типов
            // Обновляем остатки из данных импорта, если они присутствуют
            if ((isset($goodData['stock_quantity']) || isset($goodData['stock'])) && !in_array('stock_quantity', $immutableFields)) {
                $stockValue = $goodData['stock_quantity'] ?? $goodData['stock'] ?? $existingGood->stock_quantity;
                $existingGood->stock_quantity = is_numeric($stockValue) ? (float) $stockValue : $existingGood->stock_quantity;
            }

            if (isset($goodData['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                $remoteValue = $goodData['remote_stock_quantity'];
                // Обновляем только если значение не пустое
                if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                    $existingGood->remote_stock_quantity = is_numeric($remoteValue) ? (string) $remoteValue : $remoteValue;
                }
            }

            if (isset($goodData['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                $fastRemoteValue = $goodData['fast_remote_stock_quantity'];
                // Обновляем только если значение не пустое
                if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                    $existingGood->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string) $fastRemoteValue : $fastRemoteValue;
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
                        'sort_order' => 0,
                    ]
                );

                // Обновляем текстовое поле supplier в товаре
                $existingGood->supplier = $supplierName;

            } else {
                $existingGood->supplier = null;
            }
        }

        // Обновляем все остальные поля из goodData, которые есть в fillable модели
        // Это гарантирует, это все поля, отмеченные для импорта, будут обновлены
        // Используем список fillable полей из модели ShopGood
        $fillableFields = $existingGood->getFillable();

        // Список полей, которые уже обработаны выше (чтобы не дублировать)
        $alreadyProcessed = [
            'name',
            'slug',
            'sku',
            'description',
            'short_description',
            'price',
            'sale_price',
            'demping_price',
            'show_demping',
            'label_id',
            'stock_quantity',
            'remote_stock_quantity',
            'fast_remote_stock_quantity',
            'width',
            'height',
            'depth',
            'weight',
            'meta_title',
            'meta_description',
            'is_active',
            'is_featured',
            'is_new',
            'is_sale',
            'is_preorder',
            'sort_order',
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

            // ВАЖНО: Пропускаем поле 'name', так как оно уже обработано выше с обрезкой
            // Если обновить его здесь, оно перезапишет обрезанное значение необрезанным
            if ($field === 'name') {
                continue;
            }

            // Если поле есть в goodData, обновляем его
            if (isset($goodData[$field])) {
                $existingGood->$field = $goodData[$field];
            }
        }

        $this->markStockImport(
            $existingGood,
            $goodData['_stock_source'] ?? null,
            $goodData['_stock_import_run_id'] ?? null,
            $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
            $goodData
        );

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
            $categoryIds = [(int) $goodData['category']];
        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
            // Множественные категории - значения уже должны быть ID после processCategoriesAndBrandsBatch
            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                return $id > 0;
            });
        }

        // Обрабатываем категории в функции updateGood
        // ВАЖНО: Проверяем существующие категории ПЕРЕД применением категорий из файла
        $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();

        // Если категории есть в $goodData, проверяем, не была ли это категория по умолчанию
        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
            $defaultCategoryId = (int) $defaultCategory;
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
                $categoryIds = [(int) $defaultCategory];
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

        // Обрабатываем изображения. Если передан $attachImagesToVariation (при «Поиск в вариациях»),
        // привязываем изображения к вариации, а не к основному товару.
        if (isset($goodData['images']) && is_array($goodData['images'])) {
            $imageStats = $this->processImages($existingGood, $goodData['images'], $attachImagesToVariation, $skipImagesIfExistsOnUpdate, $naming);
        }

        // Обрабатываем свойства товаров
        if (isset($goodData['properties']) && is_array($goodData['properties'])) {
            $this->processProperties($existingGood, $goodData['properties']);
        }

        // Обрабатываем вариации товара
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            // Передаем nameTrimSymbol в goodData для обрезки названия товара
            if (!isset($goodData['_nameTrimSymbol'])) {
                $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
            }
            $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields, $naming);
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
                        $variation->stock_quantity = is_numeric($stockValue) ? (float) $stockValue : 0;
                    }
                }
                if (!in_array('remote_stock_quantity', $immutableFields)) {
                    $remoteValue = $goodData['remote_stock_quantity'] ?? null;
                    // Обновляем только если значение не пустое
                    if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                        $variation->remote_stock_quantity = is_numeric($remoteValue) ? (string) $remoteValue : $remoteValue;
                    }
                }
                if (!in_array('fast_remote_stock_quantity', $immutableFields)) {
                    $fastRemoteValue = $goodData['fast_remote_stock_quantity'] ?? null;
                    // Обновляем только если значение не пустое
                    if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                        $variation->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string) $fastRemoteValue : $fastRemoteValue;
                    }
                }

                $this->markStockImport(
                    $variation,
                    $goodData['_stock_source'] ?? null,
                    $goodData['_stock_import_run_id'] ?? null,
                    $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
                    $goodData
                );
                $variation->save();

            }
        }

        return ['good' => $existingGood, 'imageStats' => $imageStats];
    }

    /**
     * При обновлении существующих товаров пустые значения из файла не должны очищать
     * уже заполненные поля товара. Явное значение 0/false оставляем: это валидные данные.
     */
    private function removeBlankValuesForPartialUpdate(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->removeBlankValuesForPartialUpdate($value);
                if ($value === []) {
                    unset($data[$key]);
                    continue;
                }

                $data[$key] = $value;
                continue;
            }

            if ($value === null) {
                unset($data[$key]);
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function processCategories($categories, $autoCreate)
    {
        $categoryIds = [];

        foreach ($categories as $category) {
            if (is_numeric($category)) {
                // Это ID категории
                $categoryIds[] = (int) $category;
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
                            'is_active' => true,
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
                $brandIds[] = (int) $brand;
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
                            'is_active' => true,
                        ]);
                        $brandIds[] = $newBrand->id;
                    }
                } else {
                }
            }
        }

        return array_unique($brandIds);
    }

    /**
     * Обрабатывает изображения: загрузка и привязка к товару или вариации.
     *
     * @param  object  $good  Товар (для очистки Excel-изображений и когда $variation пустая)
     * @param  array  $images  Массив URL или путей к изображениям
     * @param  object|null  $variation  Вариация: если передана, изображения привязываются к вариации, а не к товару
     */
    private function processImages($good, $images, $variation = null, $skipIfHasImagesOnUpdate = false, $naming = 'hash')
    {        $stats = ['downloaded' => 0, 'failed' => 0];
        $attachToVariation = $variation !== null;

        $filteredImages = [];
        $seenImageNames = [];
        foreach ($images as $imageUrl) {
            if (empty($imageUrl)) {
                continue;
            }

            $imageUrl = trim((string) $imageUrl);
            if ($imageUrl === '') {
                continue;
            }

            $path = parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl;
            $filename = basename(str_replace('\\', '/', urldecode($path)));
            $uniqueKey = mb_strtolower($filename ?: $imageUrl);

            if ($uniqueKey !== '' && isset($seenImageNames[$uniqueKey])) {
                continue;
            }

            if ($uniqueKey !== '') {
                $seenImageNames[$uniqueKey] = true;
            }

            $filteredImages[] = $imageUrl;
        }
        if (empty($filteredImages)) {
            return $stats;
        }

        if ($skipIfHasImagesOnUpdate) {
            try {
                if ($attachToVariation) {
                    $hasImages = \App\Models\ShopGoodImage::where('variation_id', $variation->id)->exists();
                    if ($hasImages) {
                        $firstImageUrl = $filteredImages[0] ?? null;
                        if ($firstImageUrl !== null) {
                            $this->importLogService->logImageSkipped($firstImageUrl, 'Пропущено по настройке: у вариации уже есть изображения', $good->sku ?? null);
                        }

                        return $stats;
                    }
                } else {
                    $hasImages = \App\Models\ShopGoodImage::where('good_id', $good->id)->exists();
                    if ($hasImages) {
                        $firstImageUrl = $filteredImages[0] ?? null;
                        if ($firstImageUrl !== null) {
                            $this->importLogService->logImageSkipped($firstImageUrl, 'Пропущено по настройке: у товара уже есть изображения', $good->sku ?? null);
                        }

                        return $stats;
                    }
                }
            } catch (\Exception $e) {
                // В случае ошибки проверки продолжаем обычную обработку, чтобы не блокировать импорт
            }
        }

        // Проверяем, есть ли среди новых изображений изображения из Excel
        $hasExcelImages = false;
        foreach ($filteredImages as $imageUrl) {
            if (str_starts_with($imageUrl, '/images/shop/goods/excel_')) {
                $hasExcelImages = true;
                break;
            }
        }

        // Если есть изображения из Excel, удаляем существующие изображения из Excel
        if ($hasExcelImages) {
            if ($attachToVariation) {
                // Привязка к вариации: удаляем только изображения этой вариации из Excel
                $existingExcelImages = ShopGoodImage::where('variation_id', $variation->id)
                    ->where('file_path', 'like', '/images/shop/goods/excel_%')
                    ->get();
            } else {
                // Привязка к товару: удаляем ТОЛЬКО изображения самого товара из Excel (НЕ трогаем вариации)
                $existingExcelImages = ShopGoodImage::where('good_id', $good->id)
                    ->whereNull('variation_id')
                    ->where('file_path', 'like', '/images/shop/goods/excel_%')
                    ->get();
            }

            foreach ($existingExcelImages as $existingImage) {
                $filePath = str_replace('/images/', '', $existingImage->file_path);
                $fullPath = frontend_public_path('images/' . $filePath);
                if (file_exists($fullPath)) {
                    unlink($fullPath); // Удаляем файл
                }
                $existingImage->delete(); // Удаляем запись
            }
        }

        $imageRecord = static function ($filePath) use ($attachToVariation, $good, $variation) {
            if ($attachToVariation) {
                return [
                    'good_id' => null,
                    'variation_id' => $variation->id,
                    'file_path' => $filePath,
                    'is_main' => false,
                    'sort_order' => 0,
                ];
            }

            return [
                'good_id' => $good->id,
                'variation_id' => null,
                'file_path' => $filePath,
                'is_main' => false,
                'sort_order' => 0,
            ];
        };

        foreach ($filteredImages as $imageUrl) {            if (str_starts_with($imageUrl, '/')) {
                ShopGoodImage::create($imageRecord($imageUrl));
                $stats['downloaded']++;
            } else {
                try {
                    $downloadResponse = $this->downloadImage($imageUrl, $naming);
                    if ($downloadResponse && isset($downloadResponse['data']['path'])) {
                        if (isset($downloadResponse['data']['skipped']) && $downloadResponse['data']['skipped']) {                             $this->importLogService->logImageSkipped($imageUrl, 'Файл уже существует на диске (пропущено API)', $good->sku ?? null);
                        }
                        ShopGoodImage::create($imageRecord($downloadResponse['data']['path']));
                        if (!(isset($downloadResponse['data']['skipped']) && $downloadResponse['data']['skipped'])) {
                            $stats['downloaded']++;
                        }
                    } else {
                        ShopGoodImage::create($imageRecord($imageUrl));
                        $stats['failed']++;
                    }
                } catch (\Exception $e) {
                    ShopGoodImage::create($imageRecord($imageUrl));
                    $stats['failed']++;
                }
            }
        }

        return $stats;
    }

    private function downloadImage($imageUrl, $naming = 'hash')
    {
        // Используем тот же метод, что и в ShopGoodsController
        $response = Http::timeout(30)->post(url('/api/admin/shop/goods/download-image'), [
            'imageUrl' => $imageUrl,
            'storagePath' => '/shop/goods',
            'optimize' => true,
            'naming' => $naming,
            'resize' => 'no_change',
            'width' => null,
            'height' => null,
        ], [
            'Authorization' => 'Bearer ' . request()->bearerToken(),
            'Content-Type' => 'application/json',
        ]);

        if ($response->successful()) {        } else {        }

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
                    // НО самую строку с > не добавляем в список для создания
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
                        // НО самую строку с > не добавляем в список для создания
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
                        // НО самую строку с > не добавляем в список для создания
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
        $existingCategories = ShopCategory::where(function ($query) use ($allCategories) {
            $query->whereIn('name', $allCategories->toArray())
                ->orWhereIn('slug', $allCategories->map(function ($name) {
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
        // Они обрабатываются ??ерез processCategoryHierarchy в applyCategoryAndBrandIds
        $createdCategories = [];
        if ($autoCreate) {
            $categoriesToCreate = $allCategories->filter(function ($name) use ($categoryMap) {
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
                    'is_active' => true,
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
        $existingBrands = ShopBrand::where(function ($query) use ($allBrands) {
            $query->whereIn('name', $allBrands->toArray())
                ->orWhereIn('slug', $allBrands->map(function ($name) {
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
            $brandsToCreate = $allBrands->filter(function ($name) use ($brandMap) {
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
                    'is_active' => true,
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
        $categoryMapArray = $categoryMap instanceof \Illuminate\Support\Collection ? $categoryMap->toArray() : (array) $categoryMap;
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
                } elseif (is_numeric($good['categories']) && (int) $good['categories'] > 0) {
                    $hadCategoryInFile = true;
                }
            }

            // Обрабатываем категории
            if (isset($good['category']) && !empty($good['category'])) {
                // Если категория - строка или число, преобразуем в строку для обработки
                $categoryValue = (string) $good['category'];
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
                                        'is_active' => true,
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
            } elseif (isset($good['categories'])) {
                if (is_string($good['categories'])) {
                    $categoryNames = $this->parseDelimitedString($good['categories']);
                    $categoryIds = [];

                    foreach ($categoryNames as $categoryName) {
                        // Преобразуем в строку на всякий случай
                        $categoryName = (string) $categoryName;
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
                                        'is_active' => true,
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
                                        'is_active' => true,
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
                    if (is_numeric($good['category']) && (int) $good['category'] > 0) {
                        $hasValidCategory = true;
                    }
                    // Если category - это строка (не обработана), проверяем это она не пустая
                    elseif (is_string($good['category']) && trim($good['category']) !== '') {
                        $hasValidCategory = true;
                    }
                }

                // Проверяем массив categories (после обработки должны быть только ID)
                if (!$hasValidCategory && isset($good['categories'])) {
                    if (is_array($good['categories'])) {
                        // Фильтруем только валидные ID (числа > 0)
                        $validCategoryIds = array_filter($good['categories'], function ($cat) {
                            return is_numeric($cat) && (int) $cat > 0;
                        });
                        if (!empty($validCategoryIds)) {
                            $hasValidCategory = true;
                        }
                    } elseif (is_numeric($good['categories']) && (int) $good['categories'] > 0) {
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

                    // Убеждаемся, это категория по умолчанию еще не добавлена
                    $defaultCategoryId = (int) $defaultCategory;
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
                            $brandIds[] = (int) $brand;
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

        // Поддерживаем различные разделители: запятая, точка с запятой, вертикальная ??ерта, перенос строки
        return collect(preg_split('/[,;|\n\r]+/', $str))
            ->map(function ($item) {
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
                        if (
                            $existingBySlug &&
                            $existingBySlug->name === $categoryName &&
                            $existingBySlug->parent_id == $parentId
                        ) {
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
                                'is_active' => true,
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

        // Получаем информацию о том, какие элементы были созданы в этом ??еансе
        $createdCategories = cache('created_categories_' . auth()->id(), []);
        $createdBrands = cache('created_brands_' . auth()->id(), []);

        foreach ($goods as $index => $goodData) {

            // Собираем только те категории, которые были созданы в этом ??еансе
            if (isset($goodData['categories']) && is_array($goodData['categories'])) {
                foreach ($goodData['categories'] as $categoryId) {
                    if (is_numeric($categoryId) && in_array($categoryId, $createdCategories)) {
                        // Ищем название категории по ID в кэше
                        $categoryName = $categoryMap->search(function ($item, $key) use ($categoryId) {
                            return $item == $categoryId;
                        });

                        if ($categoryName && !in_array($categoryName, $newCategories)) {
                            $newCategories[] = $categoryName;
                        }
                    }
                }
            }

            if (isset($goodData['category']) && is_numeric($goodData['category']) && in_array($goodData['category'], $createdCategories)) {
                $categoryName = $categoryMap->search(function ($item, $key) use ($goodData) {
                    return $item == $goodData['category'];
                });

                if ($categoryName && !in_array($categoryName, $newCategories)) {
                    $newCategories[] = $categoryName;
                }
            }

            // Собираем только те бренды, которые были созданы в этом ??еансе
            if (isset($goodData['brands']) && is_array($goodData['brands'])) {
                foreach ($goodData['brands'] as $brandId) {
                    if (is_numeric($brandId) && in_array($brandId, $createdBrands)) {
                        // Ищем название бренда по ID в кэше
                        $brandName = $brandMap->search(function ($item, $key) use ($brandId) {
                            return $item == $brandId;
                        });

                        if ($brandName && !in_array($brandName, $newBrands)) {
                            $newBrands[] = $brandName;
                        }
                    }
                }
            }

            if (isset($goodData['brand']) && is_numeric($goodData['brand']) && in_array($goodData['brand'], $createdBrands)) {
                $brandName = $brandMap->search(function ($item, $key) use ($goodData) {
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
                            'is_active' => true,
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
                            'shop_property_value_id' => $propertyValue->id,
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
     * Создаёт или обновляет вариацию без атрибутов (только sku, цена, остатки).
     * Используется при «Поиск в вариациях» по SKU: товар найден по названию — добавляем вариацию по артикулу из строки.
     * ??трибуты вариации (Размер, Цвет и т.п.) не требуются.
     *
     * @return int|null ID вариации или null при ошибке
     */
    private function createSimpleVariation($good, $goodData, $supplierStockFields = null, $naming = 'hash')
    {
        try {
            $nameTrimSymbol = $goodData['_nameTrimSymbol'] ?? null;
            if (empty($nameTrimSymbol)) {
                try {
                    $request = request();
                    $nameTrimSymbol = $request ? $request->input('name_trim_symbol') : null;
                } catch (\Exception $e) {
                    $nameTrimSymbol = null;
                }
            }
            $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
            $this->trimGoodName($good, $nameTrimSymbol, [], $nameFromData);

            $sku = isset($goodData['sku']) && (string) $goodData['sku'] !== '' ? trim((string) $goodData['sku']) : null;
            if (!$sku) {
                return null;
            }

            $variationPrice = $goodData['price'] ?? $good->price ?? 0;
            $variationSalePrice = $goodData['sale_price'] ?? null;
            $variationStockQuantity = isset($goodData['stock_quantity']) && is_numeric($goodData['stock_quantity']) ? (float) $goodData['stock_quantity'] : 0;
            // Важно: поля удалённых остатков храним как строки "как есть"
            // Если значение числовое — приводим к строке, иначе используем исходную строку
            $variationRemoteStockQuantity = isset($goodData['remote_stock_quantity']) ? (
                $goodData['remote_stock_quantity'] !== null && is_numeric($goodData['remote_stock_quantity'])
                ? (string) $goodData['remote_stock_quantity']
                : $goodData['remote_stock_quantity']
            ) : null;
            $variationFastRemoteStockQuantity = isset($goodData['fast_remote_stock_quantity']) ? (
                $goodData['fast_remote_stock_quantity'] !== null && is_numeric($goodData['fast_remote_stock_quantity'])
                ? (string) $goodData['fast_remote_stock_quantity']
                : $goodData['fast_remote_stock_quantity']
            ) : null;
            $variationSupplier = isset($goodData['supplier_name']) && trim((string) $goodData['supplier_name']) !== '' ? trim((string) $goodData['supplier_name']) : null;
            $ignoreSupplierForStockSource = !empty($goodData['_stock_source']);

            $existing = ShopGoodVariation::where('good_id', $good->id)->where('sku', $sku);
            if ($variationSupplier && !$ignoreSupplierForStockSource) {
                $existing->where('supplier', $variationSupplier);
            }
            $existing = $existing->first();

            if ($existing) {
                $existing->price = $variationPrice;
                $existing->sale_price = $variationSalePrice;
                if ($supplierStockFields && !empty($supplierStockFields['stock_quantity'])) {
                    $existing->stock_quantity = $variationStockQuantity;
                }
                if (isset($goodData['remote_stock_quantity']) || $variationRemoteStockQuantity !== null) {
                    $existing->remote_stock_quantity = $variationRemoteStockQuantity;
                }
                if (isset($goodData['fast_remote_stock_quantity']) || $variationFastRemoteStockQuantity !== null) {
                    $existing->fast_remote_stock_quantity = $variationFastRemoteStockQuantity;
                }
                if ($variationSupplier) {
                    $existing->supplier = $variationSupplier;
                }
                $this->markStockImport(
                    $existing,
                    $goodData['_stock_source'] ?? null,
                    $goodData['_stock_import_run_id'] ?? null,
                    $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
                    $goodData
                );
                $existing->save();

                return $existing->id;
            }

            $maxSortOrder = $good->variations()->max('sort_order');
            $sortOrder = ($maxSortOrder !== null ? $maxSortOrder + 1 : 0);

            $toCreate = [
                'good_id' => $good->id,
                'supplier' => $variationSupplier,
                'name' => $good->name,
                'sku' => $sku,
                'price' => $variationPrice,
                'sale_price' => $variationSalePrice,
                'stock_quantity' => $variationStockQuantity,
                'weight' => $good->weight ?? null,
                'length' => $good->length ?? null,
                'height' => $good->height ?? null,
                'width' => $good->width ?? null,
                'is_active' => true,
                'sort_order' => $sortOrder,
            ];
            if (($goodData['_stock_source'] ?? null) && $this->hasImportedStockField($goodData, $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'])) {
                $toCreate['stock_source'] = $goodData['_stock_source'];
                $toCreate['last_stock_import_run_id'] = $goodData['_stock_import_run_id'] ?? null;
                $toCreate['last_stock_import_at'] = now();
            }
            if (isset($goodData['remote_stock_quantity']) || $variationRemoteStockQuantity !== null) {
                $toCreate['remote_stock_quantity'] = $variationRemoteStockQuantity;
            }
            if (isset($goodData['fast_remote_stock_quantity']) || $variationFastRemoteStockQuantity !== null) {
                $toCreate['fast_remote_stock_quantity'] = $variationFastRemoteStockQuantity;
            }

            $variation = ShopGoodVariation::create($toCreate);

            return $variation->id;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Обработка вариации товара при импорте
     *
     * @return int|null ID вариации или null, если вариация не была создана/обновлена
     */
    private function processVariation($good, $variationData, $goodData, $supplierStockFields = null, $naming = 'hash')
    {
        try {
            // КРИТИЧНО: Обрезаем и обновляем название товара ПЕРЕД обработкой вариации
            // Это гарантирует, это обрезка будет применена для всех товаров с вариациями
            // Получаем nameTrimSymbol из goodData или из глобального контекста
            $nameTrimSymbol = $goodData['_nameTrimSymbol'] ?? null;
            if (empty($nameTrimSymbol)) {
                // Пробуем получить из request ??ерез глобальный контекст
                try {
                    $request = request();
                    $nameTrimSymbol = $request ? $request->input('name_trim_symbol') : null;
                } catch (\Exception $e) {
                    $nameTrimSymbol = null;
                }
            }

            // Применяем обрезку названия товара, если указан символ обрезки
            // Используем отдельную функцию для обрезки, которая проверяет наличие символа в названии товара
            $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
            $immutableFields = []; // В processVariation immutableFields не передаются, используем пустой массив
            $this->trimGoodName($good, $nameTrimSymbol, $immutableFields, $nameFromData);

            // Проверяем наличие данных вариации
            if (!isset($variationData['attributes']) || !is_array($variationData['attributes']) || count($variationData['attributes']) === 0) {
                return null;
            }

            $attributes = $variationData['attributes'];
            $priceModification = $goodData["price_modification"] ?? null;
            $rawVarPrice = $variationData["price"] ?? $goodData["price"] ?? $good->price ?? 0;
            $variationPrice = $this->applyPriceModification($rawVarPrice, $priceModification["regular"] ?? null);

            // Sale price calculation
            $tmpData = $goodData;
            $tmpData["price"] = $rawVarPrice;
            $tmpData["sale_price"] = $variationData["sale_price"] ?? $goodData["sale_price"] ?? null;
            $variationSalePrice = $this->applySalePriceModification($tmpData, $priceModification);

            // Конвертируем остатки из строк в числа
            $variationStockQuantity = $variationData['stock_quantity'] ?? $goodData['stock_quantity'] ?? 0;
            $variationStockQuantity = is_numeric($variationStockQuantity) ? (float) $variationStockQuantity : 0;

            $variationRemoteStockQuantity = $variationData['remote_stock_quantity'] ?? $goodData['remote_stock_quantity'] ?? null;
            $variationRemoteStockQuantity = $variationRemoteStockQuantity !== null && is_numeric($variationRemoteStockQuantity) ? (string) $variationRemoteStockQuantity : $variationRemoteStockQuantity;

            $variationFastRemoteStockQuantity = $variationData['fast_remote_stock_quantity'] ?? $goodData['fast_remote_stock_quantity'] ?? null;
            $variationFastRemoteStockQuantity = $variationFastRemoteStockQuantity !== null && is_numeric($variationFastRemoteStockQuantity) ? (string) $variationFastRemoteStockQuantity : $variationFastRemoteStockQuantity;

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
                    // ??трибут не найден - пропускаем эту характеристику
                    continue;
                }

                // Проверяем, это атрибут используется в существующих вариациях (если они есть)
                // Временно отключено для отладки проблемы поиска вариаций без поставщика
                // if ($hasExistingVariations && !in_array($attribute->id, $existingAttributeIds)) {
                //     // ??трибут не используется в существующих вариациях - пропускаем
                //     continue;
                // }

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

            // Получаем поставщика для вариации
            $variationSupplier = isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '' ? trim($goodData['supplier_name']) : null;
            $searchSupplier = !empty($goodData['_stock_source']) ? '__any__' : $variationSupplier;

            // Ищем существующую вариацию с такой же комбинацией атрибутов и поставщиком
            $existingVariation = $this->findVariationByAttributes($good->id, $attributeValueIds, $searchSupplier);

            if ($existingVariation) {
                // Вариация существует - обновляем все поля из goodData

                // Обновляем цену
                $existingVariation->price = $variationPrice;

                // Обновляем акционную цену, если передана
                if (isset($variationData['sale_price'])) {
                    $existingVariation->sale_price = $variationSalePrice;
                } elseif (isset($goodData['sale_price'])) {
                    $existingVariation->sale_price = $goodData['sale_price'];
                }

                // Обновляем остатки
                if ($supplierStockFields && isset($supplierStockFields['stock_quantity']) && $supplierStockFields['stock_quantity']) {
                    $existingVariation->stock_quantity = $variationStockQuantity;
                }
                if (isset($variationData['remote_stock_quantity']) || isset($goodData['remote_stock_quantity'])) {
                    $existingVariation->remote_stock_quantity = $variationRemoteStockQuantity;
                }
                if (isset($variationData['fast_remote_stock_quantity']) || isset($goodData['fast_remote_stock_quantity'])) {
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

                // Обновляем поставщика вариации
                if (isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '') {
                    $existingVariation->supplier = trim($goodData['supplier_name']);
                }

                $this->markStockImport(
                    $existingVariation,
                    $goodData['_stock_source'] ?? null,
                    $goodData['_stock_import_run_id'] ?? null,
                    $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
                    array_merge($goodData, $variationData)
                );

                try {
                    $existingVariation->save();
                } catch (QueryException $e) {
                    // Игнорируем ошибки дублирования SKU для вариаций (дублирование разрешено)
                    if (
                        strpos($e->getMessage(), 'Duplicate entry') !== false ||
                        strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                        $e->getCode() == 23000
                    ) {
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
                    "Цена: {$variationPrice}, Остаток: {$variationStockQuantity}"
                    . ($variationRemoteStockQuantity !== null ? ", Удаленный склад: {$variationRemoteStockQuantity}" : '')
                    . ($variationFastRemoteStockQuantity !== null ? ", Быстрый удаленный склад: {$variationFastRemoteStockQuantity}" : '')
                    . (isset($existingVariation->supplier) && trim((string) $existingVariation->supplier) !== '' ? ", Поставщик: {$existingVariation->supplier}" : '')
                    . (isset($good->id) ? ", ID товара: {$good->id}" : '')
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
                $wasCreated = true;
                try {
                    $variationDataToCreate = [
                        'good_id' => $good->id,
                        'supplier' => isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '' ? trim($goodData['supplier_name']) : null,
                        'name' => $good->name,
                        'sku' => $variationSku,
                        'price' => $variationPrice,
                        'sale_price' => $variationSalePrice,
                        'weight' => $good->weight ?? null,
                        'length' => $good->length ?? null,
                        'height' => $good->height ?? null,
                        'width' => $good->width ?? null,
                        'is_active' => true,
                        'sort_order' => $sortOrder,
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

                    if (($goodData['_stock_source'] ?? null) && $this->hasImportedStockField(array_merge($goodData, $variationData), $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'])) {
                        $variationDataToCreate['stock_source'] = $goodData['_stock_source'];
                        $variationDataToCreate['last_stock_import_run_id'] = $goodData['_stock_import_run_id'] ?? null;
                        $variationDataToCreate['last_stock_import_at'] = now();
                    }

                    $variation = ShopGoodVariation::create($variationDataToCreate);
                } catch (QueryException $e) {
                    // Игнорируем ошибки дублирования SKU для вариаций (дублирование разрешено)
                    if (
                        strpos($e->getMessage(), 'Duplicate entry') !== false ||
                        strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                        $e->getCode() == 23000
                    ) {
                        // Пропускаем ошибку дублирования SKU - это разрешено для вариаций
                        // Пробуем найти существующую вариацию с таким же SKU и обновить её

                        // Ищем существующую вариацию с таким же SKU и поставщиком
                        $supplierName = isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '' ? trim($goodData['supplier_name']) : null;
                        $skuQuery = ShopGoodVariation::where('good_id', $good->id)
                            ->where('sku', $variationSku);
                        if ($supplierName) {
                            $skuQuery->where('supplier', $supplierName);
                        }
                        $existingVariationBySku = $skuQuery->first();

                        if ($existingVariationBySku) {
                            // Обновляем существующую вариацию - все поля из goodData
                            $existingVariationBySku->price = $variationPrice;

                            // Обновляем акционную цену, если передана
                            if (isset($variationData['sale_price'])) {
                                $existingVariationBySku->sale_price = $variationSalePrice;
                            } elseif (isset($goodData['sale_price'])) {
                                $existingVariationBySku->sale_price = $goodData['sale_price'];
                            }

                            // Обновляем остатки только если значения не пустые
                            if ($variationStockQuantity !== null && $variationStockQuantity !== '' && trim($variationStockQuantity) !== '') {
                                $existingVariationBySku->stock_quantity = $variationStockQuantity;
                            }
                            if (
                                (isset($variationData['remote_stock_quantity']) || isset($goodData['remote_stock_quantity'])) &&
                                $variationRemoteStockQuantity !== null && $variationRemoteStockQuantity !== '' && trim($variationRemoteStockQuantity) !== ''
                            ) {
                                $existingVariationBySku->remote_stock_quantity = $variationRemoteStockQuantity;
                            }
                            if (
                                (isset($variationData['fast_remote_stock_quantity']) || isset($goodData['fast_remote_stock_quantity'])) &&
                                $variationFastRemoteStockQuantity !== null && $variationFastRemoteStockQuantity !== '' && trim($variationFastRemoteStockQuantity) !== ''
                            ) {
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

                            // Обновляем поставщика вариации
                            if (isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '') {
                                $existingVariationBySku->supplier = trim($goodData['supplier_name']);
                            }

                            $this->markStockImport(
                                $existingVariationBySku,
                                $goodData['_stock_source'] ?? null,
                                $goodData['_stock_import_run_id'] ?? null,
                                $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
                                array_merge($goodData, $variationData)
                            );

                            try {
                                $existingVariationBySku->save();
                            } catch (QueryException $saveException) {
                                // Игнорируем ошибки дублирования SKU при сохранении
                                if (
                                    strpos($saveException->getMessage(), 'Duplicate entry') !== false ||
                                    strpos($saveException->getMessage(), 'UNIQUE constraint') !== false ||
                                    $saveException->getCode() == 23000
                                ) {
                                    // Пропускаем ошибку дублирования SKU
                                } else {
                                    throw $saveException;
                                }
                            }

                            $variation = $existingVariationBySku;
                            $wasCreated = false;
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
                        'updated_at' => now(),
                    ]);
                }

                // Логируем действие над вариацией
                $this->importLogService->logVariation(
                    $wasCreated ? 'СОЗДАНА ВАРИАЦИЯ' : 'ОБНОВЛЕНА ВАРИАЦИЯ',
                    $good->name,
                    $variationSku ?? 'нет SKU',
                    $attributes,
                    $variation->id,
                    "Цена: {$variationPrice}, Остаток: {$variationStockQuantity}"
                    . ($variationRemoteStockQuantity !== null ? ", Удаленный склад: {$variationRemoteStockQuantity}" : '')
                    . ($variationFastRemoteStockQuantity !== null ? ", Быстрый удаленный склад: {$variationFastRemoteStockQuantity}" : '')
                    . (isset($variation->supplier) && trim((string) $variation->supplier) !== '' ? ", Поставщик: {$variation->supplier}" : '')
                    . (isset($good->id) ? ", ID товара: {$good->id}" : '')
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

            // Возвращаем null при ошибке (функция должна возвращать int|null)
            return null;
        }
    }

    /**
     * Обновить вариацию из данных goodData
     *
     * @param  ShopGoodVariation  $variation  Вариация для обновления
     * @param  array  $goodData  Данные товара
     * @param  bool  $searchByNameInVariations  Если true, поле name не обновляется
     * @return int ID вариации
     */
    private function updateVariationFromGoodData($variation, $goodData, $searchByNameInVariations = false)
    {
        $goodData = $this->removeBlankValuesForPartialUpdate($goodData);

        // Обновляем цену
        if (isset($goodData['price'])) {
            $priceModification = $goodData["price_modification"] ?? null;
            $variation->price = $this->applyPriceModification($goodData["price"], $priceModification["regular"] ?? null);
        }

        // Обновляем акционную цену, если передана
        if (isset($goodData['sale_price'])) {
            if (isset($goodData["sale_price"]) || (isset($priceModification) && isset($priceModification["sale"]))) {
                $variation->sale_price = $this->applySalePriceModification($goodData, $priceModification);
            }
        }

        // Обновляем остатки с конвертацией типов
        if (isset($goodData['stock_quantity'])) {
            $variation->stock_quantity = is_numeric($goodData['stock_quantity']) ? (float) $goodData['stock_quantity'] : 0;
        }
        if (isset($goodData['remote_stock_quantity'])) {
            $variation->remote_stock_quantity = $goodData['remote_stock_quantity'] !== null && is_numeric($goodData['remote_stock_quantity']) ? (string) $goodData['remote_stock_quantity'] : $goodData['remote_stock_quantity'];
        }
        if (isset($goodData['fast_remote_stock_quantity'])) {
            $variation->fast_remote_stock_quantity = $goodData['fast_remote_stock_quantity'] !== null && is_numeric($goodData['fast_remote_stock_quantity']) ? (string) $goodData['fast_remote_stock_quantity'] : $goodData['fast_remote_stock_quantity'];
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
                            try {
                                // Находим или создаем значение атрибута
                                $attributeValueRecord = DB::table('shop_variation_attribute_values')
                                    ->where('attribute_id', $attribute->id)
                                    ->where('value', $attributeValue)
                                    ->first();

                                if (!$attributeValueRecord) {
                                    $newAttributeValueId = DB::table('shop_variation_attribute_values')->insertGetId([
                                        'attribute_id' => $attribute->id,
                                        'value' => $attributeValue,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);

                                    // Проверяем, это insertGetId вернул валидный ID
                                    if ($newAttributeValueId && $newAttributeValueId > 0) {
                                        $attributeValueRecord = (object) ['id' => $newAttributeValueId];
                                    } else {
                                        // Если не удалось создать значение атрибута, пропускаем его
                                        continue;
                                    }
                                }

                                // Добавляем ??вязь вариации с значением атрибута
                                if ($attributeValueRecord && isset($attributeValueRecord->id)) {
                                    DB::table('shop_variation_attributes_values')->insert([
                                        'variation_id' => $variation->id,
                                        'attribute_value_id' => $attributeValueRecord->id,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            } catch (\Exception $e) {
                                // Логируем ошибку, но продолжаем обработку остальных атрибутов
                                \Log::error('Ошибка при обработке атрибута вариации', [
                                    'variation_id' => $variation->id,
                                    'attribute_name' => $attributeName,
                                    'attribute_value' => $attributeValue,
                                    'error' => $e->getMessage(),
                                ]);

                                // Пропускаем этот атрибут и продолжаем со следующим
                                continue;
                            }
                        }
                    }
                }
            }
        }

        $this->markStockImport(
            $variation,
            $goodData['_stock_source'] ?? null,
            $goodData['_stock_import_run_id'] ?? null,
            $goodData['_stock_sync_fields'] ?? ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'],
            $goodData
        );

        try {
            $variation->save();
        } catch (QueryException $e) {
            // Игнорируем ошибки дублирования SKU для вариаций (дублирование разрешено)
            if (
                strpos($e->getMessage(), 'Duplicate entry') === false &&
                strpos($e->getMessage(), 'UNIQUE constraint') === false &&
                $e->getCode() != 23000
            ) {
                // Если это другая ошибка - пробрасываем дальше
                throw $e;
            }
        }

        return $variation->id;
    }

    /**
     * Найти или создать атрибут вариации
     *
     * @param  string  $attributeName  Название атрибута
     * @param  bool  $hasExistingVariations  Есть ли у товара существующие вариации
     * @param  array  $existingAttributeIds  Массив ID существующих атрибутов товара
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
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Найти вариацию по имени/SKU и атрибутам
     * Возвращает вариацию, если найдена с точно совпадающими атрибутами, иначе null
     */
    private function findVariationByNameAndAttributes($searchField, $searchValue, $importAttributes, $supplier = null)
    {
        // Если нет атрибутов для сравнения, возвращаем null (не используем старый поиск)
        if (empty($importAttributes) || !is_array($importAttributes)) {
            return null;
        }

        // Строим запрос для поиска вариаций по имени/SKU
        $query = ShopGoodVariation::where($searchField, $searchField === 'name' ? $this->normalizeTextForSearch($searchValue) : $searchValue);

        // Фильтр по поставщику, если указан
        if ($supplier !== null && $supplier !== '') {
            $query->where('supplier', $supplier);
        }

        // Получаем все найденные вариации
        $variations = $query->get();

        // Для каждой вариации проверяем совпадение атрибутов
        foreach ($variations as $variation) {
            // Получаем атрибуты существующей вариации
            $existingAttributes = $this->getVariationAttributes($variation);

            // Сравниваем атрибуты
            if ($this->attributesMatch($existingAttributes, $importAttributes)) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * Проверяет, совпадают ли два массива атрибутов
     */
    private function attributesMatch($existingAttributes, $importAttributes)
    {
        if (count($importAttributes) !== count($existingAttributes)) {
            return false;
        }

        // Сортируем оба массива для сравнения
        $importAttrsSorted = collect($importAttributes)->sortBy(function ($attr) {
            return $attr['name'] ?? '';
        })->map(function ($attr) {
            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
        })->values()->toArray();

        $existingAttrsSorted = collect($existingAttributes)->sortBy(function ($attr) {
            return $attr['name'] ?? '';
        })->map(function ($attr) {
            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
        })->values()->toArray();

        return $importAttrsSorted === $existingAttrsSorted;
    }

    /**
     * Найти вариацию по комбинации атрибутов
     */
    private function findVariationByAttributes($goodId, $attributeValueIds, $supplier = null)
    {
        // Проверяем, это массив атрибутов не пустой
        if (empty($attributeValueIds)) {
            return null;
        }

        sort($attributeValueIds); // Сортируем для консистентности

        // Сначала ищем вариацию среди вариаций указанного поставщика (если поставщик указан).
        // Специальное значение __any__ используется для источников остатков: поставщик не должен
        // участвовать в поиске, иначе повторный импорт 1С может создавать дубли.
        if ($supplier !== null) {
            $supplierVariations = ShopGoodVariation::where('good_id', $goodId)
                ->when($supplier !== '__any__', function ($query) use ($supplier) {
                    $query->where('supplier', $supplier);
                })
                ->get();

            foreach ($supplierVariations as $variation) {
                // Получаем атрибуты существующей вариации
                $existingAttributeValueIds = DB::table('shop_variation_attributes_values')
                    ->where('variation_id', $variation->id)
                    ->pluck('attribute_value_id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->toArray();

                sort($existingAttributeValueIds);

                // Сравниваем комбинации атрибутов
                if ($existingAttributeValueIds === $attributeValueIds) {
                    return $variation;
                }
            }
        }

        // Если не найдена вариация поставщика или поставщик не указан,
        // ищем среди вариаций без привязанного поставщика (null или пустая строка)
        $nullSupplierVariations = ShopGoodVariation::where('good_id', $goodId)
            ->where(function ($query) {
                $query->whereNull('supplier')
                    ->orWhere('supplier', '');
            })
            ->get();

        foreach ($nullSupplierVariations as $variation) {
            // Получаем атрибуты существующей вариации
            $existingAttributeValueIds = DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->pluck('attribute_value_id')
                ->map(function ($id) {
                    return (int) $id;
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
            // Получаем имя поставщика по ID
            $supplier = ShopSupplier::find($supplierId);
            if (!$supplier) {
                return;
            }
            $supplierName = $supplier->name;

            // Обнуляем остатки на удаленном складе и остатки у/?? быстро у товаров с указанным поставщиком
            ShopGood::where('supplier_id', $supplierId)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null,
                ]);

            // КРИТИЧНО: Обнуляем остатки у вариаций, которые привязаны к поставщику по полю supplier вариации,
            // а не ??ерез ??вязь с товаром. Вариации могут быть привязаны к другому поставщику, ??ем главный товар.
            ShopGoodVariation::where('supplier', $supplierName)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null,
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
                'fast_remote_stock_quantity' => true,
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
                    'message' => 'Нет полей остатков для обнуления',
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

            // Проверяем вариации с указанным поставщиком
            $variationsCount = ShopGoodVariation::where('supplier', $supplierName)->count();

            // Обнуляем остатки вариаций с указанным поставщиком только для выбранных полей
            $variationsUpdated = ShopGoodVariation::where('supplier', $supplierName)
                ->orWhere(function ($query) use ($supplierName) {
                    $query->where(function ($q) {
                        $q->whereNull('supplier')->orWhere('supplier', '');
                    })->whereHas('good', function ($q) use ($supplierName) {
                        $q->where('supplier', $supplierName);
                    });
                })
                ->update($updateFields);

            $updatedVariations = $variationsUpdated;

        } catch (\Exception $e) {
            Log::error('Ошибка при обнулении остатков поставщика', [
                'supplier_name' => $supplierName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return [
            'supplier_name' => $supplierName,
            'updated_goods' => $updatedGoods,
            'updated_variations' => $updatedVariations,
            'reset_fields' => array_keys($supplierStockFields),
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
                'fast_remote_stock_quantity' => null,
            ]);

            $updatedGoods = $goodsUpdated;

            // Обнуляем остатки всех вариаций
            $variationsUpdated = ShopGoodVariation::query()->update([
                'stock_quantity' => 0,
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null,
            ]);

            $updatedVariations = $variationsUpdated;

        } catch (\Exception $e) {
            Log::error('Ошибка при обнулении остатков всех товаров', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return [
            'reset_all' => true,
            'updated_goods' => $updatedGoods,
            'updated_variations' => $updatedVariations,
        ];
    }

    private function reconcileMissingStockSourceItems(string $stockSource, string $stockImportRunId, array $stockFields): array
    {
        $allowedFields = ['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity'];
        $stockFields = array_values(array_intersect($allowedFields, $stockFields));

        if (empty($stockFields)) {
            return [
                'source' => $stockSource,
                'run_id' => $stockImportRunId,
                'updated_goods' => 0,
                'updated_variations' => 0,
                'fields' => [],
                'message' => 'Нет выбранных полей остатков для полной синхронизации',
            ];
        }

        $updateFields = [
            'last_stock_import_run_id' => $stockImportRunId,
            'last_stock_import_at' => now(),
        ];

        if (in_array('stock_quantity', $stockFields, true)) {
            $updateFields['stock_quantity'] = 0;
        }
        if (in_array('remote_stock_quantity', $stockFields, true)) {
            $updateFields['remote_stock_quantity'] = null;
        }
        if (in_array('fast_remote_stock_quantity', $stockFields, true)) {
            $updateFields['fast_remote_stock_quantity'] = null;
        }

        $goodsUpdated = ShopGood::where('stock_source', $stockSource)
            ->where(function ($query) use ($stockImportRunId) {
                $query->whereNull('last_stock_import_run_id')
                    ->orWhere('last_stock_import_run_id', '<>', $stockImportRunId);
            })
            ->update($updateFields);

        $variationsUpdated = ShopGoodVariation::where('stock_source', $stockSource)
            ->where(function ($query) use ($stockImportRunId) {
                $query->whereNull('last_stock_import_run_id')
                    ->orWhere('last_stock_import_run_id', '<>', $stockImportRunId);
            })
            ->update($updateFields);

        return [
            'source' => $stockSource,
            'run_id' => $stockImportRunId,
            'updated_goods' => $goodsUpdated,
            'updated_variations' => $variationsUpdated,
            'fields' => $stockFields,
            'message' => "Полная синхронизация '{$stockSource}': обнулены отсутствующие позиции ({$goodsUpdated} товаров, {$variationsUpdated} вариаций)",
        ];
    }

    private function formatAuditGood($good): array
    {
        return [
            'type' => 'good',
            'id' => $good->id,
            'name' => $good->name,
            'sku' => $good->sku,
            'supplier' => $good->supplier,
            'stock_source' => $good->stock_source ?? null,
            'stock_quantity' => $good->stock_quantity ?? null,
            'remote_stock_quantity' => $good->remote_stock_quantity ?? null,
            'fast_remote_stock_quantity' => $good->fast_remote_stock_quantity ?? null,
            'price' => $good->price ?? null,
            'sale_price' => $good->sale_price ?? null,
            'updated_at' => optional($good->updated_at)->toDateTimeString(),
        ];
    }

    private function formatAuditVariation($variation): array
    {
        return [
            'type' => 'variation',
            'id' => $variation->id,
            'good_id' => $variation->good_id,
            'name' => $variation->name,
            'sku' => $variation->sku,
            'supplier' => $variation->supplier,
            'stock_source' => $variation->stock_source ?? null,
            'stock_quantity' => $variation->stock_quantity ?? null,
            'remote_stock_quantity' => $variation->remote_stock_quantity ?? null,
            'fast_remote_stock_quantity' => $variation->fast_remote_stock_quantity ?? null,
            'price' => $variation->price ?? null,
            'sale_price' => $variation->sale_price ?? null,
            'attributes' => $this->getVariationAttributes($variation),
            'updated_at' => optional($variation->updated_at)->toDateTimeString(),
        ];
    }

    private function zeroDuplicateStock($item): array
    {
        $before = [
            'stock_quantity' => $item->stock_quantity ?? null,
            'remote_stock_quantity' => $item->remote_stock_quantity ?? null,
            'fast_remote_stock_quantity' => $item->fast_remote_stock_quantity ?? null,
        ];

        $item->stock_quantity = 0;
        $item->remote_stock_quantity = null;
        $item->fast_remote_stock_quantity = null;
        $item->save();

        $item->refresh();

        return [
            'type' => $item instanceof ShopGood ? 'good' : 'variation',
            'id' => $item->id,
            'name' => $item->name,
            'before' => $before,
            'after' => [
                'stock_quantity' => $item->stock_quantity ?? null,
                'remote_stock_quantity' => $item->remote_stock_quantity ?? null,
                'fast_remote_stock_quantity' => $item->fast_remote_stock_quantity ?? null,
            ],
        ];
    }

    private function findVariationAttributeDuplicateGroups(string $source, string $supplier, int $limit): array
    {
        $candidateIds = ShopGoodVariation::query()
            ->where(function ($query) use ($source, $supplier) {
                $query->where('supplier', $supplier)
                    ->orWhere('stock_source', $source);
            })
            ->pluck('good_id')
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) {
            return [];
        }

        $variations = ShopGoodVariation::with('good')
            ->whereIn('good_id', $candidateIds)
            ->select('id', 'good_id', 'name', 'sku', 'supplier', 'stock_source', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'price', 'sale_price', 'updated_at')
            ->orderBy('good_id')
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($variations as $variation) {
            $attributes = $this->getVariationAttributes($variation);
            if (empty($attributes)) {
                continue;
            }

            $attributeKey = collect($attributes)
                ->sortBy(fn ($attr) => ($attr['name'] ?? '') . ':' . ($attr['value'] ?? ''))
                ->map(fn ($attr) => ($attr['name'] ?? '') . ':' . ($attr['value'] ?? ''))
                ->join('|');

            $key = $variation->good_id . '::' . $attributeKey;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'good' => $variation->good ? $this->formatAuditGood($variation->good) : null,
                    'attribute_key' => $attributeKey,
                    'items' => [],
                    'has_source_item' => false,
                ];
            }

            $groups[$key]['items'][] = $this->formatAuditVariation($variation);
            if ($variation->supplier === $supplier || ($variation->stock_source ?? null) === $source) {
                $groups[$key]['has_source_item'] = true;
            }
        }

        return collect($groups)
            ->filter(fn ($group) => count($group['items']) > 1 && $group['has_source_item'])
            ->map(function ($group) {
                unset($group['has_source_item']);
                $group['total'] = count($group['items']);

                return $group;
            })
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * ОТКЛЮЧЕНО: Тестовый метод для проверки обнуления остатков поставщика
     */
    public function testResetSupplierStocks(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Обнуление остатков отключено',
            'reason' => 'Функция обнуления остатков полностью отключена по требованию',
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
            'reason' => 'Функция обнуления остатков полностью отключена по требованию',
        ], 403);
    }

    /**
     * Обнуляет остатки товаров поставщика по имени (текстовое поле)
     */
    private function resetSupplierStockByName($supplierName)
    {
        try {
            // Обнуляем остатки на удаленном складе и остатки у/?? быстро у товаров с указанным поставщиком
            ShopGood::where('supplier', $supplierName)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null,
                ]);

            // КРИТИЧНО: Обнуляем остатки у вариаций, которые привязаны к поставщику по полю supplier вариации,
            // а не ??ерез ??вязь с товаром. Вариации могут быть привязаны к другому поставщику, ??ем главный товар.
            ShopGoodVariation::where('supplier', $supplierName)
                ->orWhere(function ($query) use ($supplierName) {
                    $query->where(function ($q) {
                        $q->whereNull('supplier')->orWhere('supplier', '');
                    })->whereHas('good', function ($q) use ($supplierName) {
                        $q->where('supplier', $supplierName);
                    });
                })
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null,
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
        $newAttrsSorted = collect($newAttributes)->sortBy(function ($attr) {
            return $attr['name'] ?? '';
        })->map(function ($attr) {
            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
        })->values()->toArray();

        $existingAttrsSorted = collect($existingAttributes)->sortBy(function ($attr) {
            return $attr['name'] ?? '';
        })->map(function ($attr) {
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

        return $variation->attributes->map(function ($attr) {
            return [
                'name' => $attr->attribute->name,
                'value' => $attr->value,
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
                'filename' => 'required|string',
            ]);

            $filename = $request->input('filename');

            // Валидация имени файла для безопасности
            if (!preg_match('/^temp_[a-f0-9\-]+\.(xml|yml|txt)$/i', $filename)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверное имя файла',
                ], 400);
            }

            $filePath = storage_path('app/temp/' . $filename);

            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл не найден',
                ], 404);
            }

            // Читаем содержимое файла
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось прочитать файл',
                ], 500);
            }

            // Парсим YML/XML
            $ymlData = $this->parseYMLContent($fileContent);

            return response()->json([
                'success' => true,
                'ymlData' => $ymlData,
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка парсинга загруженного YML файла', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Формируем более понятное сообщение об ошибке
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Opening and ending tag mismatch') !== false) {
                $errorMessage = 'Ошибка парсинга YML: Несоответствие открывающих и закрывающих тегов в XML файле. ' .
                    'Проверьте корректность структуры XML файла. ' .
                    'Возможно, некоторые теги не закрыты или закрыты неправильно.';
            } elseif (strpos($errorMessage, 'parser error') !== false) {
                $errorMessage = 'Ошибка парсинга YML: ' . $errorMessage;
            }

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'message' => $errorMessage,
            ], 500);
        }
    }

    /**
     * Парсит содержимое YML/XML строки
     */
    private function parseYMLContent($content)
    {
        try {
            $content = $this->normalizeXMLContentEncoding((string) $content);

            // Включаем обработку ошибок для получения детальной информации об ошибках парсинга
            libxml_use_internal_errors(true);
            libxml_clear_errors();

            // Загружаем XML
            $xml = simplexml_load_string($content);

            // Получаем ошибки парсинга, если они есть
            $xmlErrors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            if ($xml === false) {
                $errorMessage = 'Не удалось загрузить XML';
                if (!empty($xmlErrors)) {
                    $errorDetails = [];
                    foreach ($xmlErrors as $error) {
                        $errorDetails[] = sprintf(
                            'Строка %d: %s',
                            $error->line,
                            trim($error->message)
                        );
                    }
                    $errorMessage .= '. ' . implode('; ', $errorDetails);
                }
                throw new \Exception($errorMessage);
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
                            'id' => (string) $category['id'],
                            'parentId' => (string) ($category['parentId'] ?? null),
                            'name' => (string) $category,
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
                // ??льтернативная структура XML
                [$categories, $offers] = $this->parseAlternativeXMLStructure($xml, $alternativeStructure);
                // Для альтернативных структур shop может быть в другом месте или отсутствовать
                $shop = isset($xml->shop) ? $xml->shop : null;
            }

            return [
                'shop' => [
                    'name' => $shop ? (string) ($shop->name ?? 'Unknown Shop') : 'Unknown Shop',
                    'company' => $shop ? (string) ($shop->company ?? 'Unknown Company') : 'Unknown Company',
                    'url' => $shop ? (string) ($shop->url ?? null) : null,
                ],
                'categories' => $categories,
                'offers' => $offers,
            ];

        } catch (\Exception $e) {
            throw new \Exception('Ошибка парсинга YML: ' . $e->getMessage());
        }
    }

    /**
     * Нормализует XML/YML в UTF-8 перед SimpleXML и JSON-ответом.
     */
    private function normalizeXMLContentEncoding(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $encoding = null;
        $prefix = substr($content, 0, 1024);

        if (preg_match('/<\?xml[^>]*encoding=["\']([^"\']+)["\']/i', $prefix, $matches)) {
            $encoding = strtoupper(trim($matches[1]));
        } elseif (function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'CP1251', 'ISO-8859-1'], true);
            $encoding = $detected ? strtoupper($detected) : null;
        }

        $isUtf8 = function_exists('mb_check_encoding')
            ? mb_check_encoding($content, 'UTF-8')
            : (bool) preg_match('//u', $content);

        if ($encoding && ! in_array($encoding, ['UTF-8', 'UTF8'], true)) {
            $sourceEncoding = in_array($encoding, ['WINDOWS-1251', 'CP1251'], true) ? 'Windows-1251' : $encoding;

            if (function_exists('mb_convert_encoding')) {
                $content = mb_convert_encoding($content, 'UTF-8', $sourceEncoding);
            } elseif (function_exists('iconv')) {
                $converted = @iconv($sourceEncoding, 'UTF-8//IGNORE', $content);
                if ($converted !== false) {
                    $content = $converted;
                }
            }
        } elseif (! $isUtf8) {
            if (function_exists('mb_convert_encoding')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            } elseif (function_exists('iconv')) {
                $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $content);
                if ($converted !== false) {
                    $content = $converted;
                }
            }
        }

        return preg_replace(
            '/(<\?xml[^>]*encoding=)["\'][^"\']+["\']/i',
            '$1"UTF-8"',
            $content,
            1
        ) ?? $content;
    }

    /**
     * Парсит товар в стандартном YML формате
     */
    private function parseStandardYMLOffer($offer)
    {
        $rawXmlPrice = (string) ($offer->price ?? 0);
        $parsedPrice = (float) preg_replace('/\s+/', '', str_replace(["\xC2\xA0", 'руб.', 'руб', 'р.', 'р'], '', $rawXmlPrice));

        return [
            'id' => (string) $offer['id'],
            'name' => (string) $offer->name,
            'categoryId' => (string) ($offer->categoryId ?? null),
            'price' => $parsedPrice,
            'currencyId' => (string) ($offer->currencyId ?? 'RUB'),
            'available' => (string) ($offer['available'] ?? 'true') === 'true',
            'url' => (string) ($offer->url ?? null),
            'picture' => isset($offer->picture) ? (string) $offer->picture : null,
            'description' => isset($offer->description) ? (string) $offer->description : null,
            'vendor' => isset($offer->vendor) ? (string) $offer->vendor : null,
            'vendorCode' => isset($offer->vendorCode) ? (string) $offer->vendorCode : null,
            'params' => $this->parseYMLParams($offer),
        ];
    }

    /**
     * Парсит товар в кастомном формате (ВЕЛО_2025-12-22.xml)
     */
    private function parseCustomFormatItem($item, &$categories)
    {
        $rawPrice = (string) $item->price;

        // Используем расширенную очистку цены
        $cleanedPrice = str_replace("\xC2\xA0", '', $rawPrice); // Удаляем неразрывный пробел (UTF-8: C2 A0)
        $cleanedPrice = preg_replace('/\s+/', '', $cleanedPrice); // Удаляем остальные пробельные символы
        $cleanedPrice = str_replace(['руб.', 'руб', 'р.', 'р'], '', $cleanedPrice); // Удаляем обозначения рублей

        $parsedPrice = (float) $cleanedPrice;

        // Извлекаем данные товара
        $offerData = [
            'id' => (string) $item->id,
            'name' => (string) $item->name,
            'categoryId' => (string) ($item->level_id_3 ?? $item->level_id_2 ?? $item->level_id_1 ?? null),
            'price' => $parsedPrice,
            'currencyId' => 'RUB',
            'available' => (string) $item->available === 'true',
            'url' => null,
            'picture' => isset($item->picture) ? (string) $item->picture : null,
            'description' => isset($item->description) && !empty((string) $item->description) ? (string) $item->description : null,
            'vendor' => null,
            'vendorCode' => (string) ($item->code ?? null),
            'params' => [],
        ];

        // Парсим параметры
        if (isset($item->param)) {
            foreach ($item->param as $param) {
                $paramName = (string) $param['name'];
                $paramValue = (string) $param;

                $offerData['params'][] = [
                    'name' => $paramName,
                    'value' => $paramValue,
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
                    'name' => (string) $param['name'],
                    'value' => (string) $param,
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
                $categoryId = (string) $item->$idField;
                $categoryName = (string) $item->$nameField;

                // Проверяем, не добавлена ли уже ста категория
                $existingCategory = array_filter($categories, function ($cat) use ($categoryId) {
                    return $cat['id'] === $categoryId;
                });

                if (empty($existingCategory)) {
                    // Определяем parentId (предыдущий уровень)
                    $parentId = null;
                    if ($level > 0) {
                        $parentField = 'level_id_' . ($level - 1);
                        if (isset($item->$parentField)) {
                            $parentId = (string) $item->$parentField;
                        }
                    }

                    $categories[] = [
                        'id' => $categoryId,
                        'parentId' => $parentId,
                        'name' => $categoryName,
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
                'password' => 'nullable|string|max:255',
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
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
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
                    'http_code' => $httpCode,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Ошибка загрузки фида: ' . $errorMessage,
                ], 500);
            }

            if ($httpCode !== 200) {
                return response()->json([
                    'success' => false,
                    'error' => "Ошибка загрузки фида: HTTP {$httpCode}",
                ], $httpCode);
            }

            if (empty($xmlContent)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Фид пуст',
                ], 400);
            }

            // Парсим XML содержимое
            $ymlData = $this->parseYMLContent($xmlContent);

            return response()->json([
                'success' => true,
                'ymlData' => $ymlData,
            ]);

        } catch (\Exception $e) {
            \Log::error('Ошибка парсинга YML по URL', [
                'url' => $request->input('url'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Формируем более понятное сообщение об ошибке
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Opening and ending tag mismatch') !== false) {
                $errorMessage = 'Ошибка парсинга YML: Несоответствие открывающих и закрывающих тегов в XML файле. ' .
                    'Проверьте корректность структуры XML файла. ' .
                    'Возможно, некоторые теги не закрыты или закрыты неправильно.';
            } elseif (strpos($errorMessage, 'parser error') !== false) {
                $errorMessage = 'Ошибка парсинга YML: ' . $errorMessage;
            }

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'message' => $errorMessage,
            ], 500);
        }
    }
}
