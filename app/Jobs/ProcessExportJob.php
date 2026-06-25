<?php

namespace App\Jobs;

use App\Models\ExportFile;
use App\Models\ShopGood;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProcessExportJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 7200; // 2 часа таймаут

    public $tries = 3; // 3 попытки

    protected ExportFile $exportFile;

    /**
     * Create a new job instance.
     */
    public function __construct(ExportFile $exportFile)
    {
        $this->exportFile = $exportFile;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', '7200');

        // Специальная обработка для Авито
        if (isset($this->exportFile->export_config['is_avito']) && $this->exportFile->export_config['is_avito']) {
            $this->handleAvitoExport();
            return;
        }

        try {
            // Обновляем статус на "обрабатывается"
            $this->exportFile->update(['status' => 'processing']);

            // Получаем конфигурацию экспорта
            $config = $this->exportFile->export_config ?? [];

            $filePath = 'exports/' . $this->exportFile->filename;
            $fullPath = Storage::path($filePath);

            // Создаем директорию, если её нет
            $directory = dirname($fullPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Определяем, использовать ли потоковую выгрузку (OpenSpout)
            // Используем для всех форматов, если нет шаблона Excel
            // Для CSV и TXT всегда используем потоковую выгрузку, так как шаблоны для них не поддерживаются
            $useStreaming = empty($config['template_path']);

            $totalRows = 0;
            $fileSize = 0;

            if ($useStreaming) {
                $totalRows = $this->streamExport($config, $fullPath, $this->exportFile->format);

                if (file_exists($fullPath)) {
                    $fileSize = filesize($fullPath);
                }
                else {
                    throw new \Exception('Failed to create export file');
                }
            }
            else {
                // Стандартный метод (только для Excel с шаблоном)
                // Загружаем все данные в память (не рекомендуется для больших файлов)
                $query = $this->getExportQuery($config);
                $goods = $query->get();
                $exportData = $this->processChunk($goods, $config);

                if (empty($exportData)) {
                    throw new \Exception('Нет данных для экспорта');
                }

                // Генерируем файл с использованием шаблона
                $fileContent = $this->generateFileContent($exportData, $this->exportFile->format);
                Storage::put($filePath, $fileContent);
                $fileSize = Storage::size($filePath);
                $totalRows = count($exportData);
            }

            // Обновляем запись в БД
            $this->exportFile->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'total_rows' => $totalRows,
            ]);

        }
        catch (\Exception $e) {
            Log::error('Export job failed', [
                'export_file_id' => $this->exportFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->exportFile->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Потоковая выгрузка (Excel, CSV, TXT) с использованием OpenSpout
     */
    protected function streamExport(array $config, string $filePath, string $format): int
    {
        $writer = null;

        if ($format === 'csv' || $format === 'txt') {
            $options = new CsvOptions;
            if ($format === 'txt') {
                $options->setFieldDelimiter("\t");
            }
            $writer = new CsvWriter($options);
        }
        else {
            $options = new XlsxOptions;
            $writer = new XlsxWriter($options);
        }

        $writer->openToFile($filePath);

        $query = $this->getExportQuery($config);

        // Считаем общее количество строк для корректного отображения прогресса
        try {
            $totalCount = $query->count();
            $this->exportFile->update([
                'total_rows' => $totalCount,
            ]);
        }
        catch (\Throwable $e) {
        // Если не удалось посчитать (сложный запрос), оставляем 0
        }

        $processedRows = 0;
        $headersWritten = false;

        $query->chunk(500, function ($goods) use ($writer, $config, &$processedRows, &$headersWritten) {
            $data = $this->processChunk($goods, $config);

            if (empty($data)) {
                return;
            }

            // Записываем заголовки (из первого чанка)
            if (!$headersWritten) {
                $firstRow = $data[0];
                if (is_array($firstRow)) {
                    $headers = array_keys($firstRow);
                    $headerCells = array_map(function ($value) {
                                    $cleanValue = preg_replace('/\{\{\d+\}\}$/', '', (string)$value);

                                    return Cell::fromValue($cleanValue);
                                }
                                    , $headers);
                                $writer->addRow(new Row($headerCells));
                            }
                            $headersWritten = true;
                        }

                        // Записываем данные
                        foreach ($data as $dataRow) {
                            if (is_array($dataRow)) {
                                $cells = array_map(function ($value) {
                                    if (is_array($value) || is_object($value)) {
                                        $strVal = json_encode($value, JSON_UNESCAPED_UNICODE);
                                    }
                                    elseif ($value === null) {
                                        $strVal = '';
                                    }
                                    else {
                                        $strVal = (string)$value;
                                    }

                                    // Защита от инъекций формул (для всех форматов для безопасности)
                                    if (str_starts_with($strVal, '=')) {
                                        $strVal = ' ' . $strVal;
                                    }

                                    return Cell::fromValue($strVal);
                                }
                                    , array_values($dataRow));

                                $writer->addRow(new Row($cells));

                                $processedRows++;

                                // Обновляем прогресс (паттерн Modex: часто в начале, потом реже)
                                if ($processedRows <= 100 || $processedRows % 50 === 0) {
                                    try {
                                        $conf = $this->exportFile->export_config ?? [];
                                        $conf['progress_rows'] = $processedRows;
                                        $this->exportFile->update([
                                            'export_config' => $conf,
                                        ]);
                                    }
                                    catch (\Throwable $e) {
                                    }
                                }
                            }
                        }
                    });

        $writer->close();

        return $processedRows;
    }

    /**
     * Подготовка запроса для выборки данных
     */
    protected function getExportQuery(array $config)
    {
        // Фильтр по массиву ID (для экспорта выбранных товаров) - должен быть первым
        $selectedIds = null;
        if (isset($config['filters']['selected_ids']) && !empty($config['filters']['selected_ids'])) {
            $selectedIdsRaw = $config['filters']['selected_ids'];

            // Убеждаемся, что это массив
            if (!is_array($selectedIdsRaw)) {
                $selectedIdsRaw = is_string($selectedIdsRaw) && strpos($selectedIdsRaw, ',') !== false
                    ? explode(',', $selectedIdsRaw)
                    : [$selectedIdsRaw];
            }

            $selectedIds = array_map('intval', array_filter($selectedIdsRaw, function ($id) {
                return is_numeric($id) && $id > 0;
            }));

            if (empty($selectedIds)) {
                $selectedIds = null;
            }
        }

        $filters = $this->normalizeExportFilterAliases($config['filters'] ?? []);
        $variationRelation = function ($variationQuery) use ($filters) {
            $variationQuery->select([
                'id', 'good_id', 'name', 'sku', 'price', 'sale_price', 'demping_price', 'show_demping',
                'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'weight', 'length', 'width', 'height', 'is_active', 'supplier',
            ]);

            if ($this->hasExportVariationRowFilters($filters)) {
                $this->applyExportVariationRowFilters($variationQuery, $filters);
            }
        };

        $query = ShopGood::select([
            'id', 'name', 'sku', 'description', 'short_description',
            'price', 'sale_price', 'demping_price', 'show_demping',
            'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity',
            'width', 'height', 'depth', 'weight',
            'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'is_show',
            'created_at',
        ])->with([
            'categories:id,name',
            'brands:id,name',
            'tags:id,name,color',
            'label:id,name,color',
            'properties:id,name,slug',
            'images:id,good_id,file_path,alt_text,is_main,sort_order',
            'variations' => $variationRelation,
            'variations.images:id,variation_id,file_path,alt_text,is_main,sort_order',
        ]);

        // Применяем фильтр по выбранным товарам (если указан)
        if ($selectedIds !== null) {
            $query->whereIn('id', $selectedIds);
        // Когда есть selected_ids, пропускаем другие фильтры (как в ShopGoodsController)
        }
        else {
            // Применяем фильтры из конфигурации только если нет selected_ids
            if (isset($config['filters'])) {
                $this->applyFilters($query, $filters);
            }
        }

        // Загружаем характеристики товаров
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        if ($hasValueCol) {
            $query->with(['properties' => function ($query) {
                $query->withPivot('shop_property_value_id', 'value');
            }]);
        }
        else {
            $query->with(['properties' => function ($query) {
                $query->withPivot('shop_property_value_id');
            }]);
        }

        return $query;
    }

    /**
     * Обработка чанка данных (загрузка связей и форматирование)
     */
    protected function processChunk($goods, array $config): array
    {
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');

        // Обрабатываем характеристики товаров
        foreach ($goods as $good) {
            foreach ($good->properties as $property) {
                if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                    $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                    $property->property_value = $propertyValue;
                }
                elseif ($hasValueCol && isset($property->pivot) && $property->pivot->value) {
                    $property->property_value = (object)[
                        'id' => null,
                        'value' => $property->pivot->value,
                        'property_id' => $property->id,
                    ];
                }
            }
        }

        // Загружаем характеристики и атрибуты вариаций
        if (isset($config['with_variation_attributes']) && $config['with_variation_attributes']) {
            $this->loadVariationAttributes($goods);
        }

        if (isset($config['with_characteristics']) && $config['with_characteristics']) {
            $this->loadVariationCharacteristics($goods);
        }

        return $this->formatExportData($goods, $config);
    }

    /**
     * Получить данные для экспорта (Legacy wrapper)
     */
    protected function getExportData(array $config): array
    {
        $query = $this->getExportQuery($config);
        $goods = $query->get();

        return $this->processChunk($goods, $config);
    }

    /**
     * Применить фильтры к запросу
     */
    protected function applyFilters($query, array $filters): void
    {
        $filters = $this->normalizeExportFilterAliases($filters);

        // Реализуем фильтры аналогично ShopGoodsController

        // Фильтр по наличию (in_stock) - специальная обработка
        if (isset($filters['in_stock']) && $filters['in_stock'] !== '') {
            $inStock = $filters['in_stock'];
            if ($inStock === 'true') {
                $query->where('stock_quantity', '>', 0);
            }
            elseif ($inStock === 'false') {
                $query->where('stock_quantity', '=', 0);
            }
            elseif ($inStock === 'low') {
                $query->where('stock_quantity', '>', 0)
                    ->where('stock_quantity', '<', 3);
            }
            elseif (strpos($inStock, 'exact:') === 0) {
                // Точное значение остатка в формате exact:value
                $exactValue = (int)substr($inStock, 6);
                $query->where('stock_quantity', '=', $exactValue);
            }
        }

        // Базовые фильтры (одиночные значения) - boolean поля
        $booleanFilters = [
            'is_active', 'is_new', 'is_featured', 'is_sale', 'is_show', 'is_preorder',
        ];

        foreach ($booleanFilters as $filterName) {
            if (isset($filters[$filterName]) && $filters[$filterName] !== '') {
                // Преобразуем строковые "true"/"false" в boolean значения
                $booleanValue = filter_var($filters[$filterName], FILTER_VALIDATE_BOOLEAN);
                $query->where($filterName, $booleanValue);
            }
        }

        // Фильтры наличия связанных записей
        $relationFilters = [
            'has_variations' => 'variations',
            'has_categories' => 'categories',
            'has_brands' => 'brands',
            'has_tags' => 'tags',
            'has_label' => 'label',
        ];

        foreach ($relationFilters as $filterName => $relation) {
            if (isset($filters[$filterName]) && $filters[$filterName] !== '') {
                $value = $filters[$filterName];
                if ($value === 'true') {
                    $query->whereHas($relation);
                }
                elseif ($value === 'false') {
                    $query->whereDoesntHave($relation);
                }
            }
        }

        // Фильтр по габаритам и весу
        if (isset($filters['dimensions_weight_filter']) && $filters['dimensions_weight_filter'] !== '') {
            $type = $filters['dimensions_weight_filter'];
            if ($type === 'empty_dimensions') {
                $query->where(function ($q) {
                    $q->whereNull('width')->orWhere('width', 0)
                        ->orWhereNull('height')->orWhere('height', 0)
                        ->orWhereNull('depth')->orWhere('depth', 0);
                });
            } elseif ($type === 'empty_weight') {
                $query->where(function ($q) {
                    $q->whereNull('weight')->orWhere('weight', 0);
                });
            } elseif ($type === 'empty_both') {
                $query->where(function ($q) {
                    $q->where(function ($sq) {
                        $sq->whereNull('width')->orWhere('width', 0)
                            ->orWhereNull('height')->orWhere('height', 0)
                            ->orWhereNull('depth')->orWhere('depth', 0);
                    })->where(function ($sq) {
                        $sq->whereNull('weight')->orWhere('weight', 0);
                    });
                });
            } elseif ($type === 'filled_dimensions') {
                $query->where('width', '>', 0)
                    ->where('height', '>', 0)
                    ->where('depth', '>', 0);
            } elseif ($type === 'filled_weight') {
                $query->where('weight', '>', 0);
            } elseif ($type === 'filled_both') {
                $query->where('width', '>', 0)
                    ->where('height', '>', 0)
                    ->where('depth', '>', 0)
                    ->where('weight', '>', 0);
            } elseif ($type === 'exact_dimensions') {
                $exactFields = [
                    'exact_width' => 'width',
                    'exact_height' => 'height',
                    'exact_depth' => 'depth',
                    'exact_weight' => 'weight',
                ];

                foreach ($exactFields as $filterKey => $column) {
                    if (! isset($filters[$filterKey]) || $filters[$filterKey] === '') {
                        continue;
                    }

                    $value = str_replace(',', '.', (string) $filters[$filterKey]);
                    if (is_numeric($value)) {
                        $query->where($column, (float) $value);
                    }
                }
            }
        }

        // Фильтр по категории (одиночный)
        if (isset($filters['category_id']) && !empty($filters['category_id'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('shop_categories.id', $filters['category_id']);
            });
        }

        // Фильтр по бренду (одиночный)
        if (isset($filters['brand_id']) && !empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        // Множественные фильтры (массивы)
        // Категории - отношение
        if (isset($filters['categories']) && is_array($filters['categories']) && !empty($filters['categories'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->whereIn('shop_categories.id', $filters['categories']);
            });
        }

        // Бренды - отношение
        if (isset($filters['brands']) && is_array($filters['brands']) && !empty($filters['brands'])) {
            $query->whereHas('brands', function ($q) use ($filters) {
                $q->whereIn('shop_brands.id', $filters['brands']);
            });
        }

        // Теги - отношение
        if (isset($filters['tags']) && is_array($filters['tags']) && !empty($filters['tags'])) {
            $query->whereHas('tags', function ($q) use ($filters) {
                $q->whereIn('shop_tags.id', $filters['tags']);
            });
        }

        // Лейблы - поле label_id
        if (isset($filters['labels']) && is_array($filters['labels']) && !empty($filters['labels'])) {
            $query->whereIn('label_id', $filters['labels']);
        }

        if (isset($filters['exclude_categories']) && is_array($filters['exclude_categories']) && !empty($filters['exclude_categories'])) {
            $query->whereDoesntHave('categories', function ($q) use ($filters) {
                $q->whereIn('shop_categories.id', $filters['exclude_categories']);
            });
        }

        if (isset($filters['exclude_brands']) && is_array($filters['exclude_brands']) && !empty($filters['exclude_brands'])) {
            $query->whereDoesntHave('brands', function ($q) use ($filters) {
                $q->whereIn('shop_brands.id', $filters['exclude_brands']);
            });
        }

        if (isset($filters['exclude_tags']) && is_array($filters['exclude_tags']) && !empty($filters['exclude_tags'])) {
            $query->whereDoesntHave('tags', function ($q) use ($filters) {
                $q->whereIn('shop_tags.id', $filters['exclude_tags']);
            });
        }

        if (isset($filters['exclude_labels']) && is_array($filters['exclude_labels']) && !empty($filters['exclude_labels'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereNull('label_id')
                    ->orWhereNotIn('label_id', $filters['exclude_labels']);
            });
        }

        // Фильтр по поставщику (текстовое поле)
        if (isset($filters['supplier']) && !empty($filters['supplier'])) {
            $query->where('supplier', $filters['supplier']);
        }

        // Поставщики - поле supplier (множественный выбор)
        if (isset($filters['suppliers']) && is_array($filters['suppliers']) && !empty($filters['suppliers'])) {
            $supplierIds = array_filter($filters['suppliers'], function ($supplier) {
                return !empty($supplier);
            });
            $includeVariations = filter_var($filters['suppliers_include_variations'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (!empty($supplierIds)) {
                if ($includeVariations) {
                    // Включаем товары с поставщиками И товары с вариациями с поставщиками
                    $query->where(function ($q) use ($supplierIds) {
                        // Товары с выбранными поставщиками
                        $q->whereIn('supplier', $supplierIds)
                          // Или товары, у которых есть вариации с выбранными поставщиками
                            ->orWhereHas('variations', function ($varQ) use ($supplierIds) {
                                $varQ->whereIn('supplier', $supplierIds);
                            });
                    });
                } else {
                    // Только товары с поставщиками (без учета вариаций)
                    $query->whereIn('supplier', $supplierIds);
                }
            }
        }

        // Фильтр "Без поставщиков"
        if (isset($filters['supplier_empty']) && filter_var($filters['supplier_empty'], FILTER_VALIDATE_BOOLEAN)) {
            $includeVariations = filter_var($filters['supplier_empty_include_variations'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($includeVariations) {
                $query->where(function ($q) {
                    $q->where(function ($sq) {
                        $sq->whereNull('supplier')->orWhere('supplier', '');
                    })->whereDoesntHave('variations', function ($vq) {
                        $vq->whereNotNull('supplier')->where('supplier', '!=', '');
                    });
                });
            } else {
                $query->where(function ($q) {
                    $q->whereNull('supplier')->orWhere('supplier', '');
                });
            }
        }

        // Поиск
        if (isset($filters['search']) && !empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($searchTerm) . '%'])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ['%' . mb_strtolower($searchTerm) . '%']);
            });
        }

        // Фильтр по цене (min_price, max_price)
        $this->applyPriceFilter($query, $filters, 'price', 'min_price', 'max_price');

        // Фильтр по акционной цене (min_sale_price, max_sale_price)
        $this->applyPriceFilter($query, $filters, 'sale_price', 'min_sale_price', 'max_sale_price');

        // Фильтр по демпинг цене (min_demping_price, max_demping_price)
        $this->applyPriceFilter($query, $filters, 'demping_price', 'min_demping_price', 'max_demping_price');

        // Фильтр по артикулу (sku_filter_type)
        if (isset($filters['sku_filter_type']) && !empty($filters['sku_filter_type'])) {
            $skuFilterType = $filters['sku_filter_type'];
            if ($skuFilterType === 'empty') {
                $query->where(function ($q) {
                    $q->whereNull('sku')
                        ->orWhere('sku', '=', '');
                });
            }
            elseif ($skuFilterType === 'not_empty') {
                $query->whereNotNull('sku')
                    ->where('sku', '!=', '');
            }
        }

        // Фильтр по демпингу (show_demping)
        if (isset($filters['has_demping'])) {
            $value = $filters['has_demping'];
            if ($value === 'true') {
                $query->where('show_demping', true);
            }
            elseif ($value === 'false') {
                $query->where(function ($q) {
                    $q->where('show_demping', false)
                        ->orWhereNull('show_demping');
                });
            }
        }

        // Фильтр по наличию демпинг цены
        if (isset($filters['has_demping_price'])) {
            $value = $filters['has_demping_price'];
            if ($value === 'true') {
                $query->whereNotNull('demping_price')
                    ->where('demping_price', '>', 0);
            }
            elseif ($value === 'false') {
                $query->where(function ($q) {
                    $q->whereNull('demping_price')
                        ->orWhere('demping_price', '=', 0);
                });
            }
            elseif ($value === 'greater_than_base') {
                $query->where(function ($q) {
                    $q->where(function ($mainQ) {
                        $mainQ->whereNotNull('price')
                            ->whereNotNull('demping_price')
                            ->where('demping_price', '>', 0)
                            ->whereColumn('demping_price', '>', 'price');
                    })->orWhereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('price')
                            ->whereNotNull('demping_price')
                            ->where('demping_price', '>', 0)
                            ->whereColumn('shop_good_variations.demping_price', '>', 'shop_good_variations.price');
                    });
                });
            }
        }

        // Фильтр по наличию акционной цены
        if (isset($filters['has_sale_price'])) {
            $value = $filters['has_sale_price'];
            if ($value === 'true') {
                $query->where(function ($q) {
                    // Проверяем акционную цену основного товара
                    $q->whereNotNull('sale_price')
                        ->where('sale_price', '>', 0)
                        // Или акционную цену в вариациях
                        ->orWhereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('sale_price')
                            ->where('sale_price', '>', 0);
                    }
                    );
                });
            }
            elseif ($value === 'false') {
                $query->where(function ($q) {
                    // Основной товар без акционной цены И все вариации без акционной цены
                    $q->where(function ($mainQ) {
                            $mainQ->whereNull('sale_price')
                                ->orWhere('sale_price', '=', 0);
                        }
                        )->whereDoesntHave('variations', function ($varQ) {
                            $varQ->whereNotNull('sale_price')
                                ->where('sale_price', '>', 0);
                        }
                        );
                    });
            }
            elseif ($value === 'greater_than_base') {
                $query->where(function ($q) {
                    $q->where(function ($mainQ) {
                        $mainQ->whereNotNull('price')
                            ->whereNotNull('sale_price')
                            ->where('sale_price', '>', 0)
                            ->whereColumn('sale_price', '>', 'price');
                    })->orWhereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('price')
                            ->whereNotNull('sale_price')
                            ->where('sale_price', '>', 0)
                            ->whereColumn('shop_good_variations.sale_price', '>', 'shop_good_variations.price');
                    });
                });
            }
        }

        // Фильтр по общему остатку (total_stock_not_empty, total_stock_both_empty)
        if (isset($filters['total_stock_not_empty']) && $filters['total_stock_not_empty'] == '1') {
            // В наличии: (нет вариаций И остаток основного товара > 0 или у/с не пустой или у/с быстрый не пустой) ИЛИ (есть вариации И есть вариации с остатком)
            $query->where(function ($mainQuery) {
                // Вариант 1: Нет вариаций И остаток основного товара > 0 или у/с не пустой или у/с быстрый не пустой
                $mainQuery->where(function ($noVariationsQuery) {
                        $noVariationsQuery->whereDoesntHave('variations')
                            ->where(function ($stockQuery) {
                        $stockQuery->where('stock_quantity', '>', 0)
                            ->orWhere(function ($remoteCondition) {
                            $remoteCondition->whereNotNull('remote_stock_quantity')
                                ->where('remote_stock_quantity', '!=', '0')
                                ->where('remote_stock_quantity', '!=', '')
                                ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                        }
                        )
                            ->orWhere(function ($fastRemoteCondition) {
                            $fastRemoteCondition->whereNotNull('fast_remote_stock_quantity')
                                ->where('fast_remote_stock_quantity', '!=', '0')
                                ->where('fast_remote_stock_quantity', '!=', '')
                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                        }
                        );
                    }
                    );
                }
                );

                // Вариант 2: Есть вариации И есть вариации с остатком
                $mainQuery->orWhere(function ($hasVariationsQuery) {
                        $hasVariationsQuery->whereHas('variations')
                            ->whereHas('variations', function ($varQ) {
                        $varQ->where(function ($subVarQ) {
                                        $subVarQ->where('stock_quantity', '>', 0)
                                            ->orWhere(function ($remoteVarQ) {
                                $remoteVarQ->whereNotNull('remote_stock_quantity')
                                    ->where('remote_stock_quantity', '!=', '0')
                                    ->where('remote_stock_quantity', '!=', '')
                                    ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                            }
                            )
                                ->orWhere(function ($fastRemoteVarQ) {
                                $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                    ->where('fast_remote_stock_quantity', '!=', '0')
                                    ->where('fast_remote_stock_quantity', '!=', '')
                                    ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                            }
                            );
                        }
                        );
                    }
                    );
                }
                );
            });
        }
        elseif (isset($filters['total_stock_both_empty']) && $filters['total_stock_both_empty'] == '1') {
            // Нет в наличии: (нет вариаций И остаток основного товара = 0 и у/с пустой и у/с быстрый пустой) ИЛИ (есть вариации И нет вариаций с остатком)
            $query->where(function ($mainQuery) {
                // Вариант 1: Нет вариаций И остаток основного товара = 0 и у/с пустой и у/с быстрый пустой
                $mainQuery->where(function ($noVariationsQuery) {
                        $noVariationsQuery->whereDoesntHave('variations')
                            ->where('stock_quantity', '=', 0)
                            ->where(function ($remoteCondition) {
                        $remoteCondition->where(function ($remoteNull) {
                                        $remoteNull->whereNull('remote_stock_quantity')
                                            ->orWhere('remote_stock_quantity', '=', '0')
                                            ->orWhere('remote_stock_quantity', '=', '')
                                            ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                                    }
                                    );
                                }
                                )
                                    ->where(function ($fastRemoteCondition) {
                        $fastRemoteCondition->where(function ($fastRemoteNull) {
                                        $fastRemoteNull->whereNull('fast_remote_stock_quantity')
                                            ->orWhere('fast_remote_stock_quantity', '=', '0')
                                            ->orWhere('fast_remote_stock_quantity', '=', '')
                                            ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                                    }
                                    );
                                }
                                );
                            }
                            );

                            // Вариант 2: Есть вариации И нет вариаций с остатком
                            $mainQuery->orWhere(function ($hasVariationsQuery) {
                        $hasVariationsQuery->whereHas('variations')
                            ->whereDoesntHave('variations', function ($varQ) {
                        $varQ->where(function ($subVarQ) {
                                        $subVarQ->where('stock_quantity', '>', 0)
                                            ->orWhere(function ($remoteVarQ) {
                                $remoteVarQ->whereNotNull('remote_stock_quantity')
                                    ->where('remote_stock_quantity', '!=', '0')
                                    ->where('remote_stock_quantity', '!=', '')
                                    ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                            }
                            )
                                ->orWhere(function ($fastRemoteVarQ) {
                                $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                    ->where('fast_remote_stock_quantity', '!=', '0')
                                    ->where('fast_remote_stock_quantity', '!=', '')
                                    ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                            }
                            );
                        }
                        );
                    }
                    );
                }
                );
            });
        }

        // Фильтр по общему остатку (stock_quantity_min, stock_quantity_max) - для обратной совместимости
        elseif (isset($filters['stock_quantity_min']) || isset($filters['stock_quantity_max'])) {
            if (isset($filters['stock_quantity_min']) && isset($filters['stock_quantity_max'])) {
                $min = (int)$filters['stock_quantity_min'];
                $max = (int)$filters['stock_quantity_max'];
                if ($min === $max) {
                    $query->where('stock_quantity', '=', $min);
                }
                else {
                    $query->whereBetween('stock_quantity', [$min, $max]);
                }
            }
            elseif (isset($filters['stock_quantity_min'])) {
                $query->where('stock_quantity', '>=', (int)$filters['stock_quantity_min']);
            }
            elseif (isset($filters['stock_quantity_max'])) {
                $query->where('stock_quantity', '<=', (int)$filters['stock_quantity_max']);
            }
        }

        // Фильтр по остатку у/с (remote_stock_quantity)
        // Проверяем как остатки основного товара, так и остатки вариаций
        if (isset($filters['remote_stock_quantity_not_empty']) && $filters['remote_stock_quantity_not_empty']) {
            $query->where(function ($mainQuery) {
                // Вариант 1: Остаток у/с не пустой в основном товаре
                $mainQuery->where(function ($mainStockQuery) {
                        $mainStockQuery->whereNotNull('remote_stock_quantity')
                            ->where('remote_stock_quantity', '!=', '')
                            ->where('remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                    }
                    );
                    // Вариант 2: Есть вариации с остатком у/с не пустым
                    $mainQuery->orWhere(function ($variationsQuery) {
                        $variationsQuery->whereHas('variations', function ($varQ) {
                                $varQ->whereNotNull('remote_stock_quantity')
                                    ->where('remote_stock_quantity', '!=', '')
                                    ->where('remote_stock_quantity', '!=', '0')
                                    ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                            }
                            );
                        }
                        );
                    });
        }
        elseif (isset($filters['remote_stock_quantity_empty']) && $filters['remote_stock_quantity_empty']) {
            $query->where(function ($mainQuery) {
                // Вариант 1: Остаток у/с пустой в основном товаре И нет вариаций с остатком у/с
                $mainQuery->where(function ($mainStockQuery) {
                        $mainStockQuery->where(function ($remoteCondition) {
                                $remoteCondition->whereNull('remote_stock_quantity')
                                    ->orWhere('remote_stock_quantity', '=', '0')
                                    ->orWhere('remote_stock_quantity', '=', '')
                                    ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                            }
                            );
                        }
                        )
                            ->whereDoesntHave('variations', function ($varQ) {
                    $varQ->whereNotNull('remote_stock_quantity')
                        ->where('remote_stock_quantity', '!=', '')
                        ->where('remote_stock_quantity', '!=', '0')
                        ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                }
                );
            });
        }
        elseif (isset($filters['remote_stock_quantity']) && $filters['remote_stock_quantity'] !== '') {
            $query->where(function ($mainQuery) {
                // Вариант 1: Точное значение в основном товаре
                $mainQuery->where('remote_stock_quantity', '=', $filters['remote_stock_quantity']);
                // Вариант 2: Точное значение в вариациях
                $mainQuery->orWhereHas('variations', function ($varQ) {
                        $varQ->where('remote_stock_quantity', '=', $filters['remote_stock_quantity']);
                    }
                    );
                });
        }

        // Фильтр по быстрому остатку у/с (fast_remote_stock_quantity)
        // Проверяем как остатки основного товара, так и остатки вариаций
        if (isset($filters['fast_remote_stock_quantity_not_empty']) && $filters['fast_remote_stock_quantity_not_empty'] == '1') {
            $query->where(function ($mainQuery) {
                // Вариант 1: Остаток у/с быстро не пустой в основном товаре
                $mainQuery->where(function ($mainStockQuery) {
                        $mainStockQuery->whereNotNull('fast_remote_stock_quantity')
                            ->where('fast_remote_stock_quantity', '!=', '')
                            ->where('fast_remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                    }
                    );
                    // Вариант 2: Есть вариации с остатком у/с быстро не пустым
                    $mainQuery->orWhere(function ($variationsQuery) {
                        $variationsQuery->whereHas('variations', function ($varQ) {
                                $varQ->whereNotNull('fast_remote_stock_quantity')
                                    ->where('fast_remote_stock_quantity', '!=', '')
                                    ->where('fast_remote_stock_quantity', '!=', '0')
                                    ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                            }
                            );
                        }
                        );
                    });
        }
        elseif (isset($filters['fast_remote_stock_quantity_empty']) && $filters['fast_remote_stock_quantity_empty'] == '1') {
            $query->where(function ($mainQuery) {
                // Вариант 1: Остаток у/с быстро пустой в основном товаре И нет вариаций с остатком у/с быстро
                $mainQuery->where(function ($mainStockQuery) {
                        $mainStockQuery->where(function ($fastRemoteCondition) {
                                $fastRemoteCondition->whereNull('fast_remote_stock_quantity')
                                    ->orWhere('fast_remote_stock_quantity', '=', '0')
                                    ->orWhere('fast_remote_stock_quantity', '=', '')
                                    ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                            }
                            );
                        }
                        )
                            ->whereDoesntHave('variations', function ($varQ) {
                    $varQ->whereNotNull('fast_remote_stock_quantity')
                        ->where('fast_remote_stock_quantity', '!=', '')
                        ->where('fast_remote_stock_quantity', '!=', '0')
                        ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                }
                );
            });
        }

        // Фильтр по именам атрибутов вариаций
        if (isset($filters['variation_attribute_names']) && is_array($filters['variation_attribute_names']) && !empty($filters['variation_attribute_names'])) {
            $query->whereExists(function ($subQuery) use ($filters) {
                $subQuery->selectRaw('1')
                    ->from('shop_good_variations as v')
                    ->join('shop_variation_attributes_values as vav', 'v.id', '=', 'vav.variation_id')
                    ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                    ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                    ->whereRaw('v.good_id = shop_goods.id')
                    ->whereIn('a.name', $filters['variation_attribute_names'])
                    ->limit(1);
            });
        }

        // Фильтр по характеристикам (properties[property_id][])
        if (isset($filters['properties']) && is_array($filters['properties']) && !empty($filters['properties'])) {
            $query->where(function ($q) use ($filters) {
                foreach ($filters['properties'] as $propertyId => $valueIds) {
                    if (is_array($valueIds) && !empty($valueIds)) {
                        $q->orWhereHas('properties', function ($propQuery) use ($propertyId, $valueIds) {
                                        $propQuery->where('shop_properties.id', $propertyId)
                                            ->whereIn('shop_good_properties.shop_property_value_id', $valueIds);
                                    }
                                    );
                                }
                            }
                        });
        }

        // Фильтр по количеству характеристик (properties_count_type)
        if (isset($filters['properties_count_type'])) {
            $countType = $filters['properties_count_type'];
            if ($countType === 'none') {
                $query->whereDoesntHave('properties');
            }
            elseif ($countType === 'with') {
                $query->whereHas('properties');
            }
            elseif ($countType === 'exact' && isset($filters['properties_count'])) {
                $exactCount = (int)$filters['properties_count'];
                $query->has('properties', '=', $exactCount);
            }
        }

    }

    private function normalizeExportFilterAliases(array $filters): array
    {
        foreach ([
            'categories',
            'brands',
            'tags',
            'labels',
            'exclude_categories',
            'exclude_brands',
            'exclude_tags',
            'exclude_labels',
            'suppliers',
            'unlinked_suppliers',
            'variation_attribute_names',
            'exclude_variation_attribute_names',
        ] as $arrayFilterKey) {
            if (isset($filters[$arrayFilterKey]) && ! is_array($filters[$arrayFilterKey])) {
                $filters[$arrayFilterKey] = [$filters[$arrayFilterKey]];
            }
        }

        if (!empty($filters['remote_stock_variations_not_empty']) || !empty($filters['remote_stock_goods_not_empty'])) {
            $filters['remote_stock_quantity_not_empty'] = '1';
        } elseif (!empty($filters['remote_stock_variations_empty']) || !empty($filters['remote_stock_goods_empty'])) {
            $filters['remote_stock_quantity_empty'] = '1';
        } elseif (isset($filters['remote_stock_variations_exact']) || isset($filters['remote_stock_goods_exact'])) {
            $filters['remote_stock_quantity'] = $filters['remote_stock_variations_exact'] ?? $filters['remote_stock_goods_exact'];
        }

        if (!empty($filters['fast_remote_stock_variations_not_empty']) || !empty($filters['fast_remote_stock_goods_not_empty'])) {
            $filters['fast_remote_stock_quantity_not_empty'] = '1';
        } elseif (!empty($filters['fast_remote_stock_variations_empty']) || !empty($filters['fast_remote_stock_goods_empty'])) {
            $filters['fast_remote_stock_quantity_empty'] = '1';
        } elseif (isset($filters['fast_remote_stock_variations_exact']) || isset($filters['fast_remote_stock_goods_exact'])) {
            $filters['fast_remote_stock_quantity'] = $filters['fast_remote_stock_variations_exact'] ?? $filters['fast_remote_stock_goods_exact'];
        }

        if (!empty($filters['total_stock_variations_not_empty']) || !empty($filters['total_stock_goods_not_empty'])) {
            $filters['total_stock_not_empty'] = '1';
        } elseif (!empty($filters['total_stock_variations_both_empty']) || !empty($filters['total_stock_goods_both_empty'])) {
            $filters['total_stock_both_empty'] = '1';
        }

        return $filters;
    }

    private function hasExportVariationRowFilters(array $filters): bool
    {
        $keys = [
            'min_price',
            'max_price',
            'stock_variations_not_empty',
            'stock_variations_empty',
            'stock_variations_exact',
            'stock_variations_low',
            'remote_stock_variations_not_empty',
            'remote_stock_variations_empty',
            'remote_stock_variations_exact',
            'fast_remote_stock_variations_not_empty',
            'fast_remote_stock_variations_empty',
            'fast_remote_stock_variations_exact',
            'total_stock_variations_not_empty',
            'total_stock_variations_both_empty',
            'variation_attribute_names',
            'exclude_variation_attribute_names',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== '' && $filters[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    private function applyExportVariationRowFilters($query, array $filters): void
    {
        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $query->where('price', '>=', (float) $filters['min_price']);
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $query->where('price', '<=', (float) $filters['max_price']);
        }

        if (($filters['stock_variations_not_empty'] ?? null) === '1') {
            $query->where('stock_quantity', '>', 0);
        } elseif (($filters['stock_variations_empty'] ?? null) === '1') {
            $query->where(function ($stockQuery) {
                $stockQuery->whereNull('stock_quantity')->orWhere('stock_quantity', '<=', 0);
            });
        } elseif (isset($filters['stock_variations_exact']) && $filters['stock_variations_exact'] !== '') {
            $query->where('stock_quantity', '=', (int) $filters['stock_variations_exact']);
        } elseif (($filters['stock_variations_low'] ?? null) === '1') {
            $query->where('stock_quantity', '>', 0)->where('stock_quantity', '<', 3);
        }

        if (($filters['remote_stock_variations_not_empty'] ?? null) === '1') {
            $query->whereNotNull('remote_stock_quantity')
                ->where('remote_stock_quantity', '!=', '')
                ->where('remote_stock_quantity', '!=', '0')
                ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
        } elseif (($filters['remote_stock_variations_empty'] ?? null) === '1') {
            $query->where(function ($remoteQuery) {
                $remoteQuery->whereNull('remote_stock_quantity')
                    ->orWhere('remote_stock_quantity', '=', '')
                    ->orWhere('remote_stock_quantity', '=', '0')
                    ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
            });
        } elseif (isset($filters['remote_stock_variations_exact']) && $filters['remote_stock_variations_exact'] !== '') {
            $query->where('remote_stock_quantity', '=', $filters['remote_stock_variations_exact']);
        }

        if (($filters['fast_remote_stock_variations_not_empty'] ?? null) === '1') {
            $query->whereNotNull('fast_remote_stock_quantity')
                ->where('fast_remote_stock_quantity', '!=', '')
                ->where('fast_remote_stock_quantity', '!=', '0')
                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
        } elseif (($filters['fast_remote_stock_variations_empty'] ?? null) === '1') {
            $query->where(function ($fastRemoteQuery) {
                $fastRemoteQuery->whereNull('fast_remote_stock_quantity')
                    ->orWhere('fast_remote_stock_quantity', '=', '')
                    ->orWhere('fast_remote_stock_quantity', '=', '0')
                    ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
            });
        } elseif (isset($filters['fast_remote_stock_variations_exact']) && $filters['fast_remote_stock_variations_exact'] !== '') {
            $query->where('fast_remote_stock_quantity', '=', $filters['fast_remote_stock_variations_exact']);
        }

        if (($filters['total_stock_variations_not_empty'] ?? null) === '1') {
            $query->where(function ($totalQuery) {
                $totalQuery->where('stock_quantity', '>', 0)
                    ->orWhere(function ($remote) {
                        $remote->whereNotNull('remote_stock_quantity')
                            ->where('remote_stock_quantity', '!=', '')
                            ->where('remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                    })
                    ->orWhere(function ($fastRemote) {
                        $fastRemote->whereNotNull('fast_remote_stock_quantity')
                            ->where('fast_remote_stock_quantity', '!=', '')
                            ->where('fast_remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                    });
            });
        } elseif (($filters['total_stock_variations_both_empty'] ?? null) === '1') {
            $query->where(function ($totalQuery) {
                $totalQuery->where(function ($stock) {
                    $stock->whereNull('stock_quantity')->orWhere('stock_quantity', '<=', 0);
                })
                    ->where(function ($remote) {
                        $remote->whereNull('remote_stock_quantity')
                            ->orWhere('remote_stock_quantity', '=', '')
                            ->orWhere('remote_stock_quantity', '=', '0')
                            ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                    })
                    ->where(function ($fastRemote) {
                        $fastRemote->whereNull('fast_remote_stock_quantity')
                            ->orWhere('fast_remote_stock_quantity', '=', '')
                            ->orWhere('fast_remote_stock_quantity', '=', '0')
                            ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                    });
            });
        }
    }

    /**
     * Применить фильтр цены
     */
    private function applyPriceFilter($query, array $filters, string $field, string $minKey, string $maxKey): void
    {
        $minValue = $filters[$minKey] ?? null;
        $maxValue = $filters[$maxKey] ?? null;

        if ($minValue !== null || $maxValue !== null) {
            if ($minValue !== null && $maxValue !== null && $minValue == $maxValue) {
                // Точное значение
                $query->where($field, '=', $minValue);
            }
            else {
                // Диапазон
                if ($minValue !== null) {
                    $query->where($field, '>=', $minValue);
                }
                if ($maxValue !== null) {
                    $query->where($field, '<=', $maxValue);
                }
            }
        }
    }

    /**
     * Загрузить атрибуты вариаций
     */
    protected function loadVariationAttributes($goods): void
    {
        $allVariationIds = [];

        foreach ($goods as $good) {
            if ($good->variations) {
                foreach ($good->variations as $variation) {
                    if ($variation->id) {
                        $allVariationIds[] = $variation->id;
                    }
                }
            }
        }

        if (empty($allVariationIds)) {
            return;
        }

        // Загружаем атрибуты батчами
        $variationAttributesMap = [];
        $batchSize = 5000;

        for ($i = 0; $i < count($allVariationIds); $i += $batchSize) {
            $batch = array_slice($allVariationIds, $i, $batchSize);

            $attrsData = DB::table('shop_variation_attributes_values as vav')
                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                ->whereIn('vav.variation_id', $batch)
                ->select('vav.variation_id', 'a.id as attribute_id', 'a.name as attribute_name', 'av.id as value_id', 'av.value as value_value')
                ->get()
                ->groupBy('variation_id');

            foreach ($attrsData as $variationId => $attributes) {
                $variationAttributesMap[$variationId] = $attributes->map(function ($attr) {
                    return [
                    'attribute' => [
                    'id' => $attr->attribute_id,
                    'name' => $attr->attribute_name,
                    ],
                    'value' => [
                    'id' => $attr->value_id,
                    'value' => $attr->value_value,
                    ],
                    'attribute_id' => $attr->attribute_id,
                    'attribute_name' => $attr->attribute_name,
                    'value_id' => $attr->value_id,
                    'value_value' => $attr->value_value,
                    'value' => $attr->value_value,
                    ];
                })->toArray();
            }
        }

        // Присваиваем атрибуты вариациям
        foreach ($goods as $good) {
            if ($good->variations) {
                foreach ($good->variations as $variation) {
                    if (isset($variationAttributesMap[$variation->id])) {
                        $variation->attributes = $variationAttributesMap[$variation->id];
                    }
                }
            }
        }
    }

    /**
     * Загрузить характеристики вариаций
     */
    protected function loadVariationCharacteristics($goods): void
    {
        // Загружаем характеристики вариаций
        $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

        if (!$hasVariationIdCol) {
            return;
        }

        foreach ($goods as $good) {
            if ($good->variations) {
                foreach ($good->variations as $variation) {
                    $variationProperties = DB::table('shop_good_properties as sgp')
                        ->join('shop_properties as sp', 'sp.id', '=', 'sgp.property_id')
                        ->leftJoin('shop_property_values as spv', 'spv.id', '=', 'sgp.shop_property_value_id')
                        ->where('sgp.good_id', $good->id)
                        ->where('sgp.variation_id', $variation->id)
                        ->select(
                        'sp.id',
                        'sp.name',
                        'sp.slug',
                        'sgp.shop_property_value_id',
                        'sgp.value as pivot_value',
                        'spv.value as property_value_value'
                    )
                        ->get()
                        ->map(function ($row) {
                        $property = [
                            'id' => $row->id,
                            'name' => $row->name,
                            'slug' => $row->slug,
                            'pivot' => (object)[
                                'shop_property_value_id' => $row->shop_property_value_id,
                                'value' => $row->pivot_value,
                            ],
                        ];

                        if ($row->shop_property_value_id) {
                            $property['property_value'] = (object)[
                                'id' => $row->shop_property_value_id,
                                'value' => $row->property_value_value,
                            ];
                        }
                        elseif ($row->pivot_value) {
                            $property['property_value'] = (object)[
                                'id' => null,
                                'value' => $row->pivot_value,
                            ];
                        }

                        return $property;
                    });

                    $variation->properties = $variationProperties;
                }
            }
        }
    }

    /**
     * Форматировать данные для экспорта
     */
    protected function formatExportData($goods, array $config, int $startRowCounter = 1): array
    {
        $exportRows = [];
        $rowCounter = $startRowCounter;

        foreach ($goods as $good) {
            $hasVariations = $good->variations && $good->variations->count() > 0;

            if (!empty($config['aggregate_variations'])) {
                $row = $this->createExportRow($good, $config, $rowCounter);
                $exportRows[] = $row;
                $rowCounter++;
            }
            elseif ($hasVariations) {
                // Создаем строку для каждой вариации
                foreach ($good->variations as $variation) {
                    $row = $this->createExportRow($good, $config, $rowCounter, $variation);
                    $exportRows[] = $row;
                    $rowCounter++;
                }
            }
            else {
                // Создаем одну строку для товара
                $row = $this->createExportRow($good, $config, $rowCounter);
                $exportRows[] = $row;
                $rowCounter++;
            }
        }

        return $exportRows;
    }

    /**
     * Создать строку экспорта
     */
    protected function createExportRow($good, array $config, int $rowCounter, $variation = null): array
    {
        $row = [];
        $labelCounts = [];

        // Получаем список полей из конфигурации
        $fields = $config['fields'] ?? [];
        if (empty($fields)) {
            // Если поля не указаны, берем стандартные
            $fields = ['id', 'name', 'sku', 'price', 'stock_quantity'];
        }

        foreach ($fields as $field) {
            $label = $this->getFieldLabel($field, $config);
            $value = $this->getFieldValue($good, $field, $config, $variation, $rowCounter);

            // Обработка дублирующихся заголовков (например, при клонировании полей)
            // Добавляем суффикс {{count}} для уникальности ключа массива
            // Этот суффикс будет удален при записи заголовков в файл
            if (isset($labelCounts[$label])) {
                $labelCounts[$label]++;
                $uniqueLabel = $label . '{{' . $labelCounts[$label] . '}}';
            }
            else {
                $labelCounts[$label] = 1;
                $uniqueLabel = $label;
            }

            $row[$uniqueLabel] = $value;
        }

        return $row;
    }

    /**
     * Получить значение поля
     */
    protected function getFieldValue($good, string $field, array $config, $variation = null, int $rowCounter = 0)
    {
        $isArray = is_array($good);

        // Handle cloned fields
        if (strpos($field, '__clone_') !== false) {
            $field = preg_replace('/__clone_\d+$/', '', $field);
        }

        switch ($field) {
            case 'counter':
                return $rowCounter;
            case 'variation':
                return $this->formatVariationAttributes($variation);
            case 'id':
                return $isArray ? ($good['id'] ?? '') : $good->id;
            case 'name':
                return $isArray ? ($good['name'] ?? '') : $good->name;
            case 'sku':
                return (string)($isArray ? ($variation['sku'] ?? '') : ($variation->sku ?? $good->sku));
            case 'description':
                return $isArray ? ($good['description'] ?? '') : $good->description;
            case 'short_description':
                return $isArray ? ($good['short_description'] ?? '') : $good->short_description;
            case 'price':
                if (!empty($config['aggregate_variations'])) {
                    return $this->getAggregatedMinVariationPrice($good);
                }
                return $isArray ? ($variation['price'] ?? $good['price'] ?? 0) : ($variation->price ?? $good->price);
            case 'sale_price':
                if ($variation) {
                    return $isArray ? ($variation['sale_price'] ?? '') : ($variation->sale_price ?? '');
                }

                return $isArray ? ($good['sale_price'] ?? 0) : $good->sale_price;
            case 'demping_price':
                if ($variation) {
                    return $isArray ? ($variation['demping_price'] ?? '') : ($variation->demping_price ?? '');
                }

                return $isArray ? ($good['demping_price'] ?? 0) : $good->demping_price;
            case 'stock_quantity':
                return $isArray ? ($variation['stock_quantity'] ?? $good['stock_quantity'] ?? 0) : ($variation->stock_quantity ?? $good->stock_quantity);
            case 'full_stock':
                if (!empty($config['aggregate_variations'])) {
                    return $this->getAggregatedFullStockValue($good);
                }

                return $this->getFullStockValue($variation ?: $good);
            case 'remote_stock':
            case 'remote_stock_quantity':
                return $isArray ? ($variation['remote_stock_quantity'] ?? $good['remote_stock_quantity'] ?? '') : ($variation->remote_stock_quantity ?? $good->remote_stock_quantity);
            case 'fast_remote_stock_quantity':
                return $isArray ? ($variation['fast_remote_stock_quantity'] ?? $good['fast_remote_stock_quantity'] ?? '') : ($variation->fast_remote_stock_quantity ?? $good->fast_remote_stock_quantity);
            case 'weight':
            case 'width':
            case 'height':
            case 'length':
                return $this->applyDimensionMultiplier(
                    $field,
                    $this->getDimensionValueForExport($good, $variation, $field),
                    $config
                );
            case 'category':
            case 'categories':
                if ($isArray) {
                    $categories = $good['categories'] ?? [];
                    $names = array_column($categories, 'name');

                    return implode(', ', array_filter($names));
                }

                return $good->categories ? $good->categories->pluck('name')->filter()->join(', ') : '';
            case 'brand':
            case 'brands':
                if ($isArray) {
                    $brands = $good['brands'] ?? [];
                    $names = array_column($brands, 'name');

                    return implode(', ', array_filter($names));
                }

                return $good->brands ? $good->brands->pluck('name')->filter()->join(', ') : '';
            case 'tags':
                if ($isArray) {
                    $tags = $good['tags'] ?? [];
                    $names = array_column($tags, 'name');

                    return implode(', ', array_filter($names));
                }

                return $good->tags ? $good->tags->pluck('name')->filter()->join(', ') : '';
            case 'label':
                if ($isArray) {
                    $label = $good['label'] ?? null;

                    return $label && isset($label['name']) ? $label['name'] : '';
                }

                return $good->label && isset($good->label->name) ? $good->label->name : '';
            case 'type':
            case 'model':
            case 'year':
                return $isArray ? '' : $this->getParsedFieldValue($good, $field, $config);
            case 'properties':
                return $this->formatGoodProperties($good);
            case 'images':
            case 'related_images':
                if (!empty($config['aggregate_variations'])) {
                    return implode(', ', array_slice($this->getAggregatedImageUrls($good, 10), 1));
                }

                return $this->formatGoodImages($good, $variation);
            case 'main_image':
                if (!empty($config['aggregate_variations'])) {
                    $urls = $this->getAggregatedImageUrls($good, 10);

                    return $urls[0] ?? '';
                }

                $baseUrl = config('app.frontend_url', env('FRONTEND_URL', ''));
                $mainImg = null;

                // Если есть вариация, сначала ищем изображение в ней
                if ($variation) {
                    $varImages = $isArray ? ($variation['images'] ?? []) : ($variation->images ?? []);

                    foreach ($varImages as $img) {
                        $isMain = $isArray ? ($img['is_main'] ?? false) : $img->is_main;
                        if ($isMain) {
                            $mainImg = $img;
                            break;
                        }
                    }

                    if (!$mainImg && count($varImages) > 0) {
                        $mainImg = is_array($varImages) ? reset($varImages) : $varImages->first();
                    }
                }

                // Если не нашли в вариации (или это не вариация), ищем в основном товаре
                if (!$mainImg) {
                    $images = $isArray ? ($good['images'] ?? []) : ($good->images ?? []);

                    foreach ($images as $img) {
                        $isMain = $isArray ? ($img['is_main'] ?? false) : $img->is_main;
                        if ($isMain) {
                            $mainImg = $img;
                            break;
                        }
                    }
                    if (!$mainImg && count($images) > 0) {
                        $mainImg = is_array($images) ? reset($images) : $images->first();
                    }
                }

                if ($mainImg) {
                    $path = $isArray ? ($mainImg['file_path'] ?? '') : $mainImg->file_path;
                    if ($path) {
                        return $baseUrl . $path;
                    }
                }

                return '';

            default:
                // Проверяем на кастомные поля (статичные значения из конфигурации)
                if (isset($config['custom_fields']) && is_array($config['custom_fields'])) {
                    foreach ($config['custom_fields'] as $customField) {
                        // Support both raw field name and suffix-stripped name
                        $cleanField = $field;
                        if (strpos($cleanField, '__clone_') !== false) {
                            $cleanField = preg_replace('/__clone_\d+$/', '', $cleanField);
                        }

                        if (isset($customField['id']) && $customField['id'] === $cleanField) {
                            return $customField['value'] ?? '';
                        }
                    }
                }

                // Проверяем на характеристики и атрибуты вариаций
                // Сначала проверяем старый формат с префиксами
                if (str_starts_with($field, 'prop_')) {
                    return $this->getPropertyValueForExport($good, $variation, $field);
                }
                if (str_starts_with($field, 'attr_')) {
                    return $this->getVariationAttributeValueForExport($variation, $field);
                }

                // Новый формат: характеристики могут быть по ID или по имени
                // Сначала пытаемся найти как характеристику товара
                $propertyValue = $this->findPropertyByIdOrName($good, $field);
                if ($propertyValue !== null) {
                    return $propertyValue;
                }

                // Если не нашли как характеристику, пытаемся найти как атрибут вариации
                if ($variation) {
                    $attributeValue = $this->findVariationAttributeByIdOrName($variation, $field);
                    if ($attributeValue !== null) {
                        return $attributeValue;
                    }
                }

                // Проверяем на кастомные поля разбора
                if (in_array($field, ['type', 'model', 'year'])) {
                    return $this->getParsedFieldValue($good, $field, $config);
                }

                return '';
        }
    }

    /**
     * Получить габарит или вес для экспорта.
     * Для вариаций сначала берем значение вариации, затем fallback на основной товар.
     * У основного товара длина исторически хранится в depth, поэтому поддерживаем оба ключа.
     */
    protected function getDimensionValueForExport($good, $variation, string $field)
    {
        $readValue = function ($source, string $key) {
            if (!$source) {
                return null;
            }

            if (is_array($source)) {
                return array_key_exists($key, $source) && $source[$key] !== null ? $source[$key] : null;
            }

            return isset($source->{$key}) ? $source->{$key} : null;
        };

        $keys = $field === 'length' ? ['length', 'depth'] : [$field];

        foreach ([$variation, $good] as $source) {
            foreach ($keys as $key) {
                $value = $readValue($source, $key);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return '';
    }

    protected function applyDimensionMultiplier(string $field, $value, array $config)
    {
        if ($value === '' || $value === null) {
            return '';
        }

        if (!is_numeric(str_replace(',', '.', (string) $value))) {
            return $value;
        }

        $multipliers = $config['dimension_multipliers'] ?? [];
        $multiplier = $this->normalizeDimensionMultiplier($multipliers[$field] ?? 1);

        return (float) str_replace(',', '.', (string) $value) * $multiplier;
    }

    protected function normalizeDimensionMultiplier($value): float
    {
        if ($value === null || $value === '') {
            return 1.0;
        }

        $numeric = (float) str_replace(',', '.', (string) $value);

        return $numeric > 0 ? $numeric : 1.0;
    }

    protected function getFullStockValue($item): int
    {
        if (!$item) {
            return 0;
        }

        $stock = max(0, (int) $this->readExportValue($item, 'stock_quantity', 0));

        return $stock
            + ($this->hasRemoteStockValue($this->readExportValue($item, 'remote_stock_quantity')) ? 10 : 0)
            + ($this->hasRemoteStockValue($this->readExportValue($item, 'fast_remote_stock_quantity')) ? 10 : 0);
    }

    protected function getAggregatedFullStockValue($good): int
    {
        $variations = $this->getExportCollection($this->readExportValue($good, 'variations', []));
        if ($variations->isNotEmpty()) {
            return (int) $variations
                ->filter(fn ($variation) => $this->readExportValue($variation, 'is_active', true) !== false)
                ->sum(fn ($variation) => $this->getFullStockValue($variation));
        }

        return $this->getFullStockValue($good);
    }

    protected function getAggregatedMinVariationPrice($good)
    {
        $variations = $this->getExportCollection($this->readExportValue($good, 'variations', []))
            ->filter(fn ($variation) => $this->readExportValue($variation, 'is_active', true) !== false);

        if ($variations->isEmpty()) {
            return $this->readExportValue($good, 'price', '');
        }

        $inStockVariations = $variations->filter(fn ($variation) => $this->getFullStockValue($variation) > 0);
        $sourceVariations = $inStockVariations->isNotEmpty() ? $inStockVariations : $variations;
        $prices = $sourceVariations
            ->map(fn ($variation) => $this->calculateFinalExportPrice($variation))
            ->filter(fn ($price) => $price > 0)
            ->values();

        return $prices->isNotEmpty() ? $prices->min() : $this->readExportValue($good, 'price', '');
    }

    protected function calculateFinalExportPrice($item): float
    {
        $price = (float) $this->readExportValue($item, 'price', 0);
        $salePrice = (float) $this->readExportValue($item, 'sale_price', 0);
        $dempingPrice = (float) $this->readExportValue($item, 'demping_price', 0);

        if ($this->readExportValue($item, 'show_demping', false) && $dempingPrice > 0) {
            return $dempingPrice;
        }

        if ($salePrice > 0) {
            return $salePrice;
        }

        return $price;
    }

    protected function getAggregatedImageUrls($good, int $limit = 10): array
    {
        $baseUrl = config('app.frontend_url', env('FRONTEND_URL', ''));
        $urls = [];
        $pushImage = function ($image) use (&$urls, $baseUrl, $limit) {
            if (count($urls) >= $limit) {
                return;
            }

            $path = $this->readExportValue($image, 'file_path', $this->readExportValue($image, 'url', ''));
            if (!$path) {
                return;
            }

            $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                ? $path
                : rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

            if (!in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        };

        $variations = $this->getExportCollection($this->readExportValue($good, 'variations', []))
            ->filter(fn ($variation) => $this->readExportValue($variation, 'is_active', true) !== false);

        foreach ($variations as $variation) {
            $images = $this->getExportCollection($this->readExportValue($variation, 'images', []))
                ->sortByDesc(fn ($image) => (int) (bool) $this->readExportValue($image, 'is_main', false));

            foreach ($images as $image) {
                $pushImage($image);
            }
        }

        $goodImages = $this->getExportCollection($this->readExportValue($good, 'images', []))
            ->sortByDesc(fn ($image) => (int) (bool) $this->readExportValue($image, 'is_main', false));

        foreach ($goodImages as $image) {
            $pushImage($image);
        }

        return $urls;
    }

    protected function hasRemoteStockValue($value): bool
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' && $normalized !== '0';
    }

    protected function readExportValue($source, string $key, $default = null)
    {
        if (!$source) {
            return $default;
        }

        if (is_array($source)) {
            return array_key_exists($key, $source) ? $source[$key] : $default;
        }

        return isset($source->{$key}) ? $source->{$key} : $default;
    }

    protected function getExportCollection($value)
    {
        if ($value instanceof \Illuminate\Support\Collection) {
            return $value;
        }

        if (is_array($value)) {
            return collect($value);
        }

        return collect();
    }

    /**
     * Получить название поля
     */
    protected function getFieldLabel(string $field, array $config): string
    {
        // Возвращаем название поля из конфигурации или стандартное
        $fieldLabels = $config['field_labels'] ?? [];
        if (!is_array($fieldLabels)) {
            return $field;
        }

        return $fieldLabels[$field] ?? $field;
    }

    /**
     * Форматировать атрибуты вариации
     */
    protected function formatVariationAttributes($variation): string
    {
        if (!$variation) {
            return '';
        }

        $attributes = [];
        if (is_object($variation)) {
            $attributes = $variation->attributes ?? [];
        }
        elseif (is_array($variation)) {
            $attributes = $variation['attributes'] ?? [];
        }

        if (empty($attributes)) {
            return '';
        }

        $formatted = [];
        foreach ($attributes as $attr) {
            // Проверяем разные возможные структуры данных
            $attr = (array)$attr;
            $attrName = $attr['attribute_name'] ?? $attr['name'] ?? 'unknown';
            $attrValue = $attr['value_value'] ?? $attr['value'] ?? '';
            $formatted[] = $attrName . ': ' . $attrValue;
        }

        return implode(', ', $formatted);
    }

    /**
     * Форматировать характеристики товара
     */
    protected function formatGoodProperties($good): string
    {
        if (!$good || !isset($good->properties)) {
            return '';
        }

        $properties = [];
        foreach ($good->properties as $property) {
            $value = '';
            if (isset($property->property_value)) {
                $value = $property->property_value->value ?? '';
            }
            $properties[] = $property->name . ': ' . $value;
        }

        return implode('; ', $properties);
    }

    /**
     * Форматировать изображения товара
     */
    protected function formatGoodImages($good, $variation = null): string
    {
        $images = [];
        $baseUrl = config('app.frontend_url', env('FRONTEND_URL', ''));
        $isArray = is_array($good);

        if ($variation) {
            $varImages = $isArray ? ($variation['images'] ?? []) : ($variation->images ?? []);
            // Если images - это Collection, преобразуем
            if (!is_array($varImages) && method_exists($varImages, 'toArray')) {
                $varImages = $varImages; // Iterate directly over collection
            }

            foreach ($varImages as $image) {
                $path = $isArray ? ($image['file_path'] ?? '') : $image->file_path;
                if ($path) {
                    $images[] = $baseUrl . $path;
                }
            }
        }

        $goodImages = $isArray ? ($good['images'] ?? []) : ($good->images ?? []);

        foreach ($goodImages as $image) {
            $imgId = $isArray ? ($image['id'] ?? null) : $image->id;
            $path = $isArray ? ($image['file_path'] ?? '') : $image->file_path;

            $skip = false;
            if ($variation) {
                $varImages = $isArray ? ($variation['images'] ?? []) : ($variation->images ?? []);
                // Collection contains check
                if (!$isArray && method_exists($varImages, 'contains')) {
                    if ($varImages->contains('id', $imgId)) {
                        $skip = true;
                    }
                }
                else {
                    foreach ($varImages as $vImg) {
                        $vId = $isArray ? ($vImg['id'] ?? null) : $vImg->id;
                        if ($vId == $imgId) {
                            $skip = true;
                            break;
                        }
                    }
                }
            }

            if (!$skip && $path) {
                $images[] = $baseUrl . $path;
            }
        }

        return implode(', ', $images);
    }

    /**
     * Получить значение характеристики
     */
    protected function getPropertyValueForExport($good, $variation, string $field): string
    {
        // prop_{property_id}
        $propertyId = str_replace('prop_', '', $field);

        if (!$good || !isset($good->properties)) {
            return '';
        }

        foreach ($good->properties as $property) {
            if ($property->id == $propertyId && isset($property->property_value)) {
                return $property->property_value->value ?? '';
            }
        }

        return '';
    }

    /**
     * Найти характеристику товара по ID или имени
     */
    protected function findPropertyByIdOrName($good, string $field): ?string
    {
        if (!$good || !isset($good->properties)) {
            return null;
        }

        foreach ($good->properties as $property) {
            // Проверяем по ID (если field - число)
            if (is_numeric($field) && $property->id == $field) {
                return $property->property_value->value ?? '';
            }

            // Проверяем по имени
            if ($property->name === $field && isset($property->property_value)) {
                return $property->property_value->value ?? '';
            }
        }

        return null;
    }

    /**
     * Получить разобранное значение поля (type, model, year)
     */
    protected function getParsedFieldValue($good, string $field, array $config): string
    {
        try {
            // Получаем field type mappings из конфигурации
            $fieldTypeMappings = $config['field_type_mappings'] ?? [];

            // Получаем название товара
            $nameField = $fieldTypeMappings['name'] ?? null;
            $nameValue = $nameField ? ($good->{ $nameField} ?? $good->name) : $good->name;

            // Получаем бренд
            $brandField = $fieldTypeMappings['brand'] ?? null;
            $brandValue = '';
            if ($brandField) {
                if ($brandField === 'brands' && $good->brands && $good->brands->count() > 0) {
                    $brandValue = $good->brands->pluck('name')->join(' ');
                }
                else {
                    $brandValue = $good->{ $brandField} ?? '';
                }
            }
            elseif ($good->brands && $good->brands->count() > 0) {
                $brandValue = $good->brands->pluck('name')->join(' ');
            }

            if (!$nameValue || !$brandValue) {
                return '';
            }

            // Разбираем название
            return $this->parseProductName($nameValue, $brandValue, $field);
        }
        catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Функция разбора названия товара
     */
    protected function parseProductName(string $name, string $brand, string $fieldType): string
    {
        if (!$name || !$brand) {
            return '';
        }

        // Находим позицию бренда в названии (регистронезависимо)
        $brandIndex = stripos($name, $brand);

        if ($fieldType === 'type') {
            // Тип - это часть до бренда
            if ($brandIndex === false) {
                return '';
            }

            return trim(substr($name, 0, $brandIndex));
        }

        if ($fieldType === 'model' || $fieldType === 'year') {
            // Модель и год - это часть после бренда
            if ($brandIndex === false) {
                return '';
            }

            $afterBrand = trim(substr($name, $brandIndex + strlen($brand)));
            if (!$afterBrand) {
                return '';
            }

            if ($fieldType === 'year') {
                // Ищем год в оставшейся части
                return $this->extractYearFromText($afterBrand);
            }
            else {
                // Модель - все остальное после удаления года
                $year = $this->extractYearFromText($afterBrand);
                if ($year) {
                    return trim(str_replace($year, '', $afterBrand));
                }

                return $afterBrand;
            }
        }

        return '';
    }

    /**
     * Функция извлечения года из текста
     */
    protected function extractYearFromText(string $text): string
    {
        if (!$text) {
            return '';
        }

        // Ищем 4-значные числа в диапазоне лет
        preg_match_all('/\b(20\d{2}|19\d{2})\b/', $text, $matches);

        if (!empty($matches[0])) {
            return $matches[0][0];
        }

        return '';
    }

    /**
     * Найти атрибут вариации по ID или имени
     */
    protected function findVariationAttributeByIdOrName($variation, string $field): ?string
    {
        if (!$variation || !isset($variation->attributes)) {
            return null;
        }

        foreach ($variation->attributes as $attr) {
            // Проверяем по ID атрибута
            if (is_numeric($field) && isset($attr['attribute_id']) && $attr['attribute_id'] == $field) {
                return $attr['value'] ?? '';
            }

            // Проверяем по имени атрибута
            if (isset($attr['attribute_name']) && $attr['attribute_name'] === $field) {
                return $attr['value'] ?? '';
            }
        }

        return null;
    }

    /**
     * Получить значение атрибута вариации
     */
    protected function getVariationAttributeValueForExport($variation, string $field): string
    {
        // attr_{attribute_id} или attr_{attribute_name}
        $attrIdentifier = str_replace('attr_', '', $field);

        if (!$variation || !isset($variation->attributes)) {
            return '';
        }

        // Сначала ищем по ID
        foreach ($variation->attributes as $attr) {
            if (isset($attr['attribute_id']) && $attr['attribute_id'] == $attrIdentifier) {
                return $attr['value'] ?? '';
            }
        }

        // Затем по имени (проверяем разные варианты)
        $possibleNames = [$attrIdentifier];
        if ($attrIdentifier === 'color') {
            $possibleNames[] = 'Цвет';
        }
        elseif ($attrIdentifier === 'size') {
            $possibleNames[] = 'Размер';
        }

        foreach ($variation->attributes as $attr) {
            $attrName = $attr['attribute_name'] ?? $attr['name'] ?? '';
            if (in_array($attrName, $possibleNames)) {
                return $attr['value_value'] ?? $attr['value'] ?? '';
            }
        }

        return '';
    }

    /**
     * Генерировать содержимое файла
     */
    protected function generateFileContent(array $data, string $format): string
    {
        if (empty($data)) {
            return '';
        }

        switch ($format) {
            case 'csv':
                return $this->generateCsv($data);
            case 'txt':
                return $this->generateTxt($data);
            case 'excel':
            default:
                return $this->generateExcel($data);
        }
    }

    /**
     * Генерировать CSV
     */
    protected function generateCsv(array $data): string
    {

        $output = '';

        if (!empty($data)) {
            $headers = array_keys($data[0]);
            $output .= implode(',', array_map(function ($header) {
                // Remove {{...}} suffix
                $cleanHeader = preg_replace('/\{\{\d+\}\}$/', '', $header);

                return '"' . str_replace('"', '""', $cleanHeader) . '"';
            }, $headers)) . "\n";

            foreach ($data as $row) {
                $values = array_map(function ($value) {
                    if (is_array($value) || is_object($value)) {
                        return '"' . json_encode($value) . '"';
                    }

                    return '"' . str_replace('"', '""', (string)$value) . '"';
                }, array_values($row));
                $output .= implode(',', $values) . "\n";
            }
        }

        return $output;
    }

    /**
     * Генерировать TXT
     */
    protected function generateTxt(array $data): string
    {
        // Простая TXT генерация
        $output = '';

        if (!empty($data)) {
            $headers = array_keys($data[0]);
            $cleanHeaders = array_map(function ($header) {
                return preg_replace('/\{\{\d+\}\}$/', '', $header);
            }, $headers);
            $output .= implode("\t", $cleanHeaders) . "\n";

            foreach ($data as $row) {
                $values = array_map(function ($value) {
                    if (is_array($value) || is_object($value)) {
                        return json_encode($value);
                    }

                    return (string)$value;
                }, array_values($row));
                $output .= implode("\t", $values) . "\n";
            }
        }

        return $output;
    }

    /**
     * Генерировать настоящий Excel файл
     */
    protected function generateExcel(array $data): string
    {
        $config = $this->exportFile->export_config ?? [];
        $templatePath = $config['template_path'] ?? null;

        if ($templatePath && Storage::exists($templatePath)) {
            // Используем шаблон
            $fullPath = Storage::path($templatePath);
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fullPath);
            }
            catch (\Exception $e) {
                Log::warning('Failed to load export template: ' . $e->getMessage());
                $spreadsheet = new Spreadsheet;
            }

            $sheet = $spreadsheet->getActiveSheet();

            if (!empty($data)) {
                $startRow = isset($config['excel_template_start_row']) ? (int)$config['excel_template_start_row'] : 2;
                $includeHeaders = filter_var($config['excel_template_include_headers'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $headerRow = isset($config['excel_template_header_row']) ? (int)$config['excel_template_header_row'] : 1;

                // Записываем заголовки, если нужно
                if ($includeHeaders) {
                    $firstRow = $data[0];
                    if (is_array($firstRow)) {
                        $headers = array_keys($firstRow);
                        $col = 'A';
                        foreach ($headers as $header) {
                            $cleanHeader = preg_replace('/\{\{\d+\}\}$/', '', $header);
                            $sheet->setCellValue($col . $headerRow, $cleanHeader);
                            $col++;
                        }
                    }
                }

                // Записываем данные
                $row = $startRow;
                $highestColumnIndex = 0;

                // Определяем максимальное количество колонок
                foreach ($data as $dataRow) {
                    if (is_array($dataRow)) {
                        $count = count($dataRow);
                        if ($count > $highestColumnIndex) {
                            $highestColumnIndex = $count;
                        }
                    }
                }

                // Преобразуем индекс в букву (1 -> A, 2 -> B, etc.)
                $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($highestColumnIndex);

                foreach ($data as $dataRow) {
                    if (is_array($dataRow)) {
                        $col = 'A';
                        foreach ($dataRow as $key => $value) {
                            if (is_array($value) || is_object($value)) {
                                $value = json_encode($value);
                            }
                            elseif ($value === null) {
                                $value = '';
                            }
                            else {
                                $value = (string)$value;
                            }
                            $sheet->setCellValue($col . $row, $value);
                            $col++;
                        }
                        $row++;
                    }
                }

                // Копируем стили с начальной строки на все остальные строки
                $lastRow = $row - 1;
                if ($lastRow > $startRow) {
                    try {
                        $styleSourceRange = "A{$startRow}:{$highestColumn}{$startRow}";
                        $styleTargetRange = 'A' . ($startRow + 1) . ":{$highestColumn}{$lastRow}";
                        $sheet->duplicateStyle($sheet->getStyle($styleSourceRange), $styleTargetRange);
                    }
                    catch (\Exception $e) {
                        Log::warning('Failed to duplicate styles: ' . $e->getMessage());
                    }
                }
            }

            // Создаем writer и сохраняем в память
            $writer = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $excelContent = ob_get_clean();

            // Очищаем память
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return $excelContent;
        }
        else {
            // Стандартная генерация (без шаблона)
            // Если вызов пришел сюда (а не через handle -> generateExcelWithSpout),
            // значит нам нужно вернуть контент строкой (fallback).
            $tempPath = tempnam(sys_get_temp_dir(), 'export_xls_');
            $this->generateExcelWithSpout($data, $tempPath);
            $content = file_get_contents($tempPath);
            @unlink($tempPath);

            return $content;
        }
    }

    /**
     * Генерировать Excel с использованием OpenSpout (для больших файлов)
     * Пишет напрямую в файл по указанному пути
     */
    protected function generateExcelWithSpout(array $data, string $filePath): void
    {
        $options = new XlsxOptions;
        $writer = new XlsxWriter($options);
        $writer->openToFile($filePath);

        if (!empty($data)) {
            // Записываем заголовки
            $firstRow = $data[0];
            if (is_array($firstRow)) {
                $headers = array_keys($firstRow);
                $headerCells = array_map(function ($value) {
                    $cleanValue = preg_replace('/\{\{\d+\}\}$/', '', (string)$value);

                    return Cell::fromValue($cleanValue);
                }, $headers);
                $writer->addRow(new Row($headerCells));
            }

            // Записываем данные
            foreach ($data as $dataRow) {
                if (is_array($dataRow)) {
                    $cells = array_map(function ($value) {
                        if (is_array($value) || is_object($value)) {
                            $strVal = json_encode($value, JSON_UNESCAPED_UNICODE);
                        }
                        elseif ($value === null) {
                            $strVal = '';
                        }
                        else {
                            $strVal = (string)$value;
                        }

                        // Защита от инъекций формул
                        if (str_starts_with($strVal, '=')) {
                            $strVal = ' ' . $strVal;
                        }

                        return Cell::fromValue($strVal);
                    }, array_values($dataRow));

                    $writer->addRow(new Row($cells));
                }
            }
        }

        $writer->close();
    }

    /**
     * Обработка экспорта для Авито
     */
    protected function handleAvitoExport(): void
    {
        try {
            $this->exportFile->update(['status' => 'processing']);

            // Получаем конфигурацию и фильтры
            $config = $this->exportFile->export_config ?? [];
            
            // Получаем запрос с учетом всех фильтров
            $query = $this->getExportQuery($config);
            $goods = $query->with([
                'categories', 
                'brands', 
                'images', 
                'variations', 
                'variations.images', 
                'variations.attributeValues', 
                'variations.attributeValues.attribute', 
                'properties',
                'shopSupplier',
                'variations.shopSupplier'
            ])->get();

            $service = new \App\Services\AvitoFeedService();
            // Генерируем XML контент с учетом отфильтрованных товаров
            $result = $service->generate(null, $goods);

            if (!isset($result['error'])) {
                $xmlContent = $result['content'];
                // Храним архивную копию для конкретной записи экспорта и отдельно обновляем постоянный файл фида.
                $archiveFilePath = 'exports/' . $this->exportFile->filename;
                $permanentFilename = 'avito.xml';
                $permanentFilePath = 'exports/' . $permanentFilename;
                
                Storage::put($archiveFilePath, $xmlContent);
                Storage::put($permanentFilePath, $xmlContent);
                $fileSize = strlen($xmlContent);

                // Дополнительно генерируем файл с остатками
                try {
                    $stocksXml = $service->generateStocks($goods);
                    Storage::put('exports/avito_stocks.xml', $stocksXml);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Avito Stocks Export failed: ' . $e->getMessage());
                }

                $this->exportFile->update([
                    'status' => 'completed',
                    'file_path' => $archiveFilePath,
                    'file_size' => $fileSize,
                    'total_rows' => $result['count'] ?? $goods->count(),
                    'updated_at' => now(),
                ]);

                ExportFile::updateOrCreate(
                    ['filename' => $permanentFilename],
                    [
                        'created_by' => $this->exportFile->created_by,
                        'original_filename' => 'Основной фид Avito.xml',
                        'file_path' => $permanentFilePath,
                        'format' => 'avito_xml',
                        'status' => 'completed',
                        'total_rows' => $result['count'] ?? $goods->count(),
                        'file_size' => $fileSize,
                        'error_message' => null,
                        'export_config' => [
                            'is_avito_permanent' => true,
                            'source_export_file_id' => $this->exportFile->id,
                            'source_filename' => $this->exportFile->filename,
                            'updated_from_export_at' => now()->toDateTimeString(),
                        ],
                    ]
                );
            }
            else {
                throw new \Exception('Ошибка при генерации фида Авито: ' . ($result['error'] ?? 'Неизвестная ошибка'));
            }
        }
        catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Avito Export failed: ' . $e->getMessage());
            $this->exportFile->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
