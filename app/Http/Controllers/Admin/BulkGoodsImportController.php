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
     * РќРѕСЂРјР°Р»РёР·СѓРµС‚ С‚РµРєСЃС‚: СѓРґР°Р»СЏРµС‚ РґРІРѕР№РЅС‹Рµ РїСЂРѕР±РµР»С‹ Рё Р»РёС€РЅРёРµ РїСЂРѕР±РµР»С‹ РІ РЅР°С‡Р°Р»Рµ/РєРѕРЅС†Рµ
     */
    private function normalizeText(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        // РЈР±РёСЂР°РµРј РїСЂРѕР±РµР»С‹ РІ РЅР°С‡Р°Р»Рµ Рё РєРѕРЅС†Рµ
        $text = trim($text);

        // Р—Р°РјРµРЅСЏРµРј РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рµ РїСЂРѕР±РµР»С‹ РЅР° РѕРґРёРЅ
        $text = preg_replace('/\s+/', ' ', $text);

        return $text;
    }

    /**
     * РќРѕСЂРјР°Р»РёР·СѓРµС‚ С‚РµРєСЃС‚ РґР»СЏ РїРѕРёСЃРєР° РІ Р±Р°Р·Рµ РґР°РЅРЅС‹С… (РґР»СЏ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё СЃРѕ СЃС‚Р°СЂС‹РјРё Р·Р°РїРёСЃСЏРјРё)
     * РСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РґР»СЏ РїРѕРёСЃРєР° С‚РѕРІР°СЂРѕРІ СЃ СѓС‡РµС‚РѕРј РґРІРѕР№РЅС‹С… РїСЂРѕР±РµР»РѕРІ
     */
    private function normalizeTextForSearch(string $text): string
    {
        return $this->normalizeText($text);
    }

    public function bulkImport(Request $request)
    {

        // РћРїСЂРµРґРµР»СЏРµРј РёРјСЏ РїРѕСЃС‚Р°РІС‰РёРєР°
        $supplierNameFromRequest = $request->input('supplier_name');
        if ($supplierNameFromRequest && $supplierNameFromRequest !== '__reset_all__') {
            $supplierNameFromRequest = trim($supplierNameFromRequest);
        }

        // РџРѕР»СѓС‡Р°РµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ Р±Р°С‚С‡Рµ РґР»СЏ РѕС‡РёСЃС‚РєРё Р»РѕРіРѕРІ
        $isFirstBatch = $request->input('is_first_batch', false);

        // РџРѕР»СѓС‡Р°РµРј РІС‹Р±СЂР°РЅРЅС‹Рµ РїРѕР»СЏ РѕСЃС‚Р°С‚РєРѕРІ РёР· Р·Р°РїСЂРѕСЃР° РґР»СЏ РѕР±РЅСѓР»РµРЅРёСЏ
        $resetStockFields = $request->input('reset_stock_fields', []);

        // РћРїСЂРµРґРµР»СЏРµРј supplierStockFields РґР»СЏ РѕР±РЅСѓР»РµРЅРёСЏ Рё РёСЃРїРѕР»СЊР·РѕРІР°РЅРёСЏ РІ РѕСЃС‚Р°Р»СЊРЅРѕР№ С‡Р°СЃС‚Рё РјРµС‚РѕРґР°
        $supplierStockFields = [];
        if (!empty($resetStockFields)) {
            foreach ($resetStockFields as $field) {
                $supplierStockFields[$field] = true;
            }
        }

        // Р’С‹РїРѕР»РЅСЏРµРј РѕР±РЅСѓР»РµРЅРёРµ РѕСЃС‚Р°С‚РєРѕРІ РїРµСЂРµРґ РёРјРїРѕСЂС‚РѕРј (С‚РѕР»СЊРєРѕ РґР»СЏ РїРµСЂРІРѕРіРѕ Р±Р°С‚С‡Р°)
        $resetStats = null;
        if ($isFirstBatch && !empty($supplierStockFields) && $supplierNameFromRequest) {
            $resetStats = $this->resetSupplierStocks($supplierNameFromRequest, $supplierStockFields);

        }

        // РћС‡РёС‰Р°РµРј Р»РѕРіРё С‚РѕР»СЊРєРѕ РґР»СЏ РїРµСЂРІРѕРіРѕ Р±Р°С‚С‡Р°
        if ($isFirstBatch) {
            $this->importLogService->clearAllLogs();
        }

        // РЎРѕР·РґР°РµРј СЂРµР·РµСЂРІРЅСѓСЋ РєРѕРїРёСЋ РїРµСЂРµРґ РёРјРїРѕСЂС‚РѕРј (С‚РѕР»СЊРєРѕ РґР»СЏ РїРµСЂРІРѕРіРѕ Р±Р°С‚С‡Р°) - РІСЂРµРјРµРЅРЅРѕ РѕС‚РєР»СЋС‡РµРЅРѕ
        // $backupId = null;
        // $createBackup = $request->input('create_backup_before_import', false);
        // if ($isFirstBatch && $createBackup) {
        //     try {
        //         //         $backup = $this->backupService->createBackup(
        //             'Р РµР·РµСЂРІРЅР°СЏ РєРѕРїРёСЏ РїРµСЂРµРґ РёРјРїРѕСЂС‚РѕРј ' . now()->format('d.m.Y H:i'),
        //             auth()->id()
        //         );

        //         $backupId = $backup->id;

        //         //     } catch (\Exception $e) {
        //         Log::error('РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ СЂРµР·РµСЂРІРЅРѕР№ РєРѕРїРёРё РїРµСЂРµРґ РёРјРїРѕСЂС‚РѕРј', [
        //             'error' => $e->getMessage(),
        //             'user_id' => auth()->id()
        //         ]);

        //         // РџСЂРѕРґРѕР»Р¶Р°РµРј РёРјРїРѕСЂС‚, РЅРѕ Р»РѕРіРёСЂСѓРµРј РѕС€РёР±РєСѓ
        //     }
        // }

        // Получаем данные товаров
        $allGoods = $request->input('goods', []);

        // ГАРАНТИРОВАННАЯ ПРОВЕРКА (Удалите после теста)
        if ($request->has('debug_test')) {
             \Log::error('BulkImport: DEBUG TRIGGERED');
             dd(['status' => 'WORKING', 'naming' => $request->input('images_naming')]);
        }

        \Log::error('BulkImport: Method started', [
            'naming' => $request->input('images_naming', 'hash'),
            'goods_count' => count($allGoods)
        ]);

        // РџРѕР»СѓС‡Р°РµРј РїР°СЂР°РјРµС‚СЂС‹ РёРјРїРѕСЂС‚Р°
        $nameTrimSymbol = $request->input('name_trim_symbol');
        $naming = $request->input('images_naming', 'hash');

        // Р¤РёР»СЊС‚СЂСѓРµРј РїСѓСЃС‚С‹Рµ СЃС‚СЂРѕРєРё - РѕСЃС‚Р°РІР»СЏРµРј С‚РѕР»СЊРєРѕ С‚РѕРІР°СЂС‹ СЃ Р·Р°РїРѕР»РЅРµРЅРЅС‹РјРё SKU Рё РЅР°Р·РІР°РЅРёРµРј
        $goods = [];
        $skippedRows = [];

        foreach ($allGoods as $index => $good) {
            $sku = isset($good['sku']) ? trim((string) $good['sku']) : '';
            $name = isset($good['name']) ? $this->normalizeText((string) $good['name']) : '';

            // РџРѕР»СѓС‡Р°РµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ Р»РёСЃС‚Рµ
            $sheet = $good['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';

            // РЎРѕР·РґР°РµРј СѓРЅРёРєР°Р»СЊРЅС‹Р№ РёРґРµРЅС‚РёС„РёРєР°С‚РѕСЂ РґР»СЏ С‚РѕРІР°СЂР°
            $itemId = $sheet . '_' . $index . '_' . substr(md5($sku . $name), 0, 8);

            // РџСЂРѕРїСѓСЃРєР°РµРј С‚РѕР»СЊРєРѕ РїРѕР»РЅРѕСЃС‚СЊСЋ РїСѓСЃС‚С‹Рµ СЃС‚СЂРѕРєРё (РіРґРµ Р SKU Р РЅР°Р·РІР°РЅРёРµ РїСѓСЃС‚С‹Рµ)
            if (empty($sku) && empty($name)) {
                $reason = 'РџСѓСЃС‚Р°СЏ СЃС‚СЂРѕРєР°';

                $skippedRows[] = [
                    'count' => $index + 1,
                    'sku' => $sku,
                    'name' => $name,
                    'sheet' => $sheet,
                    'reason' => $reason,
                ];

                continue;
            }

            // Р•СЃР»Рё РЅРµС‚ РЅРё SKU, РЅРё РЅР°Р·РІР°РЅРёСЏ - РїСЂРѕРїСѓСЃРєР°РµРј СЃС‚СЂРѕРєСѓ
            if (empty($sku) && empty($name)) {
                $reason = 'РћС‚СЃСѓС‚СЃС‚РІСѓРµС‚ SKU Рё РЅР°Р·РІР°РЅРёРµ';
                $skippedRows[] = [
                    'count' => $index + 1,
                    'sku' => $sku,
                    'name' => $name,
                    'sheet' => $sheet,
                    'reason' => $reason,
                ];

                continue;
            }

            // Р•СЃР»Рё РµСЃС‚СЊ С‚РѕР»СЊРєРѕ SKU Р±РµР· РЅР°Р·РІР°РЅРёСЏ - СЌС‚Рѕ РІР°Р»РёРґРЅРѕ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРіРѕ С‚РѕРІР°СЂР°
            // Р•СЃР»Рё РµСЃС‚СЊ С‚РѕР»СЊРєРѕ РЅР°Р·РІР°РЅРёРµ Р±РµР· SKU - СЌС‚Рѕ РІР°Р»РёРґРЅРѕ РґР»СЏ СЃРѕР·РґР°РЅРёСЏ РЅРѕРІРѕРіРѕ С‚РѕРІР°СЂР°
            // Р•СЃР»Рё РµСЃС‚СЊ Рё SKU, Рё РЅР°Р·РІР°РЅРёРµ - СЌС‚Рѕ РІР°Р»РёРґРЅРѕ РґР»СЏ СЃРѕР·РґР°РЅРёСЏ РёР»Рё РѕР±РЅРѕРІР»РµРЅРёСЏ

            // РќРѕСЂРјР°Р»РёР·СѓРµРј С‚РѕР»СЊРєРѕ С‚Рµ РїРѕР»СЏ, РєРѕС‚РѕСЂС‹Рµ Р±С‹Р»Рё РІ Р·Р°РїСЂРѕСЃРµ (РїСЂРёС€Р»Рё РёР· РјР°РїРїРёРЅРіР° РєРѕР»РѕРЅРѕРє).
            // РќРµ РґРѕР±Р°РІР»СЏРµРј sku/name, РµСЃР»Рё РєРѕР»РѕРЅРєР° РЅРµ Р±С‹Р»Р° СЃРѕРїРѕСЃС‚Р°РІР»РµРЅР° вЂ” РёРЅР°С‡Рµ РїСЂРё РѕР±РЅРѕРІР»РµРЅРёРё
            // РјС‹ Р±С‹ РїРµСЂРµР·Р°РїРёСЃС‹РІР°Р»Рё Р·РЅР°С‡РµРЅРёСЏ РІ Р‘Р” В«РїСѓСЃС‚С‹РјРёВ» РёР· С…РѕСЂРѕС€РёС… РґР°РЅРЅС‹С….
            if (array_key_exists('sku', $good)) {
                $good['sku'] = $sku;
            }
            if (array_key_exists('name', $good)) {
                $good['name'] = $name;
            }

            // Р”РѕР±Р°РІР»СЏРµРј supplier_name РёР· Р·Р°РїСЂРѕСЃР°, РµСЃР»Рё СѓРєР°Р·Р°РЅ
            $supplierName = $request->input('supplier_name', null);
            if ($supplierName !== null && $supplierName !== '') {
                $good['supplier_name'] = trim($supplierName);
            }

            $goods[] = $good;

        }

        // Р›РѕРіРёСЂСѓРµРј РїСЂРѕРїСѓС‰РµРЅРЅС‹Рµ СЃС‚СЂРѕРєРё
        if (!empty($skippedRows)) {
            $this->importLogService->logSkippedBatch($skippedRows);
        }

        // Р’Р°Р»РёРґРёСЂСѓРµРј С‚РѕР»СЊРєРѕ РЅРµРїСѓСЃС‚С‹Рµ С‚РѕРІР°СЂС‹
        $validator = Validator::make([
            'goods' => $goods,
            'duplicate_action' => $request->input('duplicate_action'),
            'auto_create_categories' => $request->input('auto_create_categories'),
            'auto_create_brands' => $request->input('auto_create_brands'),
            'process_categories_and_brands' => $request->input('process_categories_and_brands'),
        ], [
            'goods' => 'required|array',
            'goods.*.sku' => 'nullable|string|max:255',
            'goods.*.name' => 'nullable|string|min:1', // name С‚РµРїРµСЂСЊ РЅРµРѕР±СЏР·Р°С‚РµР»РµРЅ, РµСЃР»Рё РµСЃС‚СЊ SKU
            'duplicate_action' => 'required|in:skip,update',
            'auto_create_categories' => 'boolean',
            'auto_create_brands' => 'boolean',
            'process_categories_and_brands' => 'boolean',
        ]);

        // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё С‚РѕРІР°СЂС‹ РґР»СЏ РѕР±СЂР°Р±РѕС‚РєРё РїРѕСЃР»Рµ С„РёР»СЊС‚СЂР°С†РёРё
        if (empty($goods)) {
            $skippedCount = count($skippedRows);

            return response()->json([
                'success' => true,
                'message' => "РќРµС‚ С‚РѕРІР°СЂРѕРІ РґР»СЏ РёРјРїРѕСЂС‚Р° (РІСЃРµ {$skippedCount} СЃС‚СЂРѕРє РїСѓСЃС‚С‹Рµ РёР»Рё РЅРµРїРѕР»РЅС‹Рµ)",
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
            // Р›РѕРіРёСЂСѓРµРј РѕС€РёР±РєРё РІР°Р»РёРґР°С†РёРё СЃ РґРµС‚Р°Р»СЊРЅРѕР№ РёРЅС„РѕСЂРјР°С†РёРµР№
            $errors = $validator->errors();
            $errorMessages = [];
            foreach ($errors->all() as $error) {
                $errorMessages[] = $error;
            }

            $this->importLogService->logGeneralError('РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё: ' . implode('; ', $errorMessages));

            // Р›РѕРіРёСЂСѓРµРј РѕС€РёР±РєРё РІР°Р»РёРґР°С†РёРё С„Р°Р№Р»РѕРІ РѕС‚РґРµР»СЊРЅРѕ
            foreach ($errorMessages as $error) {
                if (strpos($error, 'goods') !== false || strpos($error, 'file') !== false || strpos($error, 'required') !== false) {
                    $this->importLogService->logFileLoadingError($error);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
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

        // РџСЂРµРѕР±СЂР°Р·СѓРµРј immutable_fields РІ РјР°СЃСЃРёРІ, РµСЃР»Рё РїСЂРёС€Р»Рѕ РєР°Рє СЃС‚СЂРѕРєР°
        if (is_string($immutableFields)) {
            $immutableFields = json_decode($immutableFields, true) ?? [];
        }
        if (!is_array($immutableFields)) {
            $immutableFields = [];
        }

        // РџСЂРµРѕР±СЂР°Р·СѓРµРј РІ boolean, РµСЃР»Рё РїСЂРёС€Р»Рѕ РєР°Рє СЃС‚СЂРѕРєР°
        if (is_string($useDefaultCategory)) {
            $useDefaultCategory = in_array(strtolower($useDefaultCategory), ['true', '1', 'yes', 'on']);
        }
        $useDefaultCategory = (bool) $useDefaultCategory;

        $skipImagesOnUpdateIfExists = $request->input('skip_images_if_exists_on_update', false);
        if (is_string($skipImagesOnUpdateIfExists)) {
            $skipImagesOnUpdateIfExists = in_array(strtolower($skipImagesOnUpdateIfExists), ['true', '1', 'yes', 'on']);
        }
        $skipImagesOnUpdateIfExists = (bool) $skipImagesOnUpdateIfExists;

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј defaultCategory: РµСЃР»Рё СЌС‚Рѕ СЃС‚СЂРѕРєР° (РЅР°Р·РІР°РЅРёРµ), РЅР°С…РѕРґРёРј РёР»Рё СЃРѕР·РґР°РµРј РєР°С‚РµРіРѕСЂРёСЋ
        // Р•СЃР»Рё СЌС‚Рѕ С‡РёСЃР»Рѕ (ID), РёСЃРїРѕР»СЊР·СѓРµРј РєР°Рє РµСЃС‚СЊ
        if ($defaultCategory !== null && is_string($defaultCategory) && trim($defaultCategory) !== '') {
            $categoryName = trim($defaultCategory);
            // РС‰РµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ РЅР°Р·РІР°РЅРёСЋ
            $foundCategory = \App\Models\ShopCategory::where('name', $categoryName)->first();

            if ($foundCategory) {
                $defaultCategory = $foundCategory->id;
            } else {
                // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ РЅРµ РЅР°Р№РґРµРЅР°, СЃРѕР·РґР°РµРј РµС‘
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
            // Р•СЃР»Рё СЌС‚Рѕ С‡РёСЃР»Рѕ, РёСЃРїРѕР»СЊР·СѓРµРј РєР°Рє ID
            $defaultCategory = (int) $defaultCategory;
        } else {
            // Р•СЃР»Рё РїСѓСЃС‚РѕРµ РёР»Рё null, СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј null
            $defaultCategory = null;
        }

        // РџРѕР»СѓС‡Р°РµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ Р±Р°С‚С‡Рµ
        $batchNumber = $request->input('batch_number', 1);
        $totalBatches = $request->input('total_batches', 1);

        // Р•СЃР»Рё СѓРєР°Р·Р°РЅ РїРѕСЃС‚Р°РІС‰РёРє Рё СЌС‚Рѕ РїРµСЂРІС‹Р№ Р±Р°С‚С‡, РѕР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё Сѓ РІСЃРµС… С‚РѕРІР°СЂРѕРІ СЌС‚РѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°
        if ($isFirstBatch && $supplierName !== null && $supplierName !== '') {
            $this->resetSupplierStockByName(trim($supplierName));
        }

        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => count($skippedRows), // Р’РєР»СЋС‡Р°РµРј РїСЂРѕРїСѓС‰РµРЅРЅС‹Рµ СЃС‚СЂРѕРєРё
            'failed' => 0,
            'errors' => [],
            'goodIds' => [],
            'variationIds' => [], // ID РІР°СЂРёР°С†РёР№ РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
            'newCategories' => [],
            'newBrands' => [],
            'imagesDownloaded' => 0, // РљРѕР»РёС‡РµСЃС‚РІРѕ Р·Р°РіСЂСѓР¶РµРЅРЅС‹С… РёР·РѕР±СЂР°Р¶РµРЅРёР№
            'imagesFailed' => 0, // РљРѕР»РёС‡РµСЃС‚РІРѕ РЅРµСѓРґР°С‡РЅС‹С… Р·Р°РіСЂСѓР·РѕРє РёР·РѕР±СЂР°Р¶РµРЅРёР№
        ];

        // РџР°РєРµС‚РЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° РєР°С‚РµРіРѕСЂРёР№ Рё Р±СЂРµРЅРґРѕРІ
        if ($processCategoriesAndBrands && ($autoCreateCategories || $autoCreateBrands)) {
            $this->processCategoriesAndBrandsBatch($goods, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory);

            // РЎРѕР±РёСЂР°РµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ РЅРѕРІС‹С… РєР°С‚РµРіРѕСЂРёСЏС… Рё Р±СЂРµРЅРґР°С…
            $this->collectNewCategoriesAndBrands($goods, $results);
        }

        DB::beginTransaction();

        try {
            // Р“СЂСѓРїРїРёСЂСѓРµРј С‚РѕРІР°СЂС‹ РґР»СЏ РїР°РєРµС‚РЅРѕРіРѕ Р»РѕРіРёСЂРѕРІР°РЅРёСЏ
            $loadItems = [];
            $updateItems = [];
            $skipItems = [];
            $errorItems = [];

            foreach ($goods as $index => $goodData) {
                $count = $index + 1;
                // Р”Р»СЏ РїРѕРёСЃРєР°: С‚РѕР»СЊРєРѕ Р·РЅР°С‡РµРЅРёСЏ РёР· РјР°РїРїРёРЅРіР°. Р•СЃР»Рё РєРѕР»РѕРЅРєР° РЅРµ СЃРѕРїРѕСЃС‚Р°РІР»РµРЅР° вЂ” РїСѓСЃС‚Р°СЏ СЃС‚СЂРѕРєР°
                $sku = isset($goodData['sku']) ? trim((string) $goodData['sku']) : '';
                $name = isset($goodData['name']) ? $this->normalizeText((string) $goodData['name']) : '';

                try {
                    // РџСѓСЃС‚С‹Рµ СЃС‚СЂРѕРєРё СѓР¶Рµ РѕС‚С„РёР»СЊС‚СЂРѕРІР°РЅС‹ РЅР° СЌС‚Р°РїРµ РІР°Р»РёРґР°С†РёРё
                    // Р—РґРµСЃСЊ РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј С‚РѕР»СЊРєРѕ РІР°Р»РёРґРЅС‹Рµ С‚РѕРІР°СЂС‹

                    // РћРїСЂРµРґРµР»СЏРµРј РїРѕР»Рµ РґР»СЏ РїРѕРёСЃРєР° СЃРѕРІРїР°РґРµРЅРёР№
                    $duplicateFields = $request->input('duplicate_fields', ['sku']);
                    $searchByNameInVariations = $request->input('search_by_name_in_variations', false);
                    // РџСЂРµРѕР±СЂР°Р·СѓРµРј РІ boolean, РµСЃР»Рё РїСЂРёС€Р»Рѕ РєР°Рє СЃС‚СЂРѕРєР°
                    if (is_string($searchByNameInVariations)) {
                        $searchByNameInVariations = in_array(strtolower($searchByNameInVariations), ['true', '1', 'yes', 'on']);
                    }
                    $searchByNameInVariations = (bool) $searchByNameInVariations;

                    // РџРѕР»Рµ РґР»СЏ РїРѕРёСЃРєР° РІ РІР°СЂРёР°С†РёСЏС… ('name' РёР»Рё 'sku')
                    $searchByFieldInVariations = $request->input('search_by_field_in_variations', 'name');
                    if (!in_array($searchByFieldInVariations, ['name', 'sku'])) {
                        $searchByFieldInVariations = 'name'; // Р·РЅР°С‡РµРЅРёРµ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                    }

                    // РџРѕР»СѓС‡Р°РµРј РїРѕСЃС‚Р°РІС‰РёРєР°: РёР· РґР°РЅРЅС‹С… С‚РѕРІР°СЂР° (РґРѕР±Р°РІР»РµРЅРѕ РїСЂРё РїСЂРµРїСЂРѕС†РµСЃСЃРёРЅРіРµ РёР· request) РёР»Рё РёР· request
                    $supplierName = null;
                    $supplierNameRaw = $goodData['supplier_name'] ?? $request->input('supplier_name', null);
                    if ($supplierNameRaw !== null && $supplierNameRaw !== '' && $supplierNameRaw !== '__reset_all__') {
                        $trimmed = trim((string) $supplierNameRaw);
                        if ($trimmed !== '') {
                            $supplierName = $trimmed;
                        }
                    }

                    $existingGood = null;
                    $existingVariation = null;
                    $foundByFields = []; // РњР°СЃСЃРёРІ РґР»СЏ С…СЂР°РЅРµРЅРёСЏ РёРЅС„РѕСЂРјР°С†РёРё Рѕ С‚РѕРј, РїРѕ РєР°РєРёРј РїРѕР»СЏРј РЅР°Р№РґРµРЅ С‚РѕРІР°СЂ
                    $foundMainGoodByName = false; // Р”Р»СЏ В«РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС…В» РїРѕ SKU: true, РµСЃР»Рё РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ РЅР°Р№РґРµРЅ РїРѕ РЅР°Р·РІР°РЅРёСЋ (С‚РѕРіРґР° РґРѕР±Р°РІР»СЏРµРј РІР°СЂРёР°С†РёСЋ)

                    // РРЅРёС†РёР°Р»РёР·РёСЂСѓРµРј РїРµСЂРµРјРµРЅРЅСѓСЋ hasVariation
                    $hasVariation = false;

                    // Р•СЃР»Рё РµСЃС‚СЊ РїРѕР»Рµ variation, РІСЃРµРіРґР° РёС‰РµРј С‚РѕРІР°СЂ РїРѕ РёРјРµРЅРё
                    $hasVariation = isset($goodData['variation']) && is_array($goodData['variation']) &&
                        isset($goodData['variation']['attributes']) &&
                        is_array($goodData['variation']['attributes']) &&
                        count($goodData['variation']['attributes']) > 0;

                    // РЎРЅР°С‡Р°Р»Р° РїСЂРѕРІРµСЂСЏРµРј СЂРµР¶РёРј РїРѕРёСЃРєР° РІ РІР°СЂРёР°С†РёСЏС… (РµСЃР»Рё РІРєР»СЋС‡РµРЅ)
                    // РџРѕРёСЃРє СЂР°Р±РѕС‚Р°РµС‚ Рё Р±РµР· РїРѕСЃС‚Р°РІС‰РёРєР°: РїСЂРё РѕС‚СЃСѓС‚СЃС‚РІРёРё РїРѕСЃС‚Р°РІС‰РёРєР° РёС‰РµРј РїРѕ РІСЃРµРј РІР°СЂРёР°С†РёСЏРј
                    if ($searchByNameInVariations && !$hasVariation) {
                        // РџРѕРёСЃРє РІР°СЂРёР°С†РёР№ РїРѕ РёРјРµРЅРё РѕС‚РєР»СЋС‡Р°РµРј РґР»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё,
                        // РїРѕС‚РѕРјСѓ С‡С‚Рѕ РјРѕР¶РµС‚ Р±С‹С‚СЊ РЅРµСЃРєРѕР»СЊРєРѕ РІР°СЂРёР°С†РёР№ СЃ РѕРґРёРЅР°РєРѕРІС‹Рј SKU
                        // РћРїСЂРµРґРµР»СЏРµРј Р·РЅР°С‡РµРЅРёРµ РґР»СЏ РїРѕРёСЃРєР° РІ РІР°СЂРёР°С†РёСЏС…
                        $searchValue = ($searchByFieldInVariations === 'sku') ? $sku : $name;

                        if (!empty($searchValue)) {
                            // РС‰РµРј РІР°СЂРёР°С†РёСЋ РїРѕ РІС‹Р±СЂР°РЅРЅРѕРјСѓ РїРѕР»СЋ Рё Р°С‚СЂРёР±СѓС‚Р°Рј
                            // РЎРЅР°С‡Р°Р»Р° РїСЂРѕР±СѓРµРј РЅР°Р№С‚Рё СЃ СѓС‡РµС‚РѕРј РїРѕСЃС‚Р°РІС‰РёРєР°
                            $existingVariation = null;
                            if ($supplierName) {
                                $existingVariation = $this->findVariationByNameAndAttributes(
                                    $searchByFieldInVariations,
                                    $searchValue,
                                    $goodData['variation']['attributes'] ?? [],
                                    $supplierName
                                );
                            }

                            // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё СЃ РїРѕСЃС‚Р°РІС‰РёРєРѕРј вЂ” РїСЂРѕР±СѓРµРј Р±РµР· РЅРµРіРѕ
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

                    // РџСЂРё РїРѕРёСЃРєРµ РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU: РёС‰РµРј РІР°СЂРёР°С†РёСЋ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ С‚Р°РєР¶Рµ РґР»СЏ СЃС‚СЂРѕРє РЎ РІР°СЂРёР°С†РёРµР№ (hasVariation),
                    // С‚.Рє. РІ Р±Р»РѕРєРµ РІС‹С€Рµ РїРѕРёСЃРє РІС‹РїРѕР»РЅСЏРµС‚СЃСЏ С‚РѕР»СЊРєРѕ РїСЂРё !hasVariation. Р•СЃР»Рё РІР°СЂРёР°С†РёСЏ РЅР°Р№РґРµРЅР° РїРѕ SKU вЂ”
                    // РїСЂРёРѕСЂРёС‚РµС‚: С‚РѕР»СЊРєРѕ РѕР±РЅРѕРІРёС‚СЊ РІР°СЂРёР°С†РёСЋ, РёРјСЏ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР° РЅРµ Р·Р°С‚СЂР°РіРёРІР°С‚СЊ.
                    if (!$existingVariation && $searchByNameInVariations && $searchByFieldInVariations === 'sku' && !empty($sku) && $hasVariation) {
                        // РЎРЅР°С‡Р°Р»Р° РїСЂРѕР±СѓРµРј РЅР°Р№С‚Рё СЃ СѓС‡РµС‚РѕРј РїРѕСЃС‚Р°РІС‰РёРєР°
                        $existingVariation = null;
                        if ($supplierName) {
                            $existingVariation = $this->findVariationByNameAndAttributes(
                                'sku',
                                $sku,
                                $goodData['variation']['attributes'] ?? [],
                                $supplierName
                            );
                        }

                        // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё СЃ РїРѕСЃС‚Р°РІС‰РёРєРѕРј вЂ” РїСЂРѕР±СѓРµРј Р±РµР· РЅРµРіРѕ
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

                    // Р•СЃР»Рё РІР°СЂРёР°С†РёСЏ РЅРµ РЅР°Р№РґРµРЅР° (РёР»Рё РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РѕС‚РєР»СЋС‡РµРЅ), РёС‰РµРј С‚РѕРІР°СЂ РѕР±С‹С‡РЅС‹Рј СЃРїРѕСЃРѕР±РѕРј
                    if (!$existingVariation) {
                        // РЎРїРµС†РёР°Р»СЊРЅС‹Р№ СЂРµР¶РёРј: В«РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС…В» РїРѕ Р°СЂС‚РёРєСѓР»Сѓ (SKU). РџРѕСЂСЏРґРѕРє: 1) РїРѕ РЅР°Р·РІР°РЅРёСЋ в†’ РґРѕР±Р°РІРёС‚СЊ РІР°СЂРёР°С†РёСЋ, 2) РїРѕ Р°СЂС‚РёРєСѓР»Сѓ РІ РѕСЃРЅРѕРІРЅС‹С… в†’ РѕР±РЅРѕРІРёС‚СЊ; РїРѕСЃС‚Р°РІС‰РёРє СѓС‡РёС‚С‹РІР°РµС‚СЃСЏ.
                        // РџСЂРёСЃРІР°РёРІР°РµРј existingGood С‚РѕР»СЊРєРѕ РїСЂРё СѓСЃРїРµС€РЅРѕРј РЅР°С…РѕР¶РґРµРЅРёРё (РЅРµ РїРµСЂРµР·Р°РїРёСЃС‹РІР°РµРј null РїСЂРё РЅРµСѓРґР°С‡Рµ).
                        if ($searchByNameInVariations && $searchByFieldInVariations === 'sku') {
                            // 1) РџРѕ РЅР°Р·РІР°РЅРёСЋ РІ РѕСЃРЅРѕРІРЅС‹С… С‚РѕРІР°СЂР°С… (СЃ СѓС‡С‘С‚РѕРј РїРѕСЃС‚Р°РІС‰РёРєР°)
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
                                    $foundByFields[] = $supplierName ? "РЅР°Р·РІР°РЅРёРµ: '{$name}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РґРѕР±Р°РІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё, РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})" : "РЅР°Р·РІР°РЅРёРµ: '{$name}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РґРѕР±Р°РІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё)";
                                }
                            }
                            // 2) Р•СЃР»Рё РїРѕ РЅР°Р·РІР°РЅРёСЋ РЅРµ РЅР°С€Р»Рё вЂ” РїРѕ Р°СЂС‚РёРєСѓР»Сѓ РІ РѕСЃРЅРѕРІРЅС‹С… С‚РѕРІР°СЂР°С… (СЃ СѓС‡С‘С‚РѕРј РїРѕСЃС‚Р°РІС‰РёРєР°), РЅР°Р№РґРµРЅРЅРѕРµ вЂ” РѕР±РЅРѕРІРёС‚СЊ
                            if (!$existingGood && !empty($sku)) {
                                if ($supplierName) {
                                    $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РѕР±РЅРѕРІР»РµРЅРёРµ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°, РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})";
                                    } else {
                                        $existingGood = ShopGood::where('sku', $sku)->first();
                                        if ($existingGood) {
                                            $foundByFields[] = "SKU: '{$sku}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РѕР±РЅРѕРІР»РµРЅРёРµ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°, Р±РµР· С„РёР»СЊС‚СЂР° РїРѕСЃС‚Р°РІС‰РёРєР°)";
                                        }
                                    }
                                } else {
                                    $existingGood = ShopGood::where('sku', $sku)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РѕР±РЅРѕРІР»РµРЅРёРµ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°)";
                                    }
                                }
                            }
                        } else {
                            // РћР±С‹С‡РЅС‹Р№ РїРѕРёСЃРє С‚РѕРІР°СЂР° РїРѕ duplicateFields
                            if (!$existingGood) {
                                foreach ($duplicateFields as $field) {
                                    if ($field === 'sku') {
                                        if (!empty($sku)) {
                                            // Р•СЃР»Рё СѓРєР°Р·Р°РЅ РїРѕСЃС‚Р°РІС‰РёРє, СЃРЅР°С‡Р°Р»Р° РёС‰РµРј С‚РѕРІР°СЂ СЌС‚РѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°
                                            if ($supplierName) {
                                                $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = "SKU: '{$sku}' (РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})";
                                                } else {
                                                    // Р•СЃР»Рё РЅРµ РЅР°Р№РґРµРЅ С‚РѕРІР°СЂ СЌС‚РѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°, РїСЂРѕРІРµСЂСЏРµРј РµСЃС‚СЊ Р»Рё С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј SKU РІРѕРѕР±С‰Рµ
                                                    $existingGoodAnySupplier = ShopGood::where('sku', $sku)->first();
                                                    if ($existingGoodAnySupplier) {
                                                        // РќР°Р№РґРµРЅ С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј SKU, РЅРѕ РґСЂСѓРіРѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°
                                                        // Р­С‚Рѕ СЃРїРµС†РёР°Р»СЊРЅС‹Р№ СЃР»СѓС‡Р°Р№ - РЅСѓР¶РЅРѕ РѕР±РЅРѕРІРёС‚СЊ РѕСЃС‚Р°С‚РєРё Рё РёР·РјРµРЅРёС‚СЊ РїСЂРёРІСЏР·РєСѓ Рє РїРѕСЃС‚Р°РІС‰РёРєСѓ
                                                        $existingGood = $existingGoodAnySupplier;
                                                        $foundByFields[] = "SKU: '{$sku}' (РёР·РјРµРЅРµРЅРёРµ РїРѕСЃС‚Р°РІС‰РёРєР° СЃ '{$existingGood->supplier}' РЅР° '{$supplierName}')";
                                                    }
                                                }
                                            } else {
                                                // РџРѕСЃС‚Р°РІС‰РёРє РЅРµ СѓРєР°Р·Р°РЅ - РѕР±С‹С‡РЅС‹Р№ РїРѕРёСЃРє
                                                $existingGood = ShopGood::where('sku', $sku)->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = "SKU: '{$sku}'";
                                                }
                                            }
                                        } else {
                                            // РџСЂРё РїСѓСЃС‚РѕРј SKU РЅРµ РёС‰РµРј РїРѕ name, РµСЃР»Рё 'name' РЅРµ РІ duplicate_fields
                                            if (in_array('name', $duplicateFields) && !empty($name)) {
                                                $goodQuery = ShopGood::whereNull('sku')->where('name', $name);
                                                if ($supplierName) {
                                                    $goodQuery->where('supplier', $supplierName);
                                                }
                                                $existingGood = $goodQuery->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = $supplierName ? "РїСѓСЃС‚РѕР№ SKU + РЅР°Р·РІР°РЅРёРµ: '{$name}' (РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})" : "РїСѓСЃС‚РѕР№ SKU + РЅР°Р·РІР°РЅРёРµ: '{$name}'";
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
                                                $foundByFields[] = $supplierName ? "РЅР°Р·РІР°РЅРёРµ: '{$name}' (РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})" : "РЅР°Р·РІР°РЅРёРµ: '{$name}'";
                                            }
                                        }
                                    }

                                    if ($existingGood) {
                                        break; // РќР°Р№РґРµРЅ С‚РѕРІР°СЂ, РїСЂРµРєСЂР°С‰Р°РµРј РїРѕРёСЃРє
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

                    // Р”РћРџРћР›РќРРўР•Р›Р¬РќРђРЇ РџР РћР’Р•Р РљРђ: РџСЂРё СЂРµР¶РёРјРµ "РСЃРєР°С‚СЊ РІ РІР°СЂРёР°С†РёСЏС… - РїРѕ РЅР°Р·РІР°РЅРёСЋ",
                    // РµСЃР»Рё С‚РѕРІР°СЂ РЅРµ РЅР°Р№РґРµРЅ РїРѕСЃР»Рµ РІСЃРµС… РїСЂРѕРІРµСЂРѕРє, РїСЂРѕРІРµСЂСЏРµРј РіР»Р°РІРЅС‹Рµ С‚РѕРІР°СЂС‹ РїРѕ SKU
                    if (!$existingGood && $searchByNameInVariations && $searchByFieldInVariations === 'name' && !empty($sku)) {
                        if ($supplierName) {
                            $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                            if ($existingGood) {
                                $foundByFields[] = "SKU: '{$sku}' (РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° РїРѕСЃР»Рµ РїРѕРёСЃРєР° РІ РІР°СЂРёР°С†РёСЏС… РїРѕ РЅР°Р·РІР°РЅРёСЋ, РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})";
                            } else {
                                $existingGood = ShopGood::where('sku', $sku)->first();
                                if ($existingGood) {
                                    $foundByFields[] = "SKU: '{$sku}' (РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° РїРѕСЃР»Рµ РїРѕРёСЃРєР° РІ РІР°СЂРёР°С†РёСЏС… РїРѕ РЅР°Р·РІР°РЅРёСЋ, Р±РµР· С„РёР»СЊС‚СЂР° РїРѕСЃС‚Р°РІС‰РёРєР°)";
                                }
                            }
                        } else {
                            $existingGood = ShopGood::where('sku', $sku)->first();
                            if ($existingGood) {
                                $foundByFields[] = "SKU: '{$sku}' (РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° РїРѕСЃР»Рµ РїРѕРёСЃРєР° РІ РІР°СЂРёР°С†РёСЏС… РїРѕ РЅР°Р·РІР°РЅРёСЋ)";
                            }
                        }
                    }

                    if ($hasVariation && !$existingVariation) {
                        // РџСЂРё РЅР°Р»РёС‡РёРё РІР°СЂРёР°С†РёРё РёС‰РµРј С‚РѕРІР°СЂ Р±РѕР»РµРµ С‚С‰Р°С‚РµР»СЊРЅРѕ (С‚РѕР»СЊРєРѕ РµСЃР»Рё РІР°СЂРёР°С†РёСЏ РµС‰С‘ РЅРµ РЅР°Р№РґРµРЅР° РїРѕ SKU)
                        // Р’Р°Р¶РЅРѕ: РґР»СЏ РІР°СЂРёР°С†РёР№ С‚РѕРІР°СЂ РґРѕР»Р¶РµРЅ СЃСѓС‰РµСЃС‚РІРѕРІР°С‚СЊ, РёРЅР°С‡Рµ Р±СѓРґРµС‚ РѕС€РёР±РєР° РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ
                        // РџСЂРѕРІРµСЂСЏРµРј РІСЃРµ РІРѕР·РјРѕР¶РЅС‹Рµ РІР°СЂРёР°РЅС‚С‹ РїРѕРёСЃРєР°

                        // 1. РЎРЅР°С‡Р°Р»Р° РїРѕ SKU (РµСЃР»Рё СѓРєР°Р·Р°РЅ) - СЃР°РјС‹Р№ РЅР°РґРµР¶РЅС‹Р№ СЃРїРѕСЃРѕР±
                        if (!$existingGood && !empty($sku)) {
                            if ($supplierName) {
                                // Р•СЃР»Рё СѓРєР°Р·Р°РЅ РїРѕСЃС‚Р°РІС‰РёРє, СЃРЅР°С‡Р°Р»Р° РёС‰РµРј С‚РѕРІР°СЂ СЌС‚РѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°
                                $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                if ($existingGood) {
                                    $foundByFields[] = "SKU: '{$sku}' (РґР»СЏ РІР°СЂРёР°С†РёРё, РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})";
                                } else {
                                    // Р•СЃР»Рё РЅРµ РЅР°Р№РґРµРЅ С‚РѕРІР°СЂ СЌС‚РѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°, РёС‰РµРј Р»СЋР±РѕР№ С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј SKU
                                    $existingGood = ShopGood::where('sku', $sku)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (РґР»СЏ РІР°СЂРёР°С†РёРё, СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ С‚РѕРІР°СЂ РґСЂСѓРіРѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°)";
                                    }
                                }
                            } else {
                                $existingGood = ShopGood::where('sku', $sku)->first();
                                if ($existingGood) {
                                    $foundByFields[] = "SKU: '{$sku}' (РґР»СЏ РІР°СЂРёР°С†РёРё)";
                                }
                            }
                        }

                        // 2. Р•СЃР»Рё РЅРµ РЅР°Р№РґРµРЅ РїРѕ SKU, РёС‰РµРј РїРѕ РёРјРµРЅРё вЂ” С‚РѕР»СЊРєРѕ РµСЃР»Рё 'name' РІ duplicate_fields
                        if (!$existingGood && in_array('name', $duplicateFields) && !empty($name)) {
                            $goodQuery = ShopGood::where('name', $this->normalizeTextForSearch($name));
                            if ($supplierName) {
                                $goodQuery->where('supplier', $supplierName);
                            }
                            $existingGood = $goodQuery->first();
                            if ($existingGood) {
                                $foundByFields[] = $supplierName ? "РЅР°Р·РІР°РЅРёРµ: '{$name}' (РґР»СЏ РІР°СЂРёР°С†РёРё, РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})" : "РЅР°Р·РІР°РЅРёРµ: '{$name}' (РґР»СЏ РІР°СЂРёР°С†РёРё)";
                            }
                        }

                        // 3. Р•СЃР»Рё РЅРµ РЅР°Р№РґРµРЅ, РїСЂРѕР±СѓРµРј РїРѕ РёРјРµРЅРё СЃ РїСѓСЃС‚С‹Рј SKU вЂ” С‚РѕР»СЊРєРѕ РµСЃР»Рё 'name' РІ duplicate_fields
                        if (!$existingGood && in_array('name', $duplicateFields) && !empty($name)) {
                            $goodQuery = ShopGood::whereNull('sku')->where('name', $name);
                            if ($supplierName) {
                                $goodQuery->where('supplier', $supplierName);
                            }
                            $existingGood = $goodQuery->first();
                            if ($existingGood) {
                                $foundByFields[] = $supplierName ? "РїСѓСЃС‚РѕР№ SKU + РЅР°Р·РІР°РЅРёРµ: '{$name}' (РґР»СЏ РІР°СЂРёР°С†РёРё, РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})" : "РїСѓСЃС‚РѕР№ SKU + РЅР°Р·РІР°РЅРёРµ: '{$name}' (РґР»СЏ РІР°СЂРёР°С†РёРё)";
                            }
                        }

                        // 4. Р•СЃР»Рё РІСЃРµ РµС‰Рµ РЅРµ РЅР°Р№РґРµРЅ, РїСЂРѕР±СѓРµРј РїРѕРёСЃРє РїРѕ РёРјРµРЅРё Р±РµР· СѓС‡РµС‚Р° СЂРµРіРёСЃС‚СЂР° вЂ” С‚РѕР»СЊРєРѕ РµСЃР»Рё 'name' РІ duplicate_fields
                        if (!$existingGood && in_array('name', $duplicateFields) && !empty($name)) {
                            // РќРѕСЂРјР°Р»РёР·СѓРµРј РёРјСЏ РґР»СЏ РїРѕРёСЃРєР° (СѓР±РёСЂР°РµРј Р»РёС€РЅРёРµ РїСЂРѕР±РµР»С‹, РїСЂРёРІРѕРґРёРј Рє РЅРёР¶РЅРµРјСѓ СЂРµРіРёСЃС‚СЂСѓ)
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
                        // РС‰РµРј С‚РѕРІР°СЂ РїРѕ СѓРєР°Р·Р°РЅРЅС‹Рј РїРѕР»СЏРј (РѕР±С‹С‡РЅР°СЏ Р»РѕРіРёРєР°)
                        // РЎРїРµС†РёР°Р»СЊРЅС‹Р№ СЂРµР¶РёРј: В«РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС…В» РїРѕ Р°СЂС‚РёРєСѓР»Сѓ (SKU) вЂ” 1) РїРѕ РЅР°Р·РІР°РЅРёСЋ в†’ РґРѕР±Р°РІРёС‚СЊ РІР°СЂРёР°С†РёСЋ, 2) РїРѕ Р°СЂС‚РёРєСѓР»Сѓ РІ РѕСЃРЅРѕРІРЅС‹С… в†’ РѕР±РЅРѕРІРёС‚СЊ; РїРѕСЃС‚Р°РІС‰РёРє СѓС‡РёС‚С‹РІР°РµС‚СЃСЏ.
                        // РљСЂРёС‚РёС‡РЅРѕ: РїСЂРёСЃРІР°РёРІР°РµРј existingGood С‚РѕР»СЊРєРѕ РїСЂРё СѓСЃРїРµС€РЅРѕРј РЅР°С…РѕР¶РґРµРЅРёРё, РёРЅР°С‡Рµ Р·Р°С‚РёСЂР°РµС‚СЃСЏ СЂРµР·СѓР»СЊС‚Р°С‚ РёР· Р±Р»РѕРєР° 407-465 (РЅР°Р№РґРµРЅРЅС‹Р№ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ РІ РѕСЃРЅРѕРІРЅС‹С…).
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
                                        $foundByFields[] = $supplierName ? "РЅР°Р·РІР°РЅРёРµ: '{$name}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РґРѕР±Р°РІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё, РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})" : "РЅР°Р·РІР°РЅРёРµ: '{$name}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РґРѕР±Р°РІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё)";
                                    }
                                }
                                if (!$existingGood && !empty($sku)) {
                                    if ($supplierName) {
                                        $foundBySku = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                        if ($foundBySku) {
                                            $existingGood = $foundBySku;
                                            $foundByFields[] = "SKU: '{$sku}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РѕР±РЅРѕРІР»РµРЅРёРµ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°, РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})";
                                        } else {
                                            $foundBySku = ShopGood::where('sku', $sku)->first();
                                            if ($foundBySku) {
                                                $existingGood = $foundBySku;
                                                $foundByFields[] = "SKU: '{$sku}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РѕР±РЅРѕРІР»РµРЅРёРµ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°, Р±РµР· С„РёР»СЊС‚СЂР° РїРѕСЃС‚Р°РІС‰РёРєР°)";
                                            }
                                        }
                                    } else {
                                        $foundBySku = ShopGood::where('sku', $sku)->first();
                                        if ($foundBySku) {
                                            $existingGood = $foundBySku;
                                            $foundByFields[] = "SKU: '{$sku}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU, РѕР±РЅРѕРІР»РµРЅРёРµ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°)";
                                        }
                                    }
                                }
                            } else {
                                foreach ($duplicateFields as $field) {
                                    if ($field === 'sku') {
                                        if (!empty($sku)) {
                                            // Р•СЃР»Рё СѓРєР°Р·Р°РЅ РїРѕСЃС‚Р°РІС‰РёРє, СЃРЅР°С‡Р°Р»Р° РёС‰РµРј С‚РѕРІР°СЂ СЌС‚РѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°
                                            if ($supplierName) {
                                                $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = "SKU: '{$sku}' (РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})";
                                                } else {
                                                    // Р•СЃР»Рё РЅРµ РЅР°Р№РґРµРЅ С‚РѕРІР°СЂ СЌС‚РѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°, РїСЂРѕРІРµСЂСЏРµРј РµСЃС‚СЊ Р»Рё С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј SKU РІРѕРѕР±С‰Рµ
                                                    $existingGoodAnySupplier = ShopGood::where('sku', $sku)->first();
                                                    if ($existingGoodAnySupplier) {
                                                        // РќР°Р№РґРµРЅ С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј SKU, РЅРѕ РґСЂСѓРіРѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°
                                                        // Р­С‚Рѕ СЃРїРµС†РёР°Р»СЊРЅС‹Р№ СЃР»СѓС‡Р°Р№ - РЅСѓР¶РЅРѕ РѕР±РЅРѕРІРёС‚СЊ РѕСЃС‚Р°С‚РєРё Рё РёР·РјРµРЅРёС‚СЊ РїСЂРёРІСЏР·РєСѓ Рє РїРѕСЃС‚Р°РІС‰РёРєСѓ
                                                        $existingGood = $existingGoodAnySupplier;
                                                        $foundByFields[] = "SKU: '{$sku}' (РёР·РјРµРЅРµРЅРёРµ РїРѕСЃС‚Р°РІС‰РёРєР° СЃ '{$existingGood->supplier}' РЅР° '{$supplierName}')";
                                                    }
                                                }
                                            } else {
                                                // РџРѕСЃС‚Р°РІС‰РёРє РЅРµ СѓРєР°Р·Р°РЅ - РѕР±С‹С‡РЅС‹Р№ РїРѕРёСЃРє
                                                $existingGood = ShopGood::where('sku', $sku)->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = "SKU: '{$sku}'";
                                                }
                                            }
                                        } else {
                                            // РџСЂРё РїСѓСЃС‚РѕРј SKU РЅРµ РёС‰РµРј РїРѕ name, РµСЃР»Рё 'name' РЅРµ РІ duplicate_fields
                                            if (in_array('name', $duplicateFields) && !empty($name)) {
                                                $goodQuery = ShopGood::whereNull('sku')->where('name', $name);
                                                if ($supplierName) {
                                                    $goodQuery->where('supplier', $supplierName);
                                                }
                                                $existingGood = $goodQuery->first();
                                                if ($existingGood) {
                                                    $foundByFields[] = $supplierName ? "РїСѓСЃС‚РѕР№ SKU + РЅР°Р·РІР°РЅРёРµ: '{$name}' (РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})" : "РїСѓСЃС‚РѕР№ SKU + РЅР°Р·РІР°РЅРёРµ: '{$name}'";
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
                                                $foundByFields[] = $supplierName ? "РЅР°Р·РІР°РЅРёРµ: '{$name}' (РїРѕСЃС‚Р°РІС‰РёРє: {$supplierName})" : "РЅР°Р·РІР°РЅРёРµ: '{$name}'";
                                            }
                                        }
                                    }

                                    if ($existingGood) {
                                        break; // РќР°Р№РґРµРЅ С‚РѕРІР°СЂ, РїСЂРµРєСЂР°С‰Р°РµРј РїРѕРёСЃРє
                                    }
                                }
                            }
                        }
                    }

                    // Р•СЃР»Рё РЅР°Р№РґРµРЅР° РІР°СЂРёР°С†РёСЏ РїРѕ РёРјРµРЅРё (РїСЂРё РІРєР»СЋС‡РµРЅРЅРѕРј РїРѕРёСЃРєРµ РїРѕ РёРјРµРЅР°Рј РІ РІР°СЂРёР°С†РёСЏС…)
                    if ($existingVariation && $searchByNameInVariations) {
                        // РЈР±РµР¶РґР°РµРјСЃСЏ, С‡С‚Рѕ С‚РѕРІР°СЂ РЅР°Р№РґРµРЅ (РµСЃР»Рё РІР°СЂРёР°С†РёСЏ РЅР°Р№РґРµРЅР°, С‚РѕРІР°СЂ РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ)
                        if (!$existingGood && $existingVariation->good) {
                            $existingGood = $existingVariation->good;
                        }

                        // Р•СЃР»Рё С‚РѕРІР°СЂ РІСЃРµ РµС‰Рµ РЅРµ РЅР°Р№РґРµРЅ, РїСЂРѕРїСѓСЃРєР°РµРј СЌС‚Сѓ СЃС‚СЂРѕРєСѓ
                        if (!$existingGood) {
                            $results['skipped']++;
                            $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                            $reason = 'Р’Р°СЂРёР°С†РёСЏ РЅР°Р№РґРµРЅР° РїРѕ ' . ($searchByFieldInVariations === 'sku' ? 'Р°СЂС‚РёРєСѓР»Сѓ' : 'РёРјРµРЅРё') . ' "' . ($searchValue ?? $sku) . '" (РїРѕР»Рµ: ' . $searchByFieldInVariations . '), РЅРѕ СЃРІСЏР·Р°РЅРЅС‹Р№ С‚РѕРІР°СЂ РЅРµ РЅР°Р№РґРµРЅ РІ Р±Р°Р·Рµ РґР°РЅРЅС‹С…. Р’Р°СЂРёР°С†РёСЏ ID: ' . $existingVariation->id;
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];

                            continue;
                        }

                        // РљР РРўРР§РќРћ: Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё, РЅР°Р№РґРµРЅРЅС‹С… РїРѕ РёРјРµРЅРё, РѕС‚РґРµР»СЊРЅРѕ РѕР±СЂРµР·Р°РµРј Рё РѕР±РЅРѕРІР»СЏРµРј РЅР°Р·РІР°РЅРёРµ С‚РѕРІР°СЂР°.
                        // РџСЂРё РїРѕРёСЃРєРµ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ (SKU) РёРјСЏ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР° РЅРµ Р·Р°С‚СЂР°РіРёРІР°РµРј.
                        if ($searchByFieldInVariations !== 'sku') {
                            $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
                            $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);
                        }

                        // РћР±РЅРѕРІР»СЏРµРј РІР°СЂРёР°С†РёСЋ (РЅР°Р№РґРµРЅРЅСѓСЋ РїРѕ РёРјРµРЅРё РёР»Рё РїРѕ Р°СЂС‚РёРєСѓР»Сѓ)
                        $variationId = $this->updateVariationFromGoodData($existingVariation, $goodData, $searchByNameInVariations);

                        // Р›РѕРіРёСЂСѓРµРј РґРµР№СЃС‚РІРёРµ СЃ РІР°СЂРёР°С†РёРµР№
                        $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                        $variationAttributes = [];
                        // РџРѕР»СѓС‡Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё РґР»СЏ Р»РѕРіРёСЂРѕРІР°РЅРёСЏ
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

                        $details = 'РќР°Р№РґРµРЅР° РїРѕ ' . ($searchByFieldInVariations === 'sku' ? 'Р°СЂС‚РёРєСѓР»Сѓ' : 'РёРјРµРЅРё') . ' + СЃРѕРІРїР°РґРµРЅРёСЋ Р°С‚СЂРёР±СѓС‚РѕРІ: ' . ($searchByFieldInVariations === 'sku' ? ($searchValue ?? $sku) : $name);
                        if ($sheet !== 'РЅРµРёР·РІРµСЃС‚РЅРѕ') {
                            $details .= ", Р›РёСЃС‚: {$sheet}, РЎС‚СЂРѕРєР°: {$count}";
                        }

                        $this->importLogService->logVariation(
                            $searchByFieldInVariations === 'sku' ? 'РћР‘РќРћР’Р›Р•РќРђ Р’РђР РРђР¦РРЇ РџРћ РђР РўРРљРЈР›РЈ + РђРўР РР‘РЈРўРђРњ' : 'РћР‘РќРћР’Р›Р•РќРђ Р’РђР РРђР¦РРЇ РџРћ РРњР•РќР + РђРўР РР‘РЈРўРђРњ',
                            $existingGood->name ?? $name,
                            $existingGood->sku ?? 'РЅРµС‚ SKU',
                            $variationAttributes,
                            $existingVariation->id,
                            $details
                        );

                        // РћР±СЂРµР·РєР° РЅР°Р·РІР°РЅРёСЏ РґР»СЏ С‚РѕРІР°СЂРѕРІ Р±РµР· РІР°СЂРёР°С†РёР№ Р±СѓРґРµС‚ РѕР±СЂР°Р±РѕС‚Р°РЅР° РІ updateGood

                        // РћР±РЅРѕРІР»СЏРµРј С‚РѕРІР°СЂ, РµСЃР»Рё РЅСѓР¶РЅРѕ
                        if ($duplicateAction === 'update') {
                            // РљР РРўРР§РќРћ: Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё РѕС‚РґРµР»СЊРЅРѕ РѕР±СЂРµР·Р°РµРј Рё РѕР±РЅРѕРІР»СЏРµРј РЅР°Р·РІР°РЅРёРµ.
                            // РџСЂРё РїРѕРёСЃРєРµ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ (SKU) РёРјСЏ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР° РЅРµ Р·Р°С‚СЂР°РіРёРІР°РµРј.
                            if ($hasVariation && $searchByFieldInVariations !== 'sku') {
                                $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
                                $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);
                            }

                            // РџСЂРѕРІРµСЂСЏРµРј РїРѕР»Рµ РґР»СЏ СѓРґР°Р»РµРЅРёСЏ РґСѓР±Р»РёРєР°С‚РѕРІ
                            $duplicateCheckField = $request->input('duplicate_check_field');

                            // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ Р·РЅР°С‡РµРЅРёСЏ, РєРѕС‚РѕСЂРѕРµ Р±СѓРґРµС‚ СѓСЃС‚Р°РЅРѕРІР»РµРЅРѕ С‚РѕРІР°СЂСѓ
                            if (!empty($duplicateCheckField) && isset($goodData[$duplicateCheckField])) {
                                $fieldValue = $goodData[$duplicateCheckField];

                                // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё СѓР¶Рµ С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј Р·РЅР°С‡РµРЅРёРµРј РїРѕР»СЏ (РІРєР»СЋС‡Р°СЏ С‚РµРєСѓС‰РёР№ С‚РѕРІР°СЂ)
                                $existingGoodWithField = ShopGood::where($duplicateCheckField, $fieldValue)->first();

                                // Р•СЃР»Рё РЅР°Р№РґРµРЅ С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј Р·РЅР°С‡РµРЅРёРµРј РїРѕР»СЏ, Рё СЌС‚Рѕ РќР• С‚РµРєСѓС‰РёР№ С‚РѕРІР°СЂ
                                if ($existingGoodWithField && $existingGoodWithField->id !== $existingGood->id) {
                                    // РЈРґР°Р»СЏРµРј РґСѓР±Р»РёРєР°С‚ С‚РѕРІР°СЂР°
                                    try {
                                        // РЈРґР°Р»СЏРµРј СЃРІСЏР·Р°РЅРЅС‹Рµ РґР°РЅРЅС‹Рµ
                                        $existingGoodWithField->categories()->detach();
                                        $existingGoodWithField->brands()->detach();
                                        $existingGoodWithField->tags()->detach();
                                        $existingGoodWithField->properties()->detach();
                                        $existingGoodWithField->images()->delete();
                                        $existingGoodWithField->variations()->delete();

                                        // РЈРґР°Р»СЏРµРј СЃР°Рј С‚РѕРІР°СЂ
                                        $existingGoodWithField->delete();

                                    } catch (\Exception $deleteException) {
                                        Log::error('РћС€РёР±РєР° РїСЂРё СѓРґР°Р»РµРЅРёРё РґСѓР±Р»РёРєР°С‚Р° С‚РѕРІР°СЂР°', [
                                            'duplicate_good_id' => $existingGoodWithField->id,
                                            'error' => $deleteException->getMessage(),
                                        ]);
                                        // РџСЂРѕРґРѕР»Р¶Р°РµРј РёРјРїРѕСЂС‚, РЅРѕ Р»РѕРіРёСЂСѓРµРј РѕС€РёР±РєСѓ
                                    }
                                } else {
                                }
                            }

                            // Р”Р»СЏ В«РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС…В»: РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РїСЂРёРІСЏР·С‹РІР°РµРј Рє РІР°СЂРёР°С†РёРё С‚РѕР»СЊРєРѕ РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РµСЃС‚СЊ РІР°СЂРёР°С†РёРё;
                            // РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РЅРµС‚ РІР°СЂРёР°С†РёР№ вЂ” Рє РѕСЃРЅРѕРІРЅРѕРјСѓ С‚РѕРІР°СЂСѓ
                            $attachImagesTo = ($existingGood->variations()->count() > 0) ? $existingVariation : null;
                            $updateResult = $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $immutableFields, $searchByNameInVariations, $hasVariation, $supplierStockFields, $nameTrimSymbol, $attachImagesTo, $searchByFieldInVariations, $skipImagesOnUpdateIfExists);
                            $results['imagesDownloaded'] += $updateResult['imageStats']['downloaded'];
                            $results['imagesFailed'] += $updateResult['imageStats']['failed'];

                            // Р”Р»СЏ Р»РѕРіРёРєРё "РР·РјРµРЅРёС‚СЊ РІР°СЂРёР°С†РёСЋ" С‚Р°РєР¶Рµ РѕР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё СЃРѕРїРѕСЃС‚Р°РІР»РµРЅРЅС‹С… РІР°СЂРёР°С†РёР№
                            // Р’Р°Р¶РЅРѕ: РёСЃРїРѕР»СЊР·СѓРµРј $vid РІ С†РёРєР»Рµ, С‡С‚РѕР±С‹ РЅРµ РїРµСЂРµР·Р°РїРёСЃР°С‚СЊ $variationId (РЅСѓР¶РµРЅ РґР»СЏ variationIds РЅРёР¶Рµ)
                            if (isset($goodData['variation_ids']) && is_array($goodData['variation_ids'])) {
                                foreach ($goodData['variation_ids'] as $vid) {
                                    $variation = ShopGoodVariation::where('id', $vid)
                                        ->where('good_id', $existingGood->id)
                                        ->first();

                                    if ($variation) {
                                        // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёРё РґР°РЅРЅС‹РјРё РёР· С‚РѕРІР°СЂР° СЃ РєРѕРЅРІРµСЂС‚Р°С†РёРµР№ С‚РёРїРѕРІ
                                        if (isset($goodData['stock_quantity']) && !in_array('stock_quantity', $immutableFields)) {
                                            $variation->stock_quantity = is_numeric($goodData['stock_quantity']) ? (float) $goodData['stock_quantity'] : 0;
                                        }
                                        if (isset($goodData['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                                            $variation->remote_stock_quantity = $goodData['remote_stock_quantity'] !== null && is_numeric($goodData['remote_stock_quantity']) ? (string) $goodData['remote_stock_quantity'] : $goodData['remote_stock_quantity'];
                                        }
                                        if (isset($goodData['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                                            $variation->fast_remote_stock_quantity = $goodData['fast_remote_stock_quantity'] !== null && is_numeric($goodData['fast_remote_stock_quantity']) ? (string) $goodData['fast_remote_stock_quantity'] : $goodData['fast_remote_stock_quantity'];
                                        }

                                        $variation->save();
                                    }
                                }
                            }
                        }

                        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР°
                        // Р’РђР–РќРћ: РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‚РѕРІР°СЂР° РЅСѓР¶РЅРѕ РїСЂРѕРІРµСЂРёС‚СЊ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё РџР•Р Р•Р” РїСЂРёРјРµРЅРµРЅРёРµРј РєР°С‚РµРіРѕСЂРёР№ РёР· С„Р°Р№Р»Р°
                        // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР° РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РІ applyCategoryAndBrandIds, РЅРѕ Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РєР°С‚РµРіРѕСЂРёРё - РЅРµ РїРµСЂРµР·Р°РїРёСЃС‹РІР°РµРј
                        $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();
                        $categoryIds = [];

                        if (isset($goodData['category']) && !empty($goodData['category'])) {
                            $categoryIds = [(int) $goodData['category']];
                        } elseif (isset($goodData['categories']) && is_array($goodData['categories']) && !empty($goodData['categories'])) {
                            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                return $id > 0;
                            });
                        }

                        // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёРё РµСЃС‚СЊ РІ $goodData, РїСЂРѕРІРµСЂСЏРµРј, РЅРµ Р±С‹Р»Р° Р»Рё СЌС‚Рѕ РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                        // Р•СЃР»Рё РµРґРёРЅСЃС‚РІРµРЅРЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ СЃРѕРІРїР°РґР°РµС‚ СЃ defaultCategory, Рё Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РґСЂСѓРіРёРµ РєР°С‚РµРіРѕСЂРёРё - РЅРµ РїРµСЂРµР·Р°РїРёСЃС‹РІР°РµРј
                        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                            $defaultCategoryId = (int) $defaultCategory;
                            // Р•СЃР»Рё РµРґРёРЅСЃС‚РІРµРЅРЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ - СЌС‚Рѕ defaultCategory, Рё Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РґСЂСѓРіРёРµ РєР°С‚РµРіРѕСЂРёРё
                            if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                // Р’РµСЂРѕСЏС‚РЅРѕ, РєР°С‚РµРіРѕСЂРёСЏ Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР° РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ - РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                                $categoryIds = $existingCategoryIds;
                            }
                        } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                            // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ РІ С„Р°Р№Р»Рµ, РЅРѕ РІРєР»СЋС‡РµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                            // РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‚РѕРІР°СЂР°: РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ С‚РѕР»СЊРєРѕ РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№
                            if (empty($existingCategoryIds)) {
                                // РЈ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№ - РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                                $categoryIds = [(int) $defaultCategory];
                            } else {
                                // РЈ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РєР°С‚РµРіРѕСЂРёРё - РѕСЃС‚Р°РІР»СЏРµРј РёС… Р±РµР· РёР·РјРµРЅРµРЅРёР№
                                $categoryIds = $existingCategoryIds;
                            }
                        }

                        // РЎРёРЅС…СЂРѕРЅРёР·РёСЂСѓРµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅРё Р±С‹Р»Рё СѓРєР°Р·Р°РЅС‹ РІ С„Р°Р№Р»Рµ РёР»Рё РїСЂРёРјРµРЅРµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                        // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ С‚СЂРѕРіР°РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                        if (!empty($categoryIds)) {
                            $existingGood->categories()->sync($categoryIds);
                        }
                        // Р•СЃР»Рё $categoryIds РїСѓСЃС‚РѕР№ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ РІС‹Р·С‹РІР°РµРј sync, РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё

                        $results['updated']++;

                        // РЎРѕС…СЂР°РЅСЏРµРј ID С‚РѕРІР°СЂР°
                        if (!empty($sku)) {
                            $results['goodIds'][$sku] = $existingGood->id;
                        }

                        if (isset($goodData['_row'])) {
                            $results['goodIds'][$goodData['_row']] = $existingGood->id;
                        }

                        // РЎРѕС…СЂР°РЅСЏРµРј ID РІР°СЂРёР°С†РёРё РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё (С„СЂРѕРЅС‚РµРЅРґ: РІСЃС‚СЂРѕРµРЅРЅС‹Рµ Рё СЃРІСЏР·Р°РЅРЅС‹Рµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ)
                        if ($variationId) {
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }
                            // Р”Р»СЏ В«РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС…В»: РІ СЃС‚СЂРѕРєРµ РЅРµС‚ variation.attributes, РґРѕР±Р°РІР»СЏРµРј РїРѕ sku Рё name РґР»СЏ РЅР°РґС‘Р¶РЅРѕРіРѕ РїРѕРёСЃРєР° РЅР° С„СЂРѕРЅС‚Рµ
                            if (!empty($sku)) {
                                $results['variationIds'][$sku] = $variationId;
                            }
                            if (!empty($name)) {
                                $results['variationIds'][$name] = $variationId;
                            }

                            // РўР°РєР¶Рµ СЃРѕС…СЂР°РЅСЏРµРј РїРѕ РєР»СЋС‡Сѓ РЅР° РѕСЃРЅРѕРІРµ SKU + Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёРё РґР»СЏ СЃР»СѓС‡Р°РµРІ,
                            // РєРѕРіРґР° РЅРµСЃРєРѕР»СЊРєРѕ РІР°СЂРёР°С†РёР№ РёРјРµСЋС‚ РѕРґРёРЅР°РєРѕРІС‹Р№ SKU
                            if (
                                isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                            ) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // РЎРѕСЂС‚РёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РєРѕРЅСЃРёСЃС‚РµРЅС‚РЅРѕСЃС‚Рё РєР»СЋС‡Р°
                                $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function ($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;

                            }
                        }

                        // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
                        $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                        $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];

                        continue; // РџСЂРѕРїСѓСЃРєР°РµРј РґР°Р»СЊРЅРµР№С€СѓСЋ РѕР±СЂР°Р±РѕС‚РєСѓ
                    }

                    // РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU: РІ РІР°СЂРёР°С†РёСЏС… РЅРµ РЅР°С€Р»Рё, РЅР°С€Р»Рё РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ РїРѕ РЅР°Р·РІР°РЅРёСЋ.
                    // Р•СЃР»Рё Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РІР°СЂРёР°С†РёРё вЂ” РґРѕР±Р°РІР»СЏРµРј РЅРѕРІСѓСЋ (createSimpleVariation).
                    // Р•СЃР»Рё Сѓ С‚РѕРІР°СЂР° РЅРµС‚ РІР°СЂРёР°С†РёР№ вЂ” РЅРµ СЃРѕР·РґР°С‘Рј РІР°СЂРёР°С†РёСЋ; РґР°Р»РµРµ СЃСЂР°Р±РѕС‚Р°РµС‚ В«if ($existingGood)В» Рё РѕР±РЅРѕРІРёС‚СЃСЏ РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ (updateGood).
                    if ($searchByNameInVariations && !$hasVariation && !$existingVariation && $existingGood && $searchByFieldInVariations === 'sku' && $foundMainGoodByName) {
                        if ($existingGood->variations()->exists()) {
                            // РўРѕРІР°СЂ СЃ РІР°СЂРёР°С†РёСЏРјРё вЂ” РґРѕР±Р°РІР»СЏРµРј РЅРѕРІСѓСЋ РІР°СЂРёР°С†РёСЋ (Р°СЂС‚РёРєСѓР» РѕР±СЏР·Р°С‚РµР»РµРЅ)
                            if (empty($sku)) {
                                $results['skipped']++;
                                $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                                $reason = 'РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU: С‚РѕРІР°СЂ РЅР°Р№РґРµРЅ РїРѕ РЅР°Р·РІР°РЅРёСЋ В«' . $name . 'В», Сѓ С‚РѕРІР°СЂР° РµСЃС‚СЊ РІР°СЂРёР°С†РёРё, РЅРѕ Р°СЂС‚РёРєСѓР» РІ СЃС‚СЂРѕРєРµ РїСѓСЃС‚РѕР№ вЂ” РЅРµРІРѕР·РјРѕР¶РЅРѕ СЃРѕР·РґР°С‚СЊ РІР°СЂРёР°С†РёСЋ.';
                                $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];

                                continue;
                            }
                            if (!isset($goodData['_nameTrimSymbol'])) {
                                $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
                            }
                            $variationId = $this->createSimpleVariation($existingGood, $goodData, $supplierStockFields);
                            if (!$variationId) {
                                $results['skipped']++;
                                $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                                $reason = 'РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU: С‚РѕРІР°СЂ РЅР°Р№РґРµРЅ РїРѕ РЅР°Р·РІР°РЅРёСЋ В«' . $name . 'В», РЅРµ СѓРґР°Р»РѕСЃСЊ СЃРѕР·РґР°С‚СЊ РІР°СЂРёР°С†РёСЋ (Р°СЂС‚РёРєСѓР»: ' . $sku . ').';
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
                            $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];

                            continue;
                        }
                        // РўРѕРІР°СЂ Р±РµР· РІР°СЂРёР°С†РёР№ вЂ” РЅРµ СЃРѕР·РґР°С‘Рј РІР°СЂРёР°С†РёСЋ, РёРґС‘Рј РІ В«if ($existingGood)В» в†’ updateGood (РѕР±РЅРѕРІР»РµРЅРёРµ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°)
                    }

                    // Р›РѕРіРёСЂСѓРµРј С‚РµРєСѓС‰РёРµ Р·РЅР°С‡РµРЅРёСЏ РїРµСЂРµРјРµРЅРЅС‹С… РґР»СЏ РѕС‚Р»Р°РґРєРё

                    // Р•СЃР»Рё РІРєР»СЋС‡РµРЅ РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… Рё С‚РѕРІР°СЂ РёРјРµРµС‚ РІР°СЂРёР°С†РёРё, РЅРѕ РІР°СЂРёР°С†РёСЏ РЅРµ РЅР°Р№РґРµРЅР°,
                    // Р° С‚РѕРІР°СЂ РЅР°Р№РґРµРЅ - СЃРѕР·РґР°РµРј РЅРѕРІСѓСЋ РІР°СЂРёР°С†РёСЋ РґР»СЏ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРіРѕ С‚РѕРІР°СЂР°
                    if ($searchByNameInVariations && $hasVariation && !$existingVariation && $existingGood) {
                        // РЎРѕР·РґР°РµРј РЅРѕРІСѓСЋ РІР°СЂРёР°С†РёСЋ РґР»СЏ РЅР°Р№РґРµРЅРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        // РџРµСЂРµРґР°РµРј nameTrimSymbol РІ goodData РґР»СЏ РѕР±СЂРµР·РєРё РЅР°Р·РІР°РЅРёСЏ С‚РѕРІР°СЂР°
                        if (!isset($goodData['_nameTrimSymbol'])) {
                            $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
                        }
                        $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields);

                        // РЎРѕС…СЂР°РЅСЏРµРј ID РІР°СЂРёР°С†РёРё РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                        if ($variationId) {
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }

                            // РўР°РєР¶Рµ СЃРѕС…СЂР°РЅСЏРµРј РїРѕ РєР»СЋС‡Сѓ РЅР° РѕСЃРЅРѕРІРµ SKU + Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёРё РґР»СЏ СЃР»СѓС‡Р°РµРІ,
                            // РєРѕРіРґР° РЅРµСЃРєРѕР»СЊРєРѕ РІР°СЂРёР°С†РёР№ РёРјРµСЋС‚ РѕРґРёРЅР°РєРѕРІС‹Р№ SKU
                            if (
                                isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                            ) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // РЎРѕСЂС‚РёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РєРѕРЅСЃРёСЃС‚РµРЅС‚РЅРѕСЃС‚Рё РєР»СЋС‡Р°
                                $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function ($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;
                            }
                        }

                        // РР·РѕР±СЂР°Р¶РµРЅРёСЏ РїСЂРёРІСЏР·С‹РІР°РµРј Рє СЃРѕР·РґР°РЅРЅРѕР№ РІР°СЂРёР°С†РёРё, Р° РЅРµ Рє РѕСЃРЅРѕРІРЅРѕРјСѓ С‚РѕРІР°СЂСѓ
                        if (isset($goodData['images']) && is_array($goodData['images']) && $variationId) {
                            $variationForImages = ShopGoodVariation::find($variationId);
                            if ($variationForImages) {
                                $imageStats = $this->processImages($existingGood, $goodData['images'], $variationForImages, $skipImagesOnUpdateIfExists, $naming);
                                $results['imagesDownloaded'] += $imageStats['downloaded'];
                                $results['imagesFailed'] += $imageStats['failed'];
                            }
                        }

                        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР° (РґР°Р¶Рµ РµСЃР»Рё РѕР±СЂР°Р±Р°С‚С‹РІР°РµС‚СЃСЏ С‚РѕР»СЊРєРѕ РІР°СЂРёР°С†РёСЏ)
                        $categoryIds = [];
                        if (isset($goodData['category']) && !empty($goodData['category'])) {
                            $categoryIds = [(int) $goodData['category']];
                        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                return $id > 0;
                            });
                        }

                        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР° РїСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‡РµСЂРµР· РІР°СЂРёР°С†РёСЋ
                        // Р’РђР–РќРћ: РџСЂРѕРІРµСЂСЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё РџР•Р Р•Р” РїСЂРёРјРµРЅРµРЅРёРµРј РєР°С‚РµРіРѕСЂРёР№ РёР· С„Р°Р№Р»Р°
                        $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();

                        // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёРё РµСЃС‚СЊ РІ $goodData, РїСЂРѕРІРµСЂСЏРµРј, РЅРµ Р±С‹Р»Р° Р»Рё СЌС‚Рѕ РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                            $defaultCategoryId = (int) $defaultCategory;
                            // Р•СЃР»Рё РµРґРёРЅСЃС‚РІРµРЅРЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ - СЌС‚Рѕ defaultCategory, Рё Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РґСЂСѓРіРёРµ РєР°С‚РµРіРѕСЂРёРё
                            if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                // Р’РµСЂРѕСЏС‚РЅРѕ, РєР°С‚РµРіРѕСЂРёСЏ Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР° РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ - РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                                $categoryIds = $existingCategoryIds;
                            }
                        } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                            // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ РІ С„Р°Р№Р»Рµ, РЅРѕ РІРєР»СЋС‡РµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                            // РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‚РѕРІР°СЂР°: РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ С‚РѕР»СЊРєРѕ РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№
                            if (empty($existingCategoryIds)) {
                                // РЈ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№ - РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                                $categoryIds = [(int) $defaultCategory];
                            } else {
                                // РЈ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РєР°С‚РµРіРѕСЂРёРё - РѕСЃС‚Р°РІР»СЏРµРј РёС… Р±РµР· РёР·РјРµРЅРµРЅРёР№
                                $categoryIds = $existingCategoryIds;
                            }
                        }

                        // РЎРёРЅС…СЂРѕРЅРёР·РёСЂСѓРµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅРё Р±С‹Р»Рё СѓРєР°Р·Р°РЅС‹ РІ С„Р°Р№Р»Рµ РёР»Рё РїСЂРёРјРµРЅРµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                        // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ С‚СЂРѕРіР°РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                        if (!empty($categoryIds)) {
                            $existingGood->categories()->sync($categoryIds);
                        }
                        // Р•СЃР»Рё $categoryIds РїСѓСЃС‚РѕР№ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ РІС‹Р·С‹РІР°РµРј sync, РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё

                        $results['updated']++; // РЎС‡РёС‚Р°РµРј РєР°Рє РѕР±РЅРѕРІР»РµРЅРёРµ (РґРѕР±Р°РІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё)

                        // РЎРѕС…СЂР°РЅСЏРµРј ID С‚РѕРІР°СЂР°
                        if (!empty($sku)) {
                            $results['goodIds'][$sku] = $existingGood->id;
                        }

                        if (isset($goodData['_row'])) {
                            $results['goodIds'][$goodData['_row']] = $existingGood->id;
                        }

                        // РЎРѕС…СЂР°РЅСЏРµРј ID РІР°СЂРёР°С†РёРё РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                        if ($variationId) {
                            // РСЃРїРѕР»СЊР·СѓРµРј _row РєР°Рє РєР»СЋС‡ РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё (СЃР°РјС‹Р№ РЅР°РґРµР¶РЅС‹Р№ СЃРїРѕСЃРѕР±)
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $variationId;
                            }

                            // РўР°РєР¶Рµ СЃРѕС…СЂР°РЅСЏРµРј РїРѕ РєР»СЋС‡Сѓ РЅР° РѕСЃРЅРѕРІРµ SKU + Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёРё РґР»СЏ СЃР»СѓС‡Р°РµРІ,
                            // РєРѕРіРґР° РЅРµСЃРєРѕР»СЊРєРѕ РІР°СЂРёР°С†РёР№ РёРјРµСЋС‚ РѕРґРёРЅР°РєРѕРІС‹Р№ SKU
                            if (
                                isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                            ) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // РЎРѕСЂС‚РёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РєРѕРЅСЃРёСЃС‚РµРЅС‚РЅРѕСЃС‚Рё РєР»СЋС‡Р°
                                $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function ($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $variationId;
                            }
                        }

                        // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
                        $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                        $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];

                        continue; // РџСЂРѕРїСѓСЃРєР°РµРј РґР°Р»СЊРЅРµР№С€СѓСЋ РѕР±СЂР°Р±РѕС‚РєСѓ
                    }

                    if ($existingGood) {
                        // РљР РРўРР§РќРћ: Р•СЃР»Рё РІР°СЂРёР°С†РёСЏ СѓР¶Рµ РЅР°Р№РґРµРЅР° Рё РѕР±РЅРѕРІР»РµРЅР° РІ Р±Р»РѕРєРµ РІС‹С€Рµ (691-921),
                        // С‚Рѕ $existingVariation СѓСЃС‚Р°РЅРѕРІР»РµРЅ Рё РєРѕРґ РґРѕР»Р¶РµРЅ Р±С‹Р» СЃРґРµР»Р°С‚СЊ continue.
                        // Р•СЃР»Рё РјС‹ РїРѕРїР°Р»Рё СЃСЋРґР°, РЅРѕ $existingVariation СѓСЃС‚Р°РЅРѕРІР»РµРЅ - СЌС‚Рѕ РѕС€РёР±РєР° Р»РѕРіРёРєРё.
                        // РџСЂРѕРїСѓСЃРєР°РµРј РґР°Р»СЊРЅРµР№С€СѓСЋ РѕР±СЂР°Р±РѕС‚РєСѓ, С‡С‚РѕР±С‹ РЅРµ СЃРѕР·РґР°РІР°С‚СЊ РґСѓР±Р»РёРєР°С‚ С‚РѕРІР°СЂР°.
                        if ($existingVariation && $searchByNameInVariations) {
                            // Р’Р°СЂРёР°С†РёСЏ СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅР° РІС‹С€Рµ, РїСЂРѕРїСѓСЃРєР°РµРј
                            continue;
                        }

                        // РўРѕРІР°СЂ СЃСѓС‰РµСЃС‚РІСѓРµС‚
                        // Р•СЃР»Рё Сѓ СЃС‚СЂРѕРєРё РµСЃС‚СЊ РІР°СЂРёР°С†РёСЏ, РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј РµС‘ РЅРµР·Р°РІРёСЃРёРјРѕ РѕС‚ duplicateAction
                        if ($hasVariation) {
                            // РљР РРўРР§РќРћ: РћС‚РґРµР»СЊРЅРѕ РѕР±СЂРµР·Р°РµРј Рё РѕР±РЅРѕРІР»СЏРµРј РЅР°Р·РІР°РЅРёРµ С‚РѕРІР°СЂР° СЃ РІР°СЂРёР°С†РёРµР№
                            // Р­С‚Рѕ РґРѕР»Р¶РЅРѕ РїСЂРѕРёСЃС…РѕРґРёС‚СЊ Р”Рћ РѕР±СЂР°Р±РѕС‚РєРё РІР°СЂРёР°С†РёРё
                            // Р’РђР–РќРћ: Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё РЅР°Р·РІР°РЅРёРµ РѕР±РЅРѕРІР»СЏРµС‚СЃСЏ РѕС‚РґРµР»СЊРЅРѕ, РЅРµР·Р°РІРёСЃРёРјРѕ РѕС‚ searchByNameInVariations
                            // РљР РРўРР§РќРћ: Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё РѕС‚РґРµР»СЊРЅРѕ РѕР±СЂРµР·Р°РµРј Рё РѕР±РЅРѕРІР»СЏРµРј РЅР°Р·РІР°РЅРёРµ
                            // РСЃРїРѕР»СЊР·СѓРµРј РЅР°Р·РІР°РЅРёРµ РёР· РґР°РЅРЅС‹С… РёРјРїРѕСЂС‚Р°, РµСЃР»Рё РѕРЅРѕ РµСЃС‚СЊ, РёРЅР°С‡Рµ РїСЂРѕРІРµСЂСЏРµРј РЅР°Р·РІР°РЅРёРµ РІ Р‘Р”
                            $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
                            $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);

                            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј С‚РѕР»СЊРєРѕ РІР°СЂРёР°С†РёСЋ, РЅРѕ С‚Р°РєР¶Рµ РѕР±РЅРѕРІР»СЏРµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР°, РµСЃР»Рё РЅСѓР¶РЅРѕ
                            // РџРµСЂРµРґР°РµРј nameTrimSymbol РІ goodData РґР»СЏ РѕР±СЂРµР·РєРё РЅР°Р·РІР°РЅРёСЏ С‚РѕРІР°СЂР° (РЅР° СЃР»СѓС‡Р°Р№ РµСЃР»Рё РѕР±СЂРµР·РєР° РЅРµ Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР° РІС‹С€Рµ)
                            if (!isset($goodData['_nameTrimSymbol'])) {
                                $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
                            }
                            $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields);

                            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР° (РґР°Р¶Рµ РµСЃР»Рё РѕР±СЂР°Р±Р°С‚С‹РІР°РµС‚СЃСЏ С‚РѕР»СЊРєРѕ РІР°СЂРёР°С†РёСЏ)
                            $categoryIds = [];
                            if (isset($goodData['category']) && !empty($goodData['category'])) {
                                $categoryIds = [(int) $goodData['category']];
                            } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                                $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                    return $id > 0;
                                });
                            }

                            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР° РїСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‡РµСЂРµР· РІР°СЂРёР°С†РёСЋ
                            // Р’РђР–РќРћ: РџСЂРѕРІРµСЂСЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё РџР•Р Р•Р” РїСЂРёРјРµРЅРµРЅРёРµРј РєР°С‚РµРіРѕСЂРёР№ РёР· С„Р°Р№Р»Р°
                            $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();

                            // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёРё РµСЃС‚СЊ РІ $goodData, РїСЂРѕРІРµСЂСЏРµРј, РЅРµ Р±С‹Р»Р° Р»Рё СЌС‚Рѕ РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                            if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                                $defaultCategoryId = (int) $defaultCategory;
                                // Р•СЃР»Рё РµРґРёРЅСЃС‚РІРµРЅРЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ - СЌС‚Рѕ defaultCategory, Рё Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РґСЂСѓРіРёРµ РєР°С‚РµРіРѕСЂРёРё
                                if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                    // Р’РµСЂРѕСЏС‚РЅРѕ, РєР°С‚РµРіРѕСЂРёСЏ Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР° РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ - РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                                    $categoryIds = $existingCategoryIds;
                                }
                            } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                                // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ РІ С„Р°Р№Р»Рµ, РЅРѕ РІРєР»СЋС‡РµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                                // РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‚РѕРІР°СЂР°: РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ С‚РѕР»СЊРєРѕ РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№
                                if (empty($existingCategoryIds)) {
                                    // РЈ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№ - РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                                    $categoryIds = [(int) $defaultCategory];
                                } else {
                                    // РЈ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РєР°С‚РµРіРѕСЂРёРё - РѕСЃС‚Р°РІР»СЏРµРј РёС… Р±РµР· РёР·РјРµРЅРµРЅРёР№
                                    $categoryIds = $existingCategoryIds;
                                }
                            }

                            // РЎРёРЅС…СЂРѕРЅРёР·РёСЂСѓРµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅРё Р±С‹Р»Рё СѓРєР°Р·Р°РЅС‹ РІ С„Р°Р№Р»Рµ РёР»Рё РїСЂРёРјРµРЅРµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                            // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ С‚СЂРѕРіР°РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                            if (!empty($categoryIds)) {
                                $existingGood->categories()->sync($categoryIds);
                            }
                            // Р•СЃР»Рё $categoryIds РїСѓСЃС‚РѕР№ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ РІС‹Р·С‹РІР°РµРј sync, РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё

                            $results['updated']++; // РЎС‡РёС‚Р°РµРј РєР°Рє РѕР±РЅРѕРІР»РµРЅРёРµ (РґРѕР±Р°РІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё)

                            // РЎРѕС…СЂР°РЅСЏРµРј ID С‚РѕРІР°СЂР°
                            if (!empty($sku)) {
                                $results['goodIds'][$sku] = $existingGood->id;
                            }

                            if (isset($goodData['_row'])) {
                                $results['goodIds'][$goodData['_row']] = $existingGood->id;
                            }

                            // РЎРѕС…СЂР°РЅСЏРµРј ID РІР°СЂРёР°С†РёРё РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                            if ($variationId) {
                                // РСЃРїРѕР»СЊР·СѓРµРј _row РєР°Рє РєР»СЋС‡ РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё (СЃР°РјС‹Р№ РЅР°РґРµР¶РЅС‹Р№ СЃРїРѕСЃРѕР±)
                                if (isset($goodData['_row'])) {
                                    $results['variationIds'][$goodData['_row']] = $variationId;
                                }

                                // РўР°РєР¶Рµ СЃРѕС…СЂР°РЅСЏРµРј РїРѕ РєР»СЋС‡Сѓ РЅР° РѕСЃРЅРѕРІРµ SKU + Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёРё РґР»СЏ СЃР»СѓС‡Р°РµРІ,
                                // РєРѕРіРґР° РЅРµСЃРєРѕР»СЊРєРѕ РІР°СЂРёР°С†РёР№ РёРјРµСЋС‚ РѕРґРёРЅР°РєРѕРІС‹Р№ SKU
                                if (
                                    isset($goodData['sku']) && !empty($goodData['sku']) &&
                                    isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                    is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                                ) {

                                    $sku = trim($goodData['sku']);
                                    $attributes = $goodData['variation']['attributes'];

                                    // РЎРѕСЂС‚РёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РєРѕРЅСЃРёСЃС‚РµРЅС‚РЅРѕСЃС‚Рё РєР»СЋС‡Р°
                                    $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                        return $attr['name'] ?? '';
                                    })->map(function ($attr) {
                                        return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                    })->join('|');

                                    $variationKey = $sku . ':::' . $sortedAttributes;
                                    $results['variationIds'][$variationKey] = $variationId;
                                }
                            }

                            // РР·РѕР±СЂР°Р¶РµРЅРёСЏ РїСЂРёРІСЏР·С‹РІР°РµРј Рє РІР°СЂРёР°С†РёРё, Р° РЅРµ Рє РѕСЃРЅРѕРІРЅРѕРјСѓ С‚РѕРІР°СЂСѓ
                            if (isset($goodData['images']) && is_array($goodData['images']) && $variationId) {
                                $variationForImages = ShopGoodVariation::find($variationId);
                                if ($variationForImages) {
                                    $imageStats = $this->processImages($existingGood, $goodData['images'], $variationForImages, $skipImagesOnUpdateIfExists, $naming);
                                    $results['imagesDownloaded'] += $imageStats['downloaded'];
                                    $results['imagesFailed'] += $imageStats['failed'];
                                }
                            }

                            // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
                            $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];
                        } elseif ($duplicateAction === 'update') {
                            // РљР РРўРР§РќРћ: Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё РѕС‚РґРµР»СЊРЅРѕ РѕР±СЂРµР·Р°РµРј Рё РѕР±РЅРѕРІР»СЏРµРј РЅР°Р·РІР°РЅРёРµ
                            // Р­С‚Рѕ РґРѕР»Р¶РЅРѕ РїСЂРѕРёСЃС…РѕРґРёС‚СЊ Р”Рћ РІС‹Р·РѕРІР° updateGood
                            if ($hasVariation) {
                                $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
                                $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);
                            }
                            // РћР±СЂРµР·РєР° РЅР°Р·РІР°РЅРёСЏ РґР»СЏ С‚РѕРІР°СЂРѕРІ Р±РµР· РІР°СЂРёР°С†РёР№ Р±СѓРґРµС‚ РѕР±СЂР°Р±РѕС‚Р°РЅР° РІ updateGood

                            // РџСЂРѕРІРµСЂСЏРµРј РїРѕР»Рµ РґР»СЏ СѓРґР°Р»РµРЅРёСЏ РґСѓР±Р»РёРєР°С‚РѕРІ (РґР»СЏ РІР°СЂРёР°РЅС‚Р° 2)
                            $duplicateCheckField = $request->input('duplicate_check_field');

                            // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ Р·РЅР°С‡РµРЅРёСЏ, РєРѕС‚РѕСЂРѕРµ Р±СѓРґРµС‚ СѓСЃС‚Р°РЅРѕРІР»РµРЅРѕ С‚РѕРІР°СЂСѓ
                            if (!empty($duplicateCheckField) && isset($goodData[$duplicateCheckField])) {
                                $fieldValue = $goodData[$duplicateCheckField];

                                // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё СѓР¶Рµ С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј Р·РЅР°С‡РµРЅРёРµРј РїРѕР»СЏ (РІРєР»СЋС‡Р°СЏ С‚РµРєСѓС‰РёР№ С‚РѕРІР°СЂ)
                                $existingGoodWithField = ShopGood::where($duplicateCheckField, $fieldValue)->first();

                                // Р•СЃР»Рё РЅР°Р№РґРµРЅ С‚РѕРІР°СЂ СЃ С‚Р°РєРёРј Р·РЅР°С‡РµРЅРёРµРј РїРѕР»СЏ, Рё СЌС‚Рѕ РќР• С‚РµРєСѓС‰РёР№ С‚РѕРІР°СЂ
                                if ($existingGoodWithField && $existingGoodWithField->id !== $existingGood->id) {
                                    // РЈРґР°Р»СЏРµРј РґСѓР±Р»РёРєР°С‚ С‚РѕРІР°СЂР°
                                    try {
                                        // РЈРґР°Р»СЏРµРј СЃРІСЏР·Р°РЅРЅС‹Рµ РґР°РЅРЅС‹Рµ
                                        $existingGoodWithField->categories()->detach();
                                        $existingGoodWithField->brands()->detach();
                                        $existingGoodWithField->tags()->detach();
                                        $existingGoodWithField->properties()->detach();
                                        $existingGoodWithField->images()->delete();
                                        $existingGoodWithField->variations()->delete();

                                        // РЈРґР°Р»СЏРµРј СЃР°Рј С‚РѕРІР°СЂ
                                        $existingGoodWithField->delete();

                                    } catch (\Exception $deleteException) {
                                        Log::error('РћС€РёР±РєР° РїСЂРё СѓРґР°Р»РµРЅРёРё РґСѓР±Р»РёРєР°С‚Р° С‚РѕРІР°СЂР° (РІР°СЂРёР°РЅС‚ 2)', [
                                            'duplicate_good_id' => $existingGoodWithField->id,
                                            'error' => $deleteException->getMessage(),
                                        ]);
                                        // РџСЂРѕРґРѕР»Р¶Р°РµРј РёРјРїРѕСЂС‚, РЅРѕ Р»РѕРіРёСЂСѓРµРј РѕС€РёР±РєСѓ
                                    }
                                } else {
                                }
                            }

                            // Р•СЃР»Рё Сѓ С‚РѕРІР°СЂР° РµСЃС‚СЊ РІР°СЂРёР°С†РёРё Рё РІ СЃС‚СЂРѕРєРµ СѓРєР°Р·Р°РЅ SKU вЂ” РѕР±РЅРѕРІР»СЏРµРј РІР°СЂРёР°С†РёСЋ Рё РїСЂРёРІСЏР·С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ Рє РЅРµР№
                            $attachImagesToVariation = null;
                            if ($existingGood->variations()->count() > 0 && !empty($sku)) {
                                $attachImagesToVariation = $existingGood->variations()->where('sku', $sku)->first();
                                // РљСЂРёС‚РёС‡РЅРѕ: РѕР±РЅРѕРІР»СЏРµРј РІР°СЂРёР°С†РёСЋ (РѕСЃС‚Р°С‚РєРё, С†РµРЅР°, SKU Рё С‚.Рґ.) РёР· СЃС‚СЂРѕРєРё вЂ” РёРЅР°С‡Рµ РІР°СЂРёР°С†РёРё В«РЅРµ Р·Р°С‚СЂР°РіРёРІР°СЋС‚СЃСЏВ»
                                if ($attachImagesToVariation) {
                                    $this->updateVariationFromGoodData($attachImagesToVariation, $goodData, $searchByNameInVariations);
                                }
                            }

                            $updateResult = $this->updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $immutableFields, $searchByNameInVariations, $hasVariation, $supplierStockFields, $nameTrimSymbol, $attachImagesToVariation, $searchByFieldInVariations, $skipImagesOnUpdateIfExists, $naming);
                            $results['imagesDownloaded'] += $updateResult['imageStats']['downloaded'];
                            $results['imagesFailed'] += $updateResult['imageStats']['failed'];

                            // Р”Р»СЏ Р»РѕРіРёРєРё "РР·РјРµРЅРёС‚СЊ РІР°СЂРёР°С†РёСЋ" С‚Р°РєР¶Рµ РѕР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё СЃРѕРїРѕСЃС‚Р°РІР»РµРЅРЅС‹С… РІР°СЂРёР°С†РёР№
                            if (isset($goodData['variation_ids']) && is_array($goodData['variation_ids'])) {
                                foreach ($goodData['variation_ids'] as $vid) {
                                    $variation = ShopGoodVariation::where('id', $vid)
                                        ->where('good_id', $existingGood->id)
                                        ->first();

                                    if ($variation) {
                                        // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёРё РґР°РЅРЅС‹РјРё РёР· С‚РѕРІР°СЂР° СЃ РєРѕРЅРІРµСЂС‚Р°С†РёРµР№ С‚РёРїРѕРІ
                                        if (isset($goodData['stock_quantity']) && !in_array('stock_quantity', $immutableFields)) {
                                            $variation->stock_quantity = is_numeric($goodData['stock_quantity']) ? (float) $goodData['stock_quantity'] : 0;
                                        }

                                        if (isset($goodData['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                                            $variation->remote_stock_quantity = $goodData['remote_stock_quantity'] !== null && is_numeric($goodData['remote_stock_quantity']) ? (string) $goodData['remote_stock_quantity'] : $goodData['remote_stock_quantity'];
                                        }

                                        if (isset($goodData['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                                            $variation->fast_remote_stock_quantity = $goodData['fast_remote_stock_quantity'] !== null && is_numeric($goodData['fast_remote_stock_quantity']) ? (string) $goodData['fast_remote_stock_quantity'] : $goodData['fast_remote_stock_quantity'];
                                        }

                                        $variation->save();
                                    }
                                }
                            }

                            $results['updated']++;

                            // РЎРѕС…СЂР°РЅСЏРµРј ID С‚РѕРІР°СЂР°
                            // Р•СЃР»Рё SKU РЅРµ РїСѓСЃС‚РѕР№, СЃРѕС…СЂР°РЅСЏРµРј РїРѕ SKU
                            if (!empty($sku)) {
                                $results['goodIds'][$sku] = $existingGood->id;
                            }

                            // РўР°РєР¶Рµ РґРѕР±Р°РІР»СЏРµРј ID РїРѕ _row РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                            if (isset($goodData['_row'])) {
                                $results['goodIds'][$goodData['_row']] = $existingGood->id;
                            }

                            // РџСЂРё РїСЂРёРІСЏР·РєРµ РёР·РѕР±СЂР°Р¶РµРЅРёР№ Рє РІР°СЂРёР°С†РёРё вЂ” variationIds РґР»СЏ РІСЃС‚СЂРѕРµРЅРЅС‹С…/СЃРІСЏР·Р°РЅРЅС‹С… РёР·РѕР±СЂР°Р¶РµРЅРёР№ РЅР° С„СЂРѕРЅС‚Рµ
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

                            // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
                            $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                            $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id];
                        } else {
                            $results['skipped']++;

                            // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ РїСЂРѕРїСѓСЃРєР°
                            $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                            $foundByText = !empty($foundByFields) ? ' (РЅР°Р№РґРµРЅ РїРѕ: ' . implode(', ', $foundByFields) . ')' : '';
                            $reason = 'Р”СѓР±Р»РёРєР°С‚ (РЅР°СЃС‚СЂРѕР№РєР°: РїСЂРѕРїСѓСЃС‚РёС‚СЊ)' . $foundByText . '. РќР°Р№РґРµРЅРЅС‹Р№ С‚РѕРІР°СЂ ID: ' . $existingGood->id . ', SKU: "' . ($existingGood->sku ?? 'РїСѓСЃС‚РѕР№') . '", РЅР°Р·РІР°РЅРёРµ: "' . $existingGood->name . '"';
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];
                        }
                    } else {
                        // РљР РРўРР§РќРћ: Р•СЃР»Рё РІР°СЂРёР°С†РёСЏ РЅР°Р№РґРµРЅР° РїРѕ Р°СЂС‚РёРєСѓР»Сѓ, РЅРѕ С‚РѕРІР°СЂ РЅРµ РЅР°Р№РґРµРЅ РІ РѕСЃРЅРѕРІРЅРѕРј РїРѕРёСЃРєРµ,
                        // СЌС‚Рѕ РѕР·РЅР°С‡Р°РµС‚, С‡С‚Рѕ РІР°СЂРёР°С†РёСЏ РїСЂРёРЅР°РґР»РµР¶РёС‚ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРјСѓ С‚РѕРІР°СЂСѓ, РєРѕС‚РѕСЂС‹Р№ РјС‹ РґРѕР»Р¶РЅС‹ РёСЃРїРѕР»СЊР·РѕРІР°С‚СЊ.
                        // РќРµ СЃРѕР·РґР°РµРј РЅРѕРІС‹Р№ С‚РѕРІР°СЂ, РµСЃР»Рё РІР°СЂРёР°С†РёСЏ СѓР¶Рµ РЅР°Р№РґРµРЅР° Рё РѕР±СЂР°Р±РѕС‚Р°РЅР°.
                        if ($existingVariation && $searchByNameInVariations) {
                            // Р’Р°СЂРёР°С†РёСЏ СѓР¶Рµ РЅР°Р№РґРµРЅР° Рё РѕР±СЂР°Р±РѕС‚Р°РЅР° РІС‹С€Рµ, РїСЂРѕРїСѓСЃРєР°РµРј СЃРѕР·РґР°РЅРёРµ РЅРѕРІРѕРіРѕ С‚РѕРІР°СЂР°
                            continue;
                        }

                        // РўРѕРІР°СЂ РЅРµ РЅР°Р№РґРµРЅ РїСЂРё РїРµСЂРІРѕРЅР°С‡Р°Р»СЊРЅРѕРј РїРѕРёСЃРєРµ
                        // Р•СЃР»Рё РµСЃС‚СЊ РІР°СЂРёР°С†РёСЏ, РґРµР»Р°РµРј РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅСѓСЋ РїСЂРѕРІРµСЂРєСѓ РїРµСЂРµРґ СЃРѕР·РґР°РЅРёРµРј С‚РѕРІР°СЂР°
                        // С‡С‚РѕР±С‹ РёР·Р±РµР¶Р°С‚СЊ РѕС€РёР±РєРё РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ SKU
                        if ($hasVariation) {
                            // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР°: РёС‰РµРј С‚РѕРІР°СЂ РµС‰Рµ СЂР°Р· Р±РѕР»РµРµ С‚С‰Р°С‚РµР»СЊРЅРѕ
                            // РїРµСЂРµРґ СЃРѕР·РґР°РЅРёРµРј, С‡С‚РѕР±С‹ РёР·Р±РµР¶Р°С‚СЊ РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ
                            $doubleCheckGood = null;

                            // РџСЂРѕРІРµСЂСЏРµРј РїРѕ SKU (РµСЃР»Рё СѓРєР°Р·Р°РЅ)
                            if (!empty($sku)) {
                                $doubleCheckGood = ShopGood::where('sku', $sku)->first();
                            }

                            // Р•СЃР»Рё РЅРµ РЅР°Р№РґРµРЅ РїРѕ SKU, РїСЂРѕРІРµСЂСЏРµРј РїРѕ РёРјРµРЅРё вЂ” С‚РѕР»СЊРєРѕ РµСЃР»Рё 'name' РІ duplicate_fields
                            if (!$doubleCheckGood && in_array('name', $duplicateFields) && !empty($name)) {
                                $doubleCheckGood = ShopGood::where('name', $this->normalizeTextForSearch($name))->first();
                            }

                            // Р•СЃР»Рё РІСЃРµ РµС‰Рµ РЅРµ РЅР°Р№РґРµРЅ, РїСЂРѕРІРµСЂСЏРµРј РїРѕ РёРјРµРЅРё СЃ РїСѓСЃС‚С‹Рј SKU вЂ” С‚РѕР»СЊРєРѕ РµСЃР»Рё 'name' РІ duplicate_fields
                            if (!$doubleCheckGood && empty($sku) && in_array('name', $duplicateFields) && !empty($name)) {
                                $doubleCheckGood = ShopGood::whereNull('sku')->where('name', $name)->first();
                            }

                            if ($doubleCheckGood) {
                                // РўРѕРІР°СЂ РЅР°Р№РґРµРЅ РїСЂРё РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅРѕР№ РїСЂРѕРІРµСЂРєРµ - РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј С‚РѕР»СЊРєРѕ РІР°СЂРёР°С†РёСЋ
                                $variationId = $this->processVariation($doubleCheckGood, $goodData['variation'], $goodData, $supplierStockFields);

                                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР° (РґР°Р¶Рµ РµСЃР»Рё РѕР±СЂР°Р±Р°С‚С‹РІР°РµС‚СЃСЏ С‚РѕР»СЊРєРѕ РІР°СЂРёР°С†РёСЏ)
                                $categoryIds = [];
                                if (isset($goodData['category']) && !empty($goodData['category'])) {
                                    $categoryIds = [(int) $goodData['category']];
                                } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
                                    $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                                        return $id > 0;
                                    });
                                }

                                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР° РїСЂРё РґРІРѕР№РЅРѕР№ РїСЂРѕРІРµСЂРєРµ
                                // Р’РђР–РќРћ: РџСЂРѕРІРµСЂСЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё РџР•Р Р•Р” РїСЂРёРјРµРЅРµРЅРёРµРј РєР°С‚РµРіРѕСЂРёР№ РёР· С„Р°Р№Р»Р°
                                $existingCategoryIds = $doubleCheckGood->categories()->pluck('shop_categories.id')->toArray();

                                // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёРё РµСЃС‚СЊ РІ $goodData, РїСЂРѕРІРµСЂСЏРµРј, РЅРµ Р±С‹Р»Р° Р»Рё СЌС‚Рѕ РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                                if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
                                    $defaultCategoryId = (int) $defaultCategory;
                                    // Р•СЃР»Рё РµРґРёРЅСЃС‚РІРµРЅРЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ - СЌС‚Рѕ defaultCategory, Рё Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РґСЂСѓРіРёРµ РєР°С‚РµРіРѕСЂРёРё
                                    if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                                        // Р’РµСЂРѕСЏС‚РЅРѕ, РєР°С‚РµРіРѕСЂРёСЏ Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР° РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ - РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                                        $categoryIds = $existingCategoryIds;
                                    }
                                } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
                                    // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ РІ С„Р°Р№Р»Рµ, РЅРѕ РІРєР»СЋС‡РµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                                    // РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‚РѕРІР°СЂР°: РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ С‚РѕР»СЊРєРѕ РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№
                                    if (empty($existingCategoryIds)) {
                                        // РЈ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№ - РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                                        $categoryIds = [(int) $defaultCategory];
                                    } else {
                                        // РЈ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РєР°С‚РµРіРѕСЂРёРё - РѕСЃС‚Р°РІР»СЏРµРј РёС… Р±РµР· РёР·РјРµРЅРµРЅРёР№
                                        $categoryIds = $existingCategoryIds;
                                    }
                                }

                                // РЎРёРЅС…СЂРѕРЅРёР·РёСЂСѓРµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅРё Р±С‹Р»Рё СѓРєР°Р·Р°РЅС‹ РІ С„Р°Р№Р»Рµ РёР»Рё РїСЂРёРјРµРЅРµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                                // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ С‚СЂРѕРіР°РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                                if (!empty($categoryIds)) {
                                    $doubleCheckGood->categories()->sync($categoryIds);
                                }
                                // Р•СЃР»Рё $categoryIds РїСѓСЃС‚РѕР№ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ РІС‹Р·С‹РІР°РµРј sync, РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё

                                $results['updated']++; // РЎС‡РёС‚Р°РµРј РєР°Рє РѕР±РЅРѕРІР»РµРЅРёРµ (РґРѕР±Р°РІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё)

                                // РЎРѕС…СЂР°РЅСЏРµРј ID С‚РѕРІР°СЂР°
                                if (!empty($sku)) {
                                    $results['goodIds'][$sku] = $doubleCheckGood->id;
                                }

                                if (isset($goodData['_row'])) {
                                    $results['goodIds'][$goodData['_row']] = $doubleCheckGood->id;
                                }

                                // РЎРѕС…СЂР°РЅСЏРµРј ID РІР°СЂРёР°С†РёРё РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                                if ($variationId) {
                                    if (isset($goodData['_row'])) {
                                        $results['variationIds'][$goodData['_row']] = $variationId;
                                    }

                                    // РўР°РєР¶Рµ СЃРѕС…СЂР°РЅСЏРµРј РїРѕ РєР»СЋС‡Сѓ РЅР° РѕСЃРЅРѕРІРµ SKU + Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёРё РґР»СЏ СЃР»СѓС‡Р°РµРІ,
                                    // РєРѕРіРґР° РЅРµСЃРєРѕР»СЊРєРѕ РІР°СЂРёР°С†РёР№ РёРјРµСЋС‚ РѕРґРёРЅР°РєРѕРІС‹Р№ SKU
                                    if (
                                        isset($goodData['sku']) && !empty($goodData['sku']) &&
                                        isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                        is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                                    ) {

                                        $sku = trim($goodData['sku']);
                                        $attributes = $goodData['variation']['attributes'];

                                        // РЎРѕСЂС‚РёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РєРѕРЅСЃРёСЃС‚РµРЅС‚РЅРѕСЃС‚Рё РєР»СЋС‡Р°
                                        $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                            return $attr['name'] ?? '';
                                        })->map(function ($attr) {
                                            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                        })->join('|');

                                        $variationKey = $sku . ':::' . $sortedAttributes;
                                        $results['variationIds'][$variationKey] = $variationId;
                                    }
                                }

                                // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
                                $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $doubleCheckGood->id, 'supplier' => ($doubleCheckGood->supplier ?? '')];

                                // РџСЂРѕРїСѓСЃРєР°РµРј СЃРѕР·РґР°РЅРёРµ С‚РѕРІР°СЂР°, С‚Р°Рє РєР°Рє РѕРЅ СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
                                continue;
                            }
                        }

                        // РџСЂРѕРІРµСЂСЏРµРј СЂРµР¶РёРј "РўРѕР»СЊРєРѕ РѕР±РЅРѕРІР»СЏС‚СЊ"
                        $onlyUpdateMode = $request->input('only_update_mode', false);
                        if ($onlyUpdateMode) {
                            // Р’ СЂРµР¶РёРјРµ "РўРѕР»СЊРєРѕ РѕР±РЅРѕРІР»СЏС‚СЊ" РїСЂРѕРїСѓСЃРєР°РµРј СЃРѕР·РґР°РЅРёРµ РЅРѕРІС‹С… С‚РѕРІР°СЂРѕРІ
                            $results['skipped']++;
                            $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                            $searchDetails = [];
                            if (!empty($duplicateFields)) {
                                $searchDetails[] = 'РїРѕР»СЏ РїРѕРёСЃРєР°: ' . implode(', ', $duplicateFields);
                            }
                            if (!empty($sku)) {
                                $searchDetails[] = 'SKU: "' . $sku . '"';
                            }
                            if (!empty($name)) {
                                $searchDetails[] = 'РЅР°Р·РІР°РЅРёРµ: "' . $name . '"';
                            }
                            $searchText = !empty($searchDetails) ? ' (' . implode(', ', $searchDetails) . ')' : '';
                            $reason = 'Р РµР¶РёРј "С‚РѕР»СЊРєРѕ РѕР±РЅРѕРІР»СЏС‚СЊ" - С‚РѕРІР°СЂ РЅРµ РЅР°Р№РґРµРЅ РІ Р±Р°Р·Рµ РґР°РЅРЅС‹С…' . $searchText . '. Р’РєР»СЋС‡РµРЅ РїРѕРёСЃРє РїРѕ РїРѕР»СЏРј: ' . implode(', ', $duplicateFields);
                            $skipItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'reason' => $reason];

                            continue;
                        }

                        // РљР РРўРР§РќРћ: Р¤РёРЅР°Р»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° - РµСЃР»Рё РІР°СЂРёР°С†РёСЏ РЅР°Р№РґРµРЅР° РїРѕ Р°СЂС‚РёРєСѓР»Сѓ, РЅРµ СЃРѕР·РґР°РµРј РЅРѕРІС‹Р№ С‚РѕРІР°СЂ
                        // Р­С‚Рѕ Р·Р°С‰РёС‚Р° РѕС‚ СЃРѕР·РґР°РЅРёСЏ РґСѓР±Р»РёРєР°С‚Р°, РµСЃР»Рё РІР°СЂРёР°С†РёСЏ Р±С‹Р»Р° РЅР°Р№РґРµРЅР°, РЅРѕ РєРѕРґ РїРѕ РєР°РєРѕР№-С‚Рѕ РїСЂРёС‡РёРЅРµ
                        // РЅРµ РїРѕРїР°Р» РІ Р±Р»РѕРє РѕР±СЂР°Р±РѕС‚РєРё РІР°СЂРёР°С†РёРё РІС‹С€Рµ
                        // РљР РРўРР§РќРћ: Р¤РёРЅР°Р»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° - РµСЃР»Рё С‚РѕРІР°СЂ РёР»Рё РІР°СЂРёР°С†РёСЏ РЅР°Р№РґРµРЅС‹, РЅРµ СЃРѕР·РґР°РµРј РЅРѕРІС‹Р№ С‚РѕРІР°СЂ
                        if ($existingGood || $existingVariation) {
                            // Р•СЃР»Рё С‚РѕРІР°СЂ РЅР°Р№РґРµРЅ (РІ С‚.С‡. С‡РµСЂРµР· fallback РёР»Рё РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС…), РѕР±РЅРѕРІР»СЏРµРј РµРіРѕ
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

                                $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $goodToUpdate->id, 'supplier' => ($goodToUpdate->supplier ?? '')];

                                continue;
                            }
                        }

                        // РўРѕРІР°СЂ РґРµР№СЃС‚РІРёС‚РµР»СЊРЅРѕ РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ - СЃРѕР·РґР°РµРј РЅРѕРІС‹Р№ (СЃ РІР°СЂРёР°С†РёРµР№ РёР»Рё Р±РµР·)
                        $createResult = $this->createGood($goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory, $supplierStockFields, $nameTrimSymbol, $naming);
                        $newGood = $createResult['good'];
                        $results['imagesDownloaded'] += $createResult['imageStats']['downloaded'];
                        $results['imagesFailed'] += $createResult['imageStats']['failed'];
                        $results['imported']++;

                        // РЎРѕС…СЂР°РЅСЏРµРј ID С‚РѕРІР°СЂР°
                        // Р•СЃР»Рё SKU РЅРµ РїСѓСЃС‚РѕР№, СЃРѕС…СЂР°РЅСЏРµРј РїРѕ SKU
                        if (!empty($sku)) {
                            $results['goodIds'][$sku] = $newGood->id;
                        }

                        // РўР°РєР¶Рµ РґРѕР±Р°РІР»СЏРµРј ID РїРѕ _row РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                        if (isset($goodData['_row'])) {
                            $results['goodIds'][$goodData['_row']] = $newGood->id;
                        }

                        // РЎРѕС…СЂР°РЅСЏРµРј ID РІР°СЂРёР°С†РёРё, РµСЃР»Рё РѕРЅР° Р±С‹Р»Р° СЃРѕР·РґР°РЅР°
                        if (isset($newGood->lastVariationId) && $newGood->lastVariationId) {
                            // РСЃРїРѕР»СЊР·СѓРµРј _row РєР°Рє РєР»СЋС‡ РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё (СЃР°РјС‹Р№ РЅР°РґРµР¶РЅС‹Р№ СЃРїРѕСЃРѕР±)
                            if (isset($goodData['_row'])) {
                                $results['variationIds'][$goodData['_row']] = $newGood->lastVariationId;
                            }

                            // РўР°РєР¶Рµ СЃРѕС…СЂР°РЅСЏРµРј РїРѕ РєР»СЋС‡Сѓ РЅР° РѕСЃРЅРѕРІРµ SKU + Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёРё РґР»СЏ СЃР»СѓС‡Р°РµРІ,
                            // РєРѕРіРґР° РЅРµСЃРєРѕР»СЊРєРѕ РІР°СЂРёР°С†РёР№ РёРјРµСЋС‚ РѕРґРёРЅР°РєРѕРІС‹Р№ SKU
                            if (
                                isset($goodData['sku']) && !empty($goodData['sku']) &&
                                isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                            ) {

                                $sku = trim($goodData['sku']);
                                $attributes = $goodData['variation']['attributes'];

                                // РЎРѕСЂС‚РёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РєРѕРЅСЃРёСЃС‚РµРЅС‚РЅРѕСЃС‚Рё РєР»СЋС‡Р°
                                $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                    return $attr['name'] ?? '';
                                })->map(function ($attr) {
                                    return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                })->join('|');

                                $variationKey = $sku . ':::' . $sortedAttributes;
                                $results['variationIds'][$variationKey] = $newGood->lastVariationId;
                            }
                        }

                        // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ Р·Р°РіСЂСѓР·РєРё
                        $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                        $loadItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'supplier' => ($newGood->supplier ?? ($goodData['supplier_name'] ?? ''))];
                    }
                } catch (\Exception $e) {
                    // РџСЂРѕРІРµСЂСЏРµРј, РЅРµ СЏРІР»СЏРµС‚СЃСЏ Р»Рё СЌС‚Рѕ РѕС€РёР±РєРѕР№ РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ РїСЂРё СЃРѕР·РґР°РЅРёРё С‚РѕРІР°СЂР° СЃ РІР°СЂРёР°С†РёРµР№
                    $isDuplicateError = strpos($e->getMessage(), 'Duplicate entry') !== false ||
                        strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                        strpos($e->getMessage(), 'duplicate') !== false ||
                        $e->getCode() == 23000;

                    // Р•СЃР»Рё РµСЃС‚СЊ РІР°СЂРёР°С†РёСЏ Рё РѕС€РёР±РєР° РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ - СЌС‚Рѕ Р·РЅР°С‡РёС‚ С‚РѕРІР°СЂ СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
                    // РќСѓР¶РЅРѕ РЅР°Р№С‚Рё РµРіРѕ Рё РѕР±СЂР°Р±РѕС‚Р°С‚СЊ РІР°СЂРёР°С†РёСЋ
                    if ($hasVariation && $isDuplicateError && !$existingGood) {
                        // РџСЂРѕР±СѓРµРј РЅР°Р№С‚Рё С‚РѕРІР°СЂ Р±РѕР»РµРµ С‚С‰Р°С‚РµР»СЊРЅРѕ
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
                            // РўРѕРІР°СЂ РЅР°Р№РґРµРЅ - РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј С‚РѕР»СЊРєРѕ РІР°СЂРёР°С†РёСЋ
                            try {
                                // РџРµСЂРµРґР°РµРј nameTrimSymbol РІ goodData РґР»СЏ РѕР±СЂРµР·РєРё РЅР°Р·РІР°РЅРёСЏ С‚РѕРІР°СЂР°
                                if (!isset($goodData['_nameTrimSymbol'])) {
                                    $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
                                }
                                $variationId = $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields);
                                $results['updated']++; // РЎС‡РёС‚Р°РµРј РєР°Рє РѕР±РЅРѕРІР»РµРЅРёРµ (РґРѕР±Р°РІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё)

                                // РЎРѕС…СЂР°РЅСЏРµРј ID С‚РѕРІР°СЂР°
                                if (!empty($sku)) {
                                    $results['goodIds'][$sku] = $existingGood->id;
                                }

                                if (isset($goodData['_row'])) {
                                    $results['goodIds'][$goodData['_row']] = $existingGood->id;
                                }

                                // РЎРѕС…СЂР°РЅСЏРµРј ID РІР°СЂРёР°С†РёРё РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                                if ($variationId) {
                                    if (isset($goodData['_row'])) {
                                        $results['variationIds'][$goodData['_row']] = $variationId;
                                    }

                                    // РўР°РєР¶Рµ СЃРѕС…СЂР°РЅСЏРµРј РїРѕ РєР»СЋС‡Сѓ РЅР° РѕСЃРЅРѕРІРµ SKU + Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёРё РґР»СЏ СЃР»СѓС‡Р°РµРІ,
                                    // РєРѕРіРґР° РЅРµСЃРєРѕР»СЊРєРѕ РІР°СЂРёР°С†РёР№ РёРјРµСЋС‚ РѕРґРёРЅР°РєРѕРІС‹Р№ SKU
                                    if (
                                        isset($goodData['sku']) && !empty($goodData['sku']) &&
                                        isset($goodData['variation']) && isset($goodData['variation']['attributes']) &&
                                        is_array($goodData['variation']['attributes']) && count($goodData['variation']['attributes']) > 0
                                    ) {

                                        $sku = trim($goodData['sku']);
                                        $attributes = $goodData['variation']['attributes'];

                                        // РЎРѕСЂС‚РёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РєРѕРЅСЃРёСЃС‚РµРЅС‚РЅРѕСЃС‚Рё РєР»СЋС‡Р°
                                        $sortedAttributes = collect($attributes)->sortBy(function ($attr) {
                                            return $attr['name'] ?? '';
                                        })->map(function ($attr) {
                                            return ($attr['name'] ?? '') . ':' . ($attr['value'] ?? '');
                                        })->join('|');

                                        $variationKey = $sku . ':::' . $sortedAttributes;
                                        $results['variationIds'][$variationKey] = $variationId;
                                    }
                                }

                                // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
                                $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                                $updateItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'good_id' => $existingGood->id, 'supplier' => ($existingGood->supplier ?? '')];

                                // РџСЂРѕРїСѓСЃРєР°РµРј РѕР±СЂР°Р±РѕС‚РєСѓ РѕС€РёР±РєРё, С‚Р°Рє РєР°Рє РІР°СЂРёР°С†РёСЏ СѓСЃРїРµС€РЅРѕ РѕР±СЂР°Р±РѕС‚Р°РЅР°
                                continue;
                            } catch (\Exception $variationError) {
                                // Р•СЃР»Рё РѕР±СЂР°Р±РѕС‚РєР° РІР°СЂРёР°С†РёРё С‚РѕР¶Рµ РЅРµ СѓРґР°Р»Р°СЃСЊ - Р»РѕРіРёСЂСѓРµРј РѕС€РёР±РєСѓ
                                $results['failed']++;
                                $results['errors'][] = [
                                    'row' => $count,
                                    'sku' => $sku,
                                    'error' => 'РўРѕРІР°СЂ РЅР°Р№РґРµРЅ, РЅРѕ РЅРµ СѓРґР°Р»РѕСЃСЊ РѕР±СЂР°Р±РѕС‚Р°С‚СЊ РІР°СЂРёР°С†РёСЋ: ' . $variationError->getMessage(),
                                ];

                                $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
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

                    // Р”РѕР±Р°РІР»СЏРµРј РІ РіСЂСѓРїРїСѓ РґР»СЏ РѕС€РёР±РѕРє
                    $sheet = $goodData['_sheet'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ';
                    $errorItems[] = ['count' => $count, 'sku' => $sku, 'name' => $name, 'sheet' => $sheet, 'error' => $e->getMessage()];
                }
            }

            DB::commit();

            // РџР°РєРµС‚РЅРѕРµ Р»РѕРіРёСЂРѕРІР°РЅРёРµ РїРѕСЃР»Рµ СѓСЃРїРµС€РЅРѕРіРѕ РєРѕРјРјРёС‚Р°

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
                'message' => 'РРјРїРѕСЂС‚ Р·Р°РІРµСЂС€РµРЅ',
                'results' => $results,
                // 'backup_id' => $backupId // РІСЂРµРјРµРЅРЅРѕ РѕС‚РєР»СЋС‡РµРЅРѕ
            ];

            // Р”РѕР±Р°РІР»СЏРµРј СЃС‚Р°С‚РёСЃС‚РёРєСѓ РѕР±РЅСѓР»РµРЅРёСЏ, РµСЃР»Рё РѕРЅР° Р±С‹Р»Р° РІС‹РїРѕР»РЅРµРЅР°
            if ($resetStats !== null) {
                $resetFieldsList = isset($resetStats['reset_fields']) ? implode(', ', $resetStats['reset_fields']) : '';
                $response['reset_stats'] = [
                    'supplier_name' => $resetStats['supplier_name'],
                    'updated_goods' => $resetStats['updated_goods'],
                    'updated_variations' => $resetStats['updated_variations'],
                    'reset_fields' => $resetStats['reset_fields'] ?? [],
                    'message' => "РћР±РЅСѓР»РµРЅС‹ РѕСЃС‚Р°С‚РєРё {$resetStats['updated_goods']} С‚РѕРІР°СЂРѕРІ Рё {$resetStats['updated_variations']} РІР°СЂРёР°С†РёР№ РїРѕСЃС‚Р°РІС‰РёРєР° '{$resetStats['supplier_name']}'",
                ];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            DB::rollBack();

            // Р›РѕРіРёСЂСѓРµРј РѕР±С‰СѓСЋ РѕС€РёР±РєСѓ
            $this->importLogService->logGeneralError('РћР±С‰Р°СЏ РѕС€РёР±РєР° РёРјРїРѕСЂС‚Р°: ' . $e->getMessage());

            // Р›РѕРіРёСЂСѓРµРј РѕС€РёР±РєРё Р·Р°РіСЂСѓР·РєРё С„Р°Р№Р»РѕРІ РѕС‚РґРµР»СЊРЅРѕ
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
                'message' => 'РћС€РёР±РєР° РїСЂРё РёРјРїРѕСЂС‚Рµ: ' . $e->getMessage(),
                'results' => $results,
            ], 500);
        }
    }

    /**
     * РћР±СЂРµР·Р°РµС‚ РЅР°Р·РІР°РЅРёРµ С‚РѕРІР°СЂР°, РµСЃР»Рё РІ РЅРµРј РЅР°Р№РґРµРЅ СѓРєР°Р·Р°РЅРЅС‹Р№ СЃРёРјРІРѕР» РѕР±СЂРµР·РєРё
     *
     * @param  ShopGood  $good  РўРѕРІР°СЂ РґР»СЏ РѕР±СЂРµР·РєРё РЅР°Р·РІР°РЅРёСЏ
     * @param  string|null  $nameTrimSymbol  РЎРёРјРІРѕР» РѕР±СЂРµР·РєРё
     * @param  array  $immutableFields  РџРѕР»СЏ, РєРѕС‚РѕСЂС‹Рµ РЅРµР»СЊР·СЏ РёР·РјРµРЅСЏС‚СЊ
     * @param  string|null  $nameFromData  РќР°Р·РІР°РЅРёРµ РёР· РґР°РЅРЅС‹С… РёРјРїРѕСЂС‚Р° (РµСЃР»Рё РѕС‚Р»РёС‡Р°РµС‚СЃСЏ РѕС‚ РЅР°Р·РІР°РЅРёСЏ РІ Р‘Р”)
     * @return bool true РµСЃР»Рё РѕР±СЂРµР·РєР° Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР°, false РµСЃР»Рё РЅРµС‚
     */
    private function trimGoodName($good, $nameTrimSymbol = null, $immutableFields = [], $nameFromData = null)
    {
        // Р•СЃР»Рё СЃРёРјРІРѕР» РѕР±СЂРµР·РєРё РЅРµ СѓРєР°Р·Р°РЅ РёР»Рё С‚РѕРІР°СЂ РЅРµ РЅР°Р№РґРµРЅ - РЅРёС‡РµРіРѕ РЅРµ РґРµР»Р°РµРј
        if (empty($nameTrimSymbol) || empty(trim($nameTrimSymbol)) || !$good || !$good->id) {
            return false;
        }

        // Р•СЃР»Рё РЅР°Р·РІР°РЅРёРµ РІ immutableFields - РЅРµ РѕР±СЂРµР·Р°РµРј
        if (in_array('name', $immutableFields)) {
            return false;
        }

        // РљР РРўРР§РќРћ: РџСЂРѕРІРµСЂСЏРµРј РЅР°Р·РІР°РЅРёРµ РІ Р‘Р”, РїРѕС‚РѕРјСѓ С‡С‚Рѕ РЅР° С„СЂРѕРЅС‚РµРЅРґРµ РЅР°Р·РІР°РЅРёРµ СѓР¶Рµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РѕР±СЂРµР·Р°РЅРѕ
        // Р•СЃР»Рё СЃРёРјРІРѕР» РѕР±СЂРµР·РєРё СѓРєР°Р·Р°РЅ, РІСЃРµРіРґР° РїСЂРѕРІРµСЂСЏРµРј РЅР°Р·РІР°РЅРёРµ РІ Р‘Р” РЅР° РЅР°Р»РёС‡РёРµ СЃРёРјРІРѕР»Р°
        $nameInDb = $good->name ?? '';
        $nameToCheck = !empty($nameInDb) && !empty(trim($nameInDb)) ? trim($nameInDb) : null;

        // Р•СЃР»Рё РЅР°Р·РІР°РЅРёРµ РІ Р‘Р” РїСѓСЃС‚РѕРµ, РЅРѕ РµСЃС‚СЊ РЅР°Р·РІР°РЅРёРµ РёР· РґР°РЅРЅС‹С… РёРјРїРѕСЂС‚Р° - РёСЃРїРѕР»СЊР·СѓРµРј РµРіРѕ
        if (empty($nameToCheck) && !empty($nameFromData) && !empty(trim($nameFromData))) {
            $nameToCheck = trim($nameFromData);
        }

        // Р•СЃР»Рё РЅР°Р·РІР°РЅРёРµ РїСѓСЃС‚РѕРµ - РЅРёС‡РµРіРѕ РЅРµ РґРµР»Р°РµРј
        if (empty($nameToCheck)) {
            return false;
        }

        $trimSymbol = trim($nameTrimSymbol);

        // РџСЂРѕРІРµСЂСЏРµРј РЅР°Р»РёС‡РёРµ СЃРёРјРІРѕР»Р° РѕР±СЂРµР·РєРё РІ РЅР°Р·РІР°РЅРёРё
        $trimIndex = strpos($nameToCheck, $trimSymbol);

        if ($trimIndex === false) {
            // РЎРёРјРІРѕР» РЅРµ РЅР°Р№РґРµРЅ - РѕР±СЂРµР·РєР° РЅРµ С‚СЂРµР±СѓРµС‚СЃСЏ
            return false;
        }

        // РЎРёРјРІРѕР» РЅР°Р№РґРµРЅ - РѕР±СЂРµР·Р°РµРј РЅР°Р·РІР°РЅРёРµ
        $trimmedName = trim(substr($nameToCheck, 0, $trimIndex));

        // Р•СЃР»Рё РїРѕСЃР»Рµ РѕР±СЂРµР·РєРё РЅР°Р·РІР°РЅРёРµ РїСѓСЃС‚РѕРµ - РЅРµ РѕР±РЅРѕРІР»СЏРµРј
        if (empty($trimmedName)) {
            return false;
        }

        // РћР±РЅРѕРІР»СЏРµРј РЅР°Р·РІР°РЅРёРµ С‚РѕРІР°СЂР° С‡РµСЂРµР· РїСЂСЏРјРѕРµ SQL-РѕР±РЅРѕРІР»РµРЅРёРµ
        // Р­С‚Рѕ РіР°СЂР°РЅС‚РёСЂСѓРµС‚ РѕР±РЅРѕРІР»РµРЅРёРµ РЅРµР·Р°РІРёСЃРёРјРѕ РѕС‚ СѓСЃР»РѕРІРёР№
        try {
            DB::table('shop_goods')
                ->where('id', $good->id)
                ->update(['name' => $trimmedName]);

            // РЎРёРЅС…СЂРѕРЅРёР·РёСЂСѓРµРј РјРѕРґРµР»СЊ СЃ Р±Р°Р·РѕР№ РґР°РЅРЅС‹С…
            $good->name = $trimmedName;
            $good->refresh();

            return true;
        } catch (\Exception $e) {
            \Log::error('РћС€РёР±РєР° РїСЂРё РѕР±СЂРµР·РєРµ РЅР°Р·РІР°РЅРёСЏ С‚РѕРІР°СЂР°', [
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
        // Р•СЃР»Рё SKU РїСѓСЃС‚РѕР№, СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј null РІРјРµСЃС‚Рѕ РїСѓСЃС‚РѕР№ СЃС‚СЂРѕРєРё
        $good->sku = (isset($goodData['sku']) && !empty($goodData['sku'])) ? $goodData['sku'] : null;

        // РџСЂРёРјРµРЅСЏРµРј РѕР±СЂРµР·РєСѓ РЅР°Р·РІР°РЅРёСЏ РїСЂРё СЃРѕР·РґР°РЅРёРё РЅРѕРІРѕРіРѕ С‚РѕРІР°СЂР°, РµСЃР»Рё СѓРєР°Р·Р°РЅ СЃРёРјРІРѕР» РѕР±СЂРµР·РєРё
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
        // РСЃРїРѕР»СЊР·СѓРµРј slug РёР· РґР°РЅРЅС‹С…, РµСЃР»Рё РѕРЅ РµСЃС‚СЊ Рё РЅРµ РїСѓСЃС‚РѕР№, РёРЅР°С‡Рµ РіРµРЅРµСЂРёСЂСѓРµРј Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё
        if (isset($goodData['slug']) && !empty($goodData['slug']) && trim($goodData['slug']) !== '') {
            $good->slug = trim($goodData['slug']);
        } else {
            $good->slug = $this->generateSlug($nameToSet, $goodData['sku'] ?? null);
        }
        $good->description = $goodData['description'] ?? null;
        $good->short_description = $goodData['short_description'] ?? null;

        // Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј РІСЂРµРјРµРЅРЅС‹Рµ Р·РЅР°С‡РµРЅРёСЏ С†РµРЅ Рё РѕСЃС‚Р°С‚РєРѕРІ РїРµСЂРµРґ СЃРѕС…СЂР°РЅРµРЅРёРµРј
        // РџРѕСЃР»Рµ РѕР±СЂР°Р±РѕС‚РєРё РІР°СЂРёР°С†РёР№ Р·РЅР°С‡РµРЅРёСЏ Р±СѓРґСѓС‚ РѕР±РЅРѕРІР»РµРЅС‹
        if (!isset($goodData['variation']) || !is_array($goodData['variation'])) {
            // РџСЂРёРјРµРЅСЏРµРј РјРѕРґРёС„РёРєР°С†РёСЋ С†РµРЅС‹
            $priceModification = $goodData['price_modification'] ?? null;
            $rawPrice = $goodData['price'] ?? null;

            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ С†РµРЅР° РїРµСЂРµРґР°РЅР° Рё РЅРµ РїСѓСЃС‚Р°СЏ
            if ($rawPrice === null || $rawPrice === '' || $rawPrice === 0) {
                // Р›РѕРіРёСЂСѓРµРј РїСЂРµРґСѓРїСЂРµР¶РґРµРЅРёРµ, РµСЃР»Рё С†РµРЅР° РЅРµ СѓСЃС‚Р°РЅРѕРІР»РµРЅР° РґР»СЏ С‚РѕРІР°СЂР° Р±РµР· РІР°СЂРёР°С†РёР№
                \Log::warning('РўРѕРІР°СЂ СЃРѕР·РґР°РµС‚СЃСЏ Р±РµР· РІР°СЂРёР°С†РёР№ СЃ РїСѓСЃС‚РѕР№ С†РµРЅРѕР№', [
                    'good_name' => $goodData['name'] ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ',
                    'good_sku' => $goodData['sku'] ?? 'РЅРµС‚ SKU',
                    'price_in_data' => $rawPrice,
                    'has_variation_field' => isset($goodData['variation']),
                    'variation_is_array' => is_array($goodData['variation'] ?? null),
                ]);
            }

            $good->price = $this->applyPriceModification($rawPrice ?? 0, $priceModification['regular'] ?? null);
            // РџСЂРёРјРµРЅСЏРµРј РјРѕРґРёС„РёРєР°С†РёСЋ Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅС‹ (РґР°Р¶Рµ РµСЃР»Рё sale_price РЅРµ РїРµСЂРµРґР°РЅР° РІ С„Р°Р№Р»Рµ, РЅРѕ РµСЃС‚СЊ РјРѕРґРёС„РёРєР°С†РёСЏ)
            if (isset($priceModification) && isset($priceModification['sale'])) {
                $good->sale_price = $this->applySalePriceModification($goodData, $priceModification);
            } else {
                $good->sale_price = $goodData['sale_price'] ?? null;
            }
            // РЈСЃС‚Р°РЅР°РІР»РёРІР°РµРј РѕСЃС‚Р°С‚РєРё РёР· РґР°РЅРЅС‹С… РёРјРїРѕСЂС‚Р°
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
            // Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј РІСЂРµРјРµРЅРЅС‹Рµ Р·РЅР°С‡РµРЅРёСЏ (Р±СѓРґСѓС‚ РѕР±РЅРѕРІР»РµРЅС‹ РїРѕСЃР»Рµ СЃРѕР·РґР°РЅРёСЏ РІР°СЂРёР°С†РёРё)
            $good->price = 0; // Р’СЂРµРјРµРЅРЅРѕРµ Р·РЅР°С‡РµРЅРёРµ, Р±СѓРґРµС‚ РѕР±РЅРѕРІР»РµРЅРѕ РїРѕСЃР»Рµ РѕР±СЂР°Р±РѕС‚РєРё РІР°СЂРёР°С†РёРё
            $good->sale_price = null;

            // РћСЃС‚Р°С‚РєРё РќР• СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј - РѕРЅРё Р±СѓРґСѓС‚ РІР·СЏС‚С‹ РёР· РІР°СЂРёР°С†РёР№ РёР»Рё РѕСЃС‚Р°РІР»РµРЅС‹ Р±РµР· РёР·РјРµРЅРµРЅРёР№
        }

        $good->weight = $goodData['weight'] ?? 0;
        $good->width = $goodData['width'] ?? 0;
        $good->height = $goodData['height'] ?? 0;
        // РџРѕРґРґРµСЂР¶РёРІР°РµРј Рё depth, Рё length РґР»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё
        $good->depth = $goodData['depth'] ?? $goodData['length'] ?? 0;
        $good->is_active = $goodData['is_active'] ?? true;
        $good->is_featured = $goodData['is_featured'] ?? false;
        $good->meta_title = $goodData['meta_title'] ?? null;
        $good->meta_description = $goodData['meta_description'] ?? null;

        // РЈСЃС‚Р°РЅР°РІР»РёРІР°РµРј РїРѕСЃС‚Р°РІС‰РёРєР° (С‚РµРєСЃС‚РѕРІРѕРµ РїРѕР»Рµ), РµСЃР»Рё РїРµСЂРµРґР°РЅ РІ Р·Р°РїСЂРѕСЃРµ
        // Р СЃРѕР·РґР°РµРј Р·Р°РїРёСЃСЊ РІ С‚Р°Р±Р»РёС†Рµ shop_suppliers, РµСЃР»Рё РµС‘ РЅРµС‚
        if (isset($goodData['supplier_name']) && $goodData['supplier_name'] !== null && trim($goodData['supplier_name']) !== '') {
            $supplierName = trim($goodData['supplier_name']);
            $good->supplier = $supplierName;

            // РќР°С…РѕРґРёРј РёР»Рё СЃРѕР·РґР°РµРј РїРѕСЃС‚Р°РІС‰РёРєР° РІ С‚Р°Р±Р»РёС†Рµ shop_suppliers
            $supplier = ShopSupplier::firstOrCreate(
                ['name' => $supplierName],
                [
                    'slug' => \Illuminate\Support\Str::slug($supplierName),
                    'is_active' => true,
                    'sort_order' => 0,
                ]
            );

        }

        $good->save();

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё
        // Р’РђР–РќРћ: РєР°С‚РµРіРѕСЂРёРё СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅС‹ РІ applyCategoryAndBrandIds, РіРґРµ:
        // - РљР°С‚РµРіРѕСЂРёРё РёР· С„Р°Р№Р»Р° РЅР°Р№РґРµРЅС‹/СЃРѕР·РґР°РЅС‹ Рё РїСЂРµРѕР±СЂР°Р·РѕРІР°РЅС‹ РІ ID
        // - РљР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РїСЂРёРјРµРЅРµРЅР°, РµСЃР»Рё РЅСѓР¶РЅРѕ
        // Р—РґРµСЃСЊ РјС‹ РїСЂРѕСЃС‚Рѕ РёР·РІР»РµРєР°РµРј СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅРЅС‹Рµ РєР°С‚РµРіРѕСЂРёРё
        $categoryIds = [];

        if (isset($goodData['category']) && !empty($goodData['category'])) {
            // РћРґРёРЅРѕС‡РЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ - Р·РЅР°С‡РµРЅРёРµ СѓР¶Рµ РґРѕР»Р¶РЅРѕ Р±С‹С‚СЊ ID РїРѕСЃР»Рµ applyCategoryAndBrandIds
            $categoryIds = [(int) $goodData['category']];
        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
            // РњРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рµ РєР°С‚РµРіРѕСЂРёРё - Р·РЅР°С‡РµРЅРёСЏ СѓР¶Рµ РґРѕР»Р¶РЅС‹ Р±С‹С‚СЊ ID РїРѕСЃР»Рµ applyCategoryAndBrandIds
            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                return $id > 0;
            });
        }

        // РЎРёРЅС…СЂРѕРЅРёР·РёСЂСѓРµРј РєР°С‚РµРіРѕСЂРёРё (СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅРЅС‹Рµ, РІРєР»СЋС‡Р°СЏ РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ, РµСЃР»Рё РѕРЅР° Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР°)
        if (!empty($categoryIds)) {
            $good->categories()->sync($categoryIds);
        } else {
            // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚, РѕС‚РІСЏР·С‹РІР°РµРј РІСЃРµ РєР°С‚РµРіРѕСЂРёРё
            $good->categories()->sync([]);
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј Р±СЂРµРЅРґС‹
        if (isset($goodData['brand']) && is_numeric($goodData['brand'])) {
            // РћРґРёРЅРѕС‡РЅС‹Р№ Р±СЂРµРЅРґ РїРѕ ID
            $good->brands()->sync([$goodData['brand']]);
        } elseif (isset($goodData['brands']) && is_array($goodData['brands'])) {
            // РњРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рµ Р±СЂРµРЅРґС‹ - ID СѓР¶Рµ РїСЂРёРјРµРЅРµРЅС‹ РІ applyCategoryAndBrandIds
            $brandIds = array_filter($goodData['brands'], 'is_numeric');
            $good->brands()->sync($brandIds);
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР° Р”Рћ РёР·РѕР±СЂР°Р¶РµРЅРёР№: РїСЂРё СЃРѕР·РґР°РЅРёРё СЃ РІР°СЂРёР°С†РёРµР№ РєР°СЂС‚РёРЅРєРё РІРµС€Р°РµРј РЅР° РІР°СЂРёР°С†РёСЋ
        $variationId = null;
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            if (!isset($goodData['_nameTrimSymbol'])) {
                $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
            }
            $variationId = $this->processVariation($good, $goodData['variation'], $goodData, $supplierStockFields);

            if ($variationId) {
                // РљР РРўРР§РќРћ: Р‘РµСЂРµРј С†РµРЅСѓ РёР· РІР°СЂРёР°С†РёРё, РЅРѕ РµСЃР»Рё РµС‘ РЅРµС‚ - РїСЂРѕР±СѓРµРј РёР· goodData['price']
                // Р­С‚Рѕ РЅСѓР¶РЅРѕ, РµСЃР»Рё С†РµРЅР° РЅРµ Р±С‹Р»Р° РїРµСЂРµРґР°РЅР° РІ variation.price, РЅРѕ РµСЃС‚СЊ РІ РѕСЃРЅРѕРІРЅРѕРј С‚РѕРІР°СЂРµ
                $variationPrice = $goodData['variation']['price'] ?? null;
                if ($variationPrice === null || $variationPrice === '' || $variationPrice === 0) {
                    // Р•СЃР»Рё С†РµРЅР° РІР°СЂРёР°С†РёРё РЅРµ СѓСЃС‚Р°РЅРѕРІР»РµРЅР°, Р±РµСЂРµРј РёР· goodData['price']
                    $variationPrice = $goodData['price'] ?? null;
                }
                // Р•СЃР»Рё Рё С‚Р°Рј РЅРµС‚ - РёСЃРїРѕР»СЊР·СѓРµРј С†РµРЅСѓ РёР· СЃРѕР·РґР°РЅРЅРѕР№ РІР°СЂРёР°С†РёРё (РµСЃР»Рё РѕРЅР° Р±С‹Р»Р° СѓСЃС‚Р°РЅРѕРІР»РµРЅР° РїСЂРё СЃРѕР·РґР°РЅРёРё)
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
                // РљР РРўРР§РќРћ: Р•СЃР»Рё РІР°СЂРёР°С†РёСЏ РЅРµ Р±С‹Р»Р° СЃРѕР·РґР°РЅР°, РЅРѕ С‚РѕРІР°СЂ СЃРѕР·РґР°РЅ СЃ hasVariation=true,
                // СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј С†РµРЅСѓ РёР· goodData['price'], С‡С‚РѕР±С‹ РЅРµ РѕСЃС‚Р°РІР»СЏС‚СЊ С†РµРЅСѓ 0
                // Р­С‚Рѕ РјРѕР¶РµС‚ РїСЂРѕРёР·РѕР№С‚Рё, РµСЃР»Рё РІР°СЂРёР°С†РёСЏ РЅРµ СЃРѕР·РґР°Р»Р°СЃСЊ РёР·-Р·Р° РѕС€РёР±РєРё РёР»Рё РѕС‚СЃСѓС‚СЃС‚РІРёСЏ Р°С‚СЂРёР±СѓС‚РѕРІ
                $priceModification = $goodData['price_modification'] ?? null;
                $rawPrice = $goodData['price'] ?? null;
                if ($rawPrice !== null && $rawPrice !== '' && $rawPrice !== 0) {
                    $good->price = $this->applyPriceModification($rawPrice, $priceModification['regular'] ?? null);
                    \Log::warning('Р’Р°СЂРёР°С†РёСЏ РЅРµ СЃРѕР·РґР°РЅР°, РЅРѕ С‚РѕРІР°СЂ СЃРѕР·РґР°РЅ СЃ hasVariation=true. РЈСЃС‚Р°РЅРѕРІР»РµРЅР° С†РµРЅР° РёР· goodData[price]', [
                        'good_id' => $good->id,
                        'good_name' => $good->name,
                        'good_sku' => $good->sku,
                        'price_set' => $good->price,
                    ]);
                } else {
                    \Log::warning('Р’Р°СЂРёР°С†РёСЏ РЅРµ СЃРѕР·РґР°РЅР°, Рё С†РµРЅР° РІ goodData РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚. РўРѕРІР°СЂ СЃРѕР·РґР°РЅ СЃ С†РµРЅРѕР№ 0', [
                        'good_id' => $good->id,
                        'good_name' => $good->name,
                        'good_sku' => $good->sku,
                    ]);
                }
            }
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ: РїСЂРё СЃРѕР·РґР°РЅРёРё СЃ РІР°СЂРёР°С†РёРµР№ вЂ” Рє РІР°СЂРёР°С†РёРё, РёРЅР°С‡Рµ Рє С‚РѕРІР°СЂСѓ
        if (isset($goodData['images']) && is_array($goodData['images'])) {
            $variationForImages = $variationId ? ShopGoodVariation::find($variationId) : null;
            $imageStats = $this->processImages($good, $goodData['images'], $variationForImages, false, $naming);
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј СЃРІРѕР№СЃС‚РІР° С‚РѕРІР°СЂРѕРІ
        if (isset($goodData['properties']) && is_array($goodData['properties'])) {
            $this->processProperties($good, $goodData['properties']);
        }

        // РЎРѕС…СЂР°РЅСЏРµРј ID РІР°СЂРёР°С†РёРё РІ РѕР±СЉРµРєС‚Рµ С‚РѕРІР°СЂР° РґР»СЏ РїРѕСЃР»РµРґСѓСЋС‰РµРіРѕ РёСЃРїРѕР»СЊР·РѕРІР°РЅРёСЏ
        if ($variationId) {
            $good->lastVariationId = $variationId;
        }

        return ['good' => $good, 'imageStats' => $imageStats];
    }

    private function updateGood($existingGood, $goodData, $autoCreateCategories, $autoCreateBrands, $defaultCategory = null, $useDefaultCategory = false, $immutableFields = [], $searchByNameInVariations = false, $hasVariation = false, $supplierStockFields = null, $nameTrimSymbol = null, $attachImagesToVariation = null, $searchByFieldInVariations = null, $skipImagesIfExistsOnUpdate = false, $naming = 'hash')
    {
        $imageStats = ['downloaded' => 0, 'failed' => 0];

        // РћР±РЅРѕРІР»СЏРµРј РІСЃРµ РїРѕР»СЏ РёР· goodData, РєРѕС‚РѕСЂС‹Рµ РїРµСЂРµРґР°РЅС‹ РІ РёРјРїРѕСЂС‚Рµ
        // Р­С‚Рѕ РїРѕР·РІРѕР»СЏРµС‚ РѕР±РЅРѕРІР»СЏС‚СЊ РІСЃРµ РїРѕР»СЏ, РѕС‚РјРµС‡РµРЅРЅС‹Рµ РґР»СЏ РёРјРїРѕСЂС‚Р°, Р° РЅРµ С‚РѕР»СЊРєРѕ С‚Рµ, С‡С‚Рѕ РёСЃРїРѕР»СЊР·СѓСЋС‚СЃСЏ РґР»СЏ РїРѕРёСЃРєР°

        // РћР±РЅРѕРІР»СЏРµРј SKU (Р°СЂС‚РёРєСѓР») - РµСЃР»Рё РїРµСЂРµРґР°РЅ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…, РѕР±РЅРѕРІР»СЏРµРј.
        // РљР РРўРР§РќРћ: РџСЂРё РїРѕРёСЃРєРµ РІ РІР°СЂРёР°С†РёСЏС… РїРѕ Р°СЂС‚РёРєСѓР»Сѓ (SKU) Рё РЅР°Р»РёС‡РёРё РІР°СЂРёР°С†РёР№ Сѓ С‚РѕРІР°СЂР°,
        // SKU РёР· С„Р°Р№Р»Р° РѕС‚РЅРѕСЃРёС‚СЃСЏ Рє РІР°СЂРёР°С†РёРё, Р° РЅРµ Рє РѕСЃРЅРѕРІРЅРѕРјСѓ С‚РѕРІР°СЂСѓ. РќРµ РѕР±РЅРѕРІР»СЏРµРј SKU РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°,
        // С‡С‚РѕР±С‹ РёР·Р±РµР¶Р°С‚СЊ РєРѕРЅС„Р»РёРєС‚Р° СЃ СѓРЅРёРєР°Р»СЊРЅС‹Рј РёРЅРґРµРєСЃРѕРј (SKU СѓР¶Рµ СѓСЃС‚Р°РЅРѕРІР»РµРЅ РІ РІР°СЂРёР°С†РёРё).
        // РќРµ В«РІРѕСЃСЃС‚Р°РЅР°РІР»РёРІР°РµРјВ» Р°СЂС‚РёРєСѓР»: РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РІ Р‘Р” РѕРЅ РїСѓСЃС‚РѕР№, Р° РІ С„Р°Р№Р»Рµ вЂ” РЅРµРїСѓСЃС‚РѕР№,
        // РЅРµ РїРµСЂРµР·Р°РїРёСЃС‹РІР°РµРј (РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ РјРѕРі РЅР°РјРµСЂРµРЅРЅРѕ РѕС‡РёСЃС‚РёС‚СЊ; Р·РЅР°С‡РµРЅРёРµ РІ С„Р°Р№Р»Рµ РјРѕРіР»Рѕ
        // РѕСЃС‚Р°С‚СЊСЃСЏ РѕС‚ РІР°СЂРёР°С†РёРё/СЃС‚Р°СЂРѕРіРѕ Р·РЅР°С‡РµРЅРёСЏ).
        if (isset($goodData['sku']) && !in_array('sku', $immutableFields)) {
            // РџСЂРѕРїСѓСЃРєР°РµРј РѕР±РЅРѕРІР»РµРЅРёРµ SKU РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°, РµСЃР»Рё РЅР°Р№РґРµРЅ С‚РѕРІР°СЂ СЃ РІР°СЂРёР°С†РёСЏРјРё РїРѕ Р°СЂС‚РёРєСѓР»Сѓ
            // (РІ СЌС‚РѕРј СЃР»СѓС‡Р°Рµ SKU РѕС‚РЅРѕСЃРёС‚СЃСЏ Рє РІР°СЂРёР°С†РёРё, Р° РЅРµ Рє РѕСЃРЅРѕРІРЅРѕРјСѓ С‚РѕРІР°СЂСѓ)
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

        // РљР РРўРР§РќРћ: РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РЅР°Р·РІР°РЅРёРµ. РџСЂРё РїРѕРёСЃРєРµ РІ РІР°СЂРёР°С†РёСЏС… РїРѕ Р°СЂС‚РёРєСѓР»Сѓ (SKU) РїРѕР»Рµ name РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
        // РЅРµ Р·Р°С‚СЂР°РіРёРІР°РµРј С‚РѕР»СЊРєРѕ Сѓ С‚РѕРІР°СЂРѕРІ РЎ РІР°СЂРёР°С†РёСЏРјРё. РЈ С‚РѕРІР°СЂРѕРІ Р±РµР· РІР°СЂРёР°С†РёР№ РёРјСЏ РѕР±РЅРѕРІР»СЏРµС‚СЃСЏ.
        if (!($searchByNameInVariations && $searchByFieldInVariations === 'sku' && $existingGood->variations()->exists())) {
            if (isset($goodData['name']) && !empty(trim($goodData['name']))) {
                $nameFromData = $this->normalizeText($goodData['name']);
                // РџСЂСЏРјРѕРµ РѕР±РЅРѕРІР»РµРЅРёРµ РёРјРµРЅРё РёР· С„Р°Р№Р»Р° (РµСЃР»Рё name РЅРµ РІ В«РќРµ РёР·РјРµРЅСЏС‚СЊ РїРѕР»СЏВ»)
                if (!in_array('name', $immutableFields)) {
                    $existingGood->name = $nameFromData;
                }
                // РџСЂРёРјРµРЅСЏРµРј РѕР±СЂРµР·РєСѓ С‡РµСЂРµР· trimGoodName, РµСЃР»Рё РІ РЅР°Р·РІР°РЅРёРё РµСЃС‚СЊ СЃРёРјРІРѕР» РѕР±СЂРµР·РєРё
                $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, $nameFromData);
                unset($goodData['name']);
            } else {
                // Р•СЃР»Рё РЅР°Р·РІР°РЅРёРµ РЅРµ РїРµСЂРµРґР°РЅРѕ РІ РґР°РЅРЅС‹С…, РЅРѕ РµСЃС‚СЊ СЃРёРјРІРѕР» РѕР±СЂРµР·РєРё вЂ” РїСЂРѕРІРµСЂСЏРµРј РЅР°Р·РІР°РЅРёРµ РІ Р‘Р”
                if (!empty($nameTrimSymbol) && !empty(trim($nameTrimSymbol))) {
                    $this->trimGoodName($existingGood, $nameTrimSymbol, $immutableFields, null);
                }
            }
        }
        // РћР±РЅРѕРІР»СЏРµРј slug С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅ СЏРІРЅРѕ РїРµСЂРµРґР°РЅ РІ РґР°РЅРЅС‹С… (РІС‹Р±СЂР°РЅ РІ РјР°РїРїРёРЅРіРµ) Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        // РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµР№ Р·Р°РїРёСЃРё РЅРµ СЃРѕР·РґР°РµРј slug Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё
        if (isset($goodData['slug']) && !in_array('slug', $immutableFields)) {
            if (!empty($goodData['slug']) && trim($goodData['slug']) !== '') {
                $existingGood->slug = trim($goodData['slug']);
            }
            // Р•СЃР»Рё slug РїРµСЂРµРґР°РЅ, РЅРѕ РїСѓСЃС‚РѕР№, РЅРµ РѕР±РЅРѕРІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ slug
        }
        // Р•СЃР»Рё slug РЅРµ РїРµСЂРµРґР°РЅ РІ РґР°РЅРЅС‹С… (РЅРµ РІС‹Р±СЂР°РЅ РІ РјР°РїРїРёРЅРіРµ), РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ slug Р±РµР· РёР·РјРµРЅРµРЅРёР№

        // РћР±РЅРѕРІР»СЏРµРј РѕРїРёСЃР°РЅРёРµ С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅРѕ РїРµСЂРµРґР°РЅРѕ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['description']) && !in_array('description', $immutableFields)) {
            $existingGood->description = $goodData['description'];
        }

        // РћР±РЅРѕРІР»СЏРµРј РєРѕСЂРѕС‚РєРѕРµ РѕРїРёСЃР°РЅРёРµ С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅРѕ РїРµСЂРµРґР°РЅРѕ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['short_description']) && !in_array('short_description', $immutableFields)) {
            $existingGood->short_description = $goodData['short_description'];
        }

        // РџСЂРёРјРµРЅСЏРµРј РјРѕРґРёС„РёРєР°С†РёСЋ С†РµРЅС‹ (С‚РѕР»СЊРєРѕ РµСЃР»Рё РїРѕР»Рµ РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…)
        $priceModification = $goodData['price_modification'] ?? null;

        // Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё С†РµРЅС‹ Р±РµСЂСѓС‚СЃСЏ РёР· РІР°СЂРёР°С†РёРё
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            // РћР±РЅРѕРІР»СЏРµРј С†РµРЅС‹ РёР· РІР°СЂРёР°С†РёРё
            if (isset($goodData['variation']['price']) && !in_array('price', $immutableFields)) {
                $existingGood->price = $this->applyPriceModification($goodData['variation']['price'], $priceModification['regular'] ?? null);
            }
            if ((isset($goodData['variation']['sale_price']) || isset($priceModification)) && !in_array('sale_price', $immutableFields)) {
                $goodData['sale_price'] = $goodData['variation']['sale_price'] ?? null;
                $newSalePrice = $this->applySalePriceModification($goodData, $priceModification);
                // Р•СЃР»Рё РµСЃС‚СЊ РјРѕРґРёС„РёРєР°С†РёСЏ Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅС‹, РІСЃРµРіРґР° РїСЂРёРјРµРЅСЏРµРј СЂРµР·СѓР»СЊС‚Р°С‚ (РґР°Р¶Рµ РµСЃР»Рё null)
                if (isset($priceModification) && isset($priceModification['sale'])) {
                    $existingGood->sale_price = $newSalePrice;
                } else {
                    // Р•СЃР»Рё РјРѕРґРёС„РёРєР°С†РёРё РЅРµС‚, РёСЃРїРѕР»СЊР·СѓРµРј Р·РЅР°С‡РµРЅРёРµ РёР· С„Р°Р№Р»Р° РёР»Рё РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ
                    $existingGood->sale_price = $newSalePrice ?? $existingGood->sale_price;
                }
            }
        } else {
            // Р”Р»СЏ С‚РѕРІР°СЂРѕРІ Р±РµР· РІР°СЂРёР°С†РёР№ РѕР±РЅРѕРІР»СЏРµРј С†РµРЅС‹ РёР· goodData
            if (isset($goodData['price']) && !in_array('price', $immutableFields)) {
                $existingGood->price = $this->applyPriceModification($goodData['price'], $priceModification['regular'] ?? null);
            }
            if ((isset($goodData['sale_price']) || isset($priceModification)) && !in_array('sale_price', $immutableFields)) {
                $newSalePrice = $this->applySalePriceModification($goodData, $priceModification);
                // Р•СЃР»Рё РµСЃС‚СЊ РјРѕРґРёС„РёРєР°С†РёСЏ Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅС‹, РІСЃРµРіРґР° РїСЂРёРјРµРЅСЏРµРј СЂРµР·СѓР»СЊС‚Р°С‚ (РґР°Р¶Рµ РµСЃР»Рё null)
                if (isset($priceModification) && isset($priceModification['sale'])) {
                    $existingGood->sale_price = $newSalePrice;
                } else {
                    // Р•СЃР»Рё РјРѕРґРёС„РёРєР°С†РёРё РЅРµС‚, РёСЃРїРѕР»СЊР·СѓРµРј Р·РЅР°С‡РµРЅРёРµ РёР· С„Р°Р№Р»Р° РёР»Рё РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ
                    $existingGood->sale_price = $newSalePrice ?? $existingGood->sale_price;
                }
            }
        }

        // РћР±РЅРѕРІР»СЏРµРј РґРµРјРїРёРЅРі С†РµРЅСѓ, РµСЃР»Рё РїРµСЂРµРґР°РЅР° Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['demping_price']) && !in_array('demping_price', $immutableFields)) {
            $existingGood->demping_price = $goodData['demping_price'];
        }
        if (isset($goodData['show_demping']) && !in_array('show_demping', $immutableFields)) {
            $existingGood->show_demping = $goodData['show_demping'];
        }

        // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅРё РїРµСЂРµРґР°РЅС‹ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            // Р”Р»СЏ С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё РѕСЃС‚Р°С‚РєРё Р±РµСЂСѓС‚СЃСЏ РёР· РІР°СЂРёР°С†РёРё
            if ((isset($goodData['variation']['stock_quantity']) || isset($goodData['variation']['stock'])) && !in_array('stock_quantity', $immutableFields)) {
                $stockValue = $goodData['variation']['stock_quantity'] ?? $goodData['variation']['stock'] ?? $existingGood->stock_quantity;
                $existingGood->stock_quantity = is_numeric($stockValue) ? (float) $stockValue : $existingGood->stock_quantity;
            }

            if (!in_array('remote_stock_quantity', $immutableFields)) {
                $remoteValue = $goodData['variation']['remote_stock_quantity'] ?? null;
                // РћР±РЅРѕРІР»СЏРµРј С‚РѕР»СЊРєРѕ РµСЃР»Рё Р·РЅР°С‡РµРЅРёРµ РЅРµ РїСѓСЃС‚РѕРµ
                if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                    $existingGood->remote_stock_quantity = is_numeric($remoteValue) ? (string) $remoteValue : $remoteValue;
                }
            }

            if (!in_array('fast_remote_stock_quantity', $immutableFields)) {
                $fastRemoteValue = $goodData['variation']['fast_remote_stock_quantity'] ?? null;
                // РћР±РЅРѕРІР»СЏРµРј С‚РѕР»СЊРєРѕ РµСЃР»Рё Р·РЅР°С‡РµРЅРёРµ РЅРµ РїСѓСЃС‚РѕРµ
                if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                    $existingGood->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string) $fastRemoteValue : $fastRemoteValue;
                }
            }
        } else {
            // Р”Р»СЏ С‚РѕРІР°СЂРѕРІ Р±РµР· РІР°СЂРёР°С†РёР№ РѕСЃС‚Р°С‚РєРё Р±РµСЂСѓС‚СЃСЏ РёР· goodData СЃ РєРѕРЅРІРµСЂС‚Р°С†РёРµР№ С‚РёРїРѕРІ
            // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё РёР· РґР°РЅРЅС‹С… РёРјРїРѕСЂС‚Р°, РµСЃР»Рё РѕРЅРё РїСЂРёСЃСѓС‚СЃС‚РІСѓСЋС‚
            if ((isset($goodData['stock_quantity']) || isset($goodData['stock'])) && !in_array('stock_quantity', $immutableFields)) {
                $stockValue = $goodData['stock_quantity'] ?? $goodData['stock'] ?? $existingGood->stock_quantity;
                $existingGood->stock_quantity = is_numeric($stockValue) ? (float) $stockValue : $existingGood->stock_quantity;
            }

            if (isset($goodData['remote_stock_quantity']) && !in_array('remote_stock_quantity', $immutableFields)) {
                $remoteValue = $goodData['remote_stock_quantity'];
                // РћР±РЅРѕРІР»СЏРµРј С‚РѕР»СЊРєРѕ РµСЃР»Рё Р·РЅР°С‡РµРЅРёРµ РЅРµ РїСѓСЃС‚РѕРµ
                if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                    $existingGood->remote_stock_quantity = is_numeric($remoteValue) ? (string) $remoteValue : $remoteValue;
                }
            }

            if (isset($goodData['fast_remote_stock_quantity']) && !in_array('fast_remote_stock_quantity', $immutableFields)) {
                $fastRemoteValue = $goodData['fast_remote_stock_quantity'];
                // РћР±РЅРѕРІР»СЏРµРј С‚РѕР»СЊРєРѕ РµСЃР»Рё Р·РЅР°С‡РµРЅРёРµ РЅРµ РїСѓСЃС‚РѕРµ
                if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                    $existingGood->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string) $fastRemoteValue : $fastRemoteValue;
                }
            }
        }

        // РћР±РЅРѕРІР»СЏРµРј СЂР°Р·РјРµСЂС‹ Рё РІРµСЃ, РµСЃР»Рё РїРµСЂРµРґР°РЅС‹ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['weight']) && !in_array('weight', $immutableFields)) {
            $existingGood->weight = $goodData['weight'];
        }
        if (isset($goodData['width']) && !in_array('width', $immutableFields)) {
            $existingGood->width = $goodData['width'];
        }
        if (isset($goodData['height']) && !in_array('height', $immutableFields)) {
            $existingGood->height = $goodData['height'];
        }
        // РџРѕРґРґРµСЂР¶РёРІР°РµРј Рё depth, Рё length РґР»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё
        if (isset($goodData['depth']) && !in_array('depth', $immutableFields)) {
            $existingGood->depth = $goodData['depth'];
        } elseif (isset($goodData['length']) && !in_array('depth', $immutableFields)) {
            $existingGood->depth = $goodData['length'];
        }

        // РћР±РЅРѕРІР»СЏРµРј С„Р»Р°РіРё, РµСЃР»Рё РїРµСЂРµРґР°РЅС‹ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
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

        // РћР±РЅРѕРІР»СЏРµРј РјРµС‚Р°-С‚РµРіРё, РµСЃР»Рё РїРµСЂРµРґР°РЅС‹ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['meta_title']) && !in_array('meta_title', $immutableFields)) {
            $existingGood->meta_title = $goodData['meta_title'];
        }
        if (isset($goodData['meta_description']) && !in_array('meta_description', $immutableFields)) {
            $existingGood->meta_description = $goodData['meta_description'];
        }

        // РћР±РЅРѕРІР»СЏРµРј label_id, РµСЃР»Рё РїРµСЂРµРґР°РЅ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['label_id']) && !in_array('label_id', $immutableFields)) {
            $existingGood->label_id = $goodData['label_id'];
        }

        // РћР±РЅРѕРІР»СЏРµРј sort_order, РµСЃР»Рё РїРµСЂРµРґР°РЅ Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['sort_order']) && !in_array('sort_order', $immutableFields)) {
            $existingGood->sort_order = $goodData['sort_order'];
        }

        // РћР±РЅРѕРІР»СЏРµРј supplier (С‚РµРєСЃС‚РѕРІРѕРµ РїРѕР»Рµ), РµСЃР»Рё РїРµСЂРµРґР°РЅ supplier_name Рё РЅРµ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
        if (isset($goodData['supplier_name']) && !in_array('supplier', $immutableFields)) {
            $supplierName = trim($goodData['supplier_name'] ?? '');
            if ($supplierName !== '') {
                // РќР°С…РѕРґРёРј РёР»Рё СЃРѕР·РґР°РµРј РїРѕСЃС‚Р°РІС‰РёРєР° РІ С‚Р°Р±Р»РёС†Рµ shop_suppliers
                $supplier = ShopSupplier::firstOrCreate(
                    ['name' => $supplierName],
                    [
                        'slug' => \Illuminate\Support\Str::slug($supplierName),
                        'is_active' => true,
                        'sort_order' => 0,
                    ]
                );

                // РћР±РЅРѕРІР»СЏРµРј С‚РµРєСЃС‚РѕРІРѕРµ РїРѕР»Рµ supplier РІ С‚РѕРІР°СЂРµ
                $existingGood->supplier = $supplierName;

            } else {
                $existingGood->supplier = null;
            }
        }

        // РћР±РЅРѕРІР»СЏРµРј РІСЃРµ РѕСЃС‚Р°Р»СЊРЅС‹Рµ РїРѕР»СЏ РёР· goodData, РєРѕС‚РѕСЂС‹Рµ РµСЃС‚СЊ РІ fillable РјРѕРґРµР»Рё
        // Р­С‚Рѕ РіР°СЂР°РЅС‚РёСЂСѓРµС‚, С‡С‚Рѕ РІСЃРµ РїРѕР»СЏ, РѕС‚РјРµС‡РµРЅРЅС‹Рµ РґР»СЏ РёРјРїРѕСЂС‚Р°, Р±СѓРґСѓС‚ РѕР±РЅРѕРІР»РµРЅС‹
        // РСЃРїРѕР»СЊР·СѓРµРј СЃРїРёСЃРѕРє fillable РїРѕР»РµР№ РёР· РјРѕРґРµР»Рё ShopGood
        $fillableFields = $existingGood->getFillable();

        // РЎРїРёСЃРѕРє РїРѕР»РµР№, РєРѕС‚РѕСЂС‹Рµ СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅС‹ РІС‹С€Рµ (С‡С‚РѕР±С‹ РЅРµ РґСѓР±Р»РёСЂРѕРІР°С‚СЊ)
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

        // РћР±РЅРѕРІР»СЏРµРј РІСЃРµ РїРѕР»СЏ РёР· goodData, РєРѕС‚РѕСЂС‹Рµ РµСЃС‚СЊ РІ fillable Рё РЅРµ Р±С‹Р»Рё РѕР±СЂР°Р±РѕС‚Р°РЅС‹ РІС‹С€Рµ
        foreach ($fillableFields as $field) {
            // РџСЂРѕРїСѓСЃРєР°РµРј РїРѕР»СЏ, РєРѕС‚РѕСЂС‹Рµ СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅС‹ РІС‹С€Рµ РёР»Рё СЏРІР»СЏСЋС‚СЃСЏ СЃР»СѓР¶РµР±РЅС‹РјРё (РЅР°С‡РёРЅР°СЋС‚СЃСЏ СЃ _)
            if (in_array($field, $alreadyProcessed) || strpos($field, '_') === 0) {
                continue;
            }

            // РџСЂРѕРїСѓСЃРєР°РµРј РїРѕР»СЏ, РєРѕС‚РѕСЂС‹Рµ РЅР°С…РѕРґСЏС‚СЃСЏ РІ СЃРїРёСЃРєРµ РЅРµРёР·РјРµРЅСЏРµРјС‹С…
            if (in_array($field, $immutableFields)) {
                continue;
            }

            // Р’РђР–РќРћ: РџСЂРѕРїСѓСЃРєР°РµРј РїРѕР»Рµ 'name', С‚Р°Рє РєР°Рє РѕРЅРѕ СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅРѕ РІС‹С€Рµ СЃ РѕР±СЂРµР·РєРѕР№
            // Р•СЃР»Рё РѕР±РЅРѕРІРёС‚СЊ РµРіРѕ Р·РґРµСЃСЊ, РѕРЅРѕ РїРµСЂРµР·Р°РїРёС€РµС‚ РѕР±СЂРµР·Р°РЅРЅРѕРµ Р·РЅР°С‡РµРЅРёРµ РЅРµРѕР±СЂРµР·Р°РЅРЅС‹Рј
            if ($field === 'name') {
                continue;
            }

            // Р•СЃР»Рё РїРѕР»Рµ РµСЃС‚СЊ РІ goodData, РѕР±РЅРѕРІР»СЏРµРј РµРіРѕ
            if (isset($goodData[$field])) {
                $existingGood->$field = $goodData[$field];
            }
        }

        $existingGood->save();

        // Р•СЃР»Рё С‚РѕРІР°СЂ РёРјРµРµС‚ РІР°СЂРёР°С†РёРё, РЅРµ РѕР±СЂР°Р±Р°С‚С‹РІР°РµС‚СЃСЏ РєРѕРЅРєСЂРµС‚РЅР°СЏ РІР°СЂРёР°С†РёСЏ Рё Р±С‹Р»Рё РѕР±РЅРѕРІР»РµРЅС‹ РѕСЃС‚Р°С‚РєРё,
        // РѕР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё РІСЃРµС… Р°РєС‚РёРІРЅС‹С… РІР°СЂРёР°С†РёР№ РґР°РЅРЅС‹РјРё РёР· С‚РѕРІР°СЂР°
        // РџСЂРё РёРјРїРѕСЂС‚Рµ РќР• РєРѕРїРёСЂСѓРµРј РѕСЃС‚Р°С‚РєРё РјРµР¶РґСѓ С‚РѕРІР°СЂРѕРј Рё РІР°СЂРёР°С†РёСЏРјРё
        // РљР°Р¶РґС‹Р№ РѕР±СЉРµРєС‚ РѕР±РЅРѕРІР»СЏРµС‚СЃСЏ С‚РѕР»СЊРєРѕ СЃРІРѕРёРјРё РґР°РЅРЅС‹РјРё РёР· С„Р°Р№Р»Р°

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё
        // Р’РђР–РќРћ: РєР°С‚РµРіРѕСЂРёРё СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅС‹ РІ processCategoriesAndBrandsBatch, РіРґРµ:
        // - РљР°С‚РµРіРѕСЂРёРё РёР· С„Р°Р№Р»Р° РЅР°Р№РґРµРЅС‹/СЃРѕР·РґР°РЅС‹ Рё РїСЂРµРѕР±СЂР°Р·РѕРІР°РЅС‹ РІ ID
        // Р—РґРµСЃСЊ РјС‹ РёР·РІР»РµРєР°РµРј СѓР¶Рµ РѕР±СЂР°Р±РѕС‚Р°РЅРЅС‹Рµ РєР°С‚РµРіРѕСЂРёРё
        $categoryIds = [];

        if (isset($goodData['category']) && !empty($goodData['category'])) {
            // РћРґРёРЅРѕС‡РЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ - Р·РЅР°С‡РµРЅРёРµ СѓР¶Рµ РґРѕР»Р¶РЅРѕ Р±С‹С‚СЊ ID РїРѕСЃР»Рµ processCategoriesAndBrandsBatch
            $categoryIds = [(int) $goodData['category']];
        } elseif (isset($goodData['categories']) && is_array($goodData['categories'])) {
            // РњРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рµ РєР°С‚РµРіРѕСЂРёРё - Р·РЅР°С‡РµРЅРёСЏ СѓР¶Рµ РґРѕР»Р¶РЅС‹ Р±С‹С‚СЊ ID РїРѕСЃР»Рµ processCategoriesAndBrandsBatch
            $categoryIds = array_filter(array_map('intval', $goodData['categories']), function ($id) {
                return $id > 0;
            });
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё РІ С„СѓРЅРєС†РёРё updateGood
        // Р’РђР–РќРћ: РџСЂРѕРІРµСЂСЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё РџР•Р Р•Р” РїСЂРёРјРµРЅРµРЅРёРµРј РєР°С‚РµРіРѕСЂРёР№ РёР· С„Р°Р№Р»Р°
        $existingCategoryIds = $existingGood->categories()->pluck('shop_categories.id')->toArray();

        // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёРё РµСЃС‚СЊ РІ $goodData, РїСЂРѕРІРµСЂСЏРµРј, РЅРµ Р±С‹Р»Р° Р»Рё СЌС‚Рѕ РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
        if (!empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null && !empty($existingCategoryIds)) {
            $defaultCategoryId = (int) $defaultCategory;
            // Р•СЃР»Рё РµРґРёРЅСЃС‚РІРµРЅРЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ - СЌС‚Рѕ defaultCategory, Рё Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РґСЂСѓРіРёРµ РєР°С‚РµРіРѕСЂРёРё
            if (count($categoryIds) === 1 && $categoryIds[0] === $defaultCategoryId) {
                // Р’РµСЂРѕСЏС‚РЅРѕ, РєР°С‚РµРіРѕСЂРёСЏ Р±С‹Р»Р° РїСЂРёРјРµРЅРµРЅР° РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ - РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
                $categoryIds = $existingCategoryIds;
            }
        } elseif (empty($categoryIds) && $useDefaultCategory && $defaultCategory !== null) {
            // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ РІ С„Р°Р№Р»Рµ, РЅРѕ РІРєР»СЋС‡РµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
            // РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‚РѕРІР°СЂР°: РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ С‚РѕР»СЊРєРѕ РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№
            if (empty($existingCategoryIds)) {
                // РЈ С‚РѕРІР°СЂР° РЅРµС‚ РєР°С‚РµРіРѕСЂРёР№ - РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                $categoryIds = [(int) $defaultCategory];
            } else {
                // РЈ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РєР°С‚РµРіРѕСЂРёРё - РѕСЃС‚Р°РІР»СЏРµРј РёС… Р±РµР· РёР·РјРµРЅРµРЅРёР№
                $categoryIds = $existingCategoryIds;
            }
        }

        // РЎРёРЅС…СЂРѕРЅРёР·РёСЂСѓРµРј РєР°С‚РµРіРѕСЂРёРё С‚РѕР»СЊРєРѕ РµСЃР»Рё РѕРЅРё Р±С‹Р»Рё СѓРєР°Р·Р°РЅС‹ РІ С„Р°Р№Р»Рµ РёР»Рё РїСЂРёРјРµРЅРµРЅР° РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
        // Р’РђР–РќРћ: РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё С‚РѕРІР°СЂР°, РµСЃР»Рё РєР°С‚РµРіРѕСЂРёР№ РЅРµС‚ РІ С„Р°Р№Р»Рµ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ,
        // РЅРµ РѕС‚РІСЏР·С‹РІР°РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё - РѕСЃС‚Р°РІР»СЏРµРј РёС… Р±РµР· РёР·РјРµРЅРµРЅРёР№
        if (!empty($categoryIds)) {
            $existingGood->categories()->sync($categoryIds);
        }
        // Р•СЃР»Рё $categoryIds РїСѓСЃС‚РѕР№ Рё РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РЅРµ РїСЂРёРјРµРЅСЏР»Р°СЃСЊ - РЅРµ РІС‹Р·С‹РІР°РµРј sync, РѕСЃС‚Р°РІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј Р±СЂРµРЅРґС‹
        if (isset($goodData['brand']) && is_numeric($goodData['brand'])) {
            // РћРґРёРЅРѕС‡РЅС‹Р№ Р±СЂРµРЅРґ РїРѕ ID
            $existingGood->brands()->sync([$goodData['brand']]);
        } elseif (isset($goodData['brands']) && is_array($goodData['brands'])) {
            // РњРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рµ Р±СЂРµРЅРґС‹ - ID СѓР¶Рµ РїСЂРёРјРµРЅРµРЅС‹ РІ applyCategoryAndBrandIds
            $brandIds = array_filter($goodData['brands'], 'is_numeric');
            $existingGood->brands()->sync($brandIds);
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ. Р•СЃР»Рё РїРµСЂРµРґР°РЅ $attachImagesToVariation (РїСЂРё В«РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС…В»),
        // РїСЂРёРІСЏР·С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ Рє РІР°СЂРёР°С†РёРё, Р° РЅРµ Рє РѕСЃРЅРѕРІРЅРѕРјСѓ С‚РѕРІР°СЂСѓ.
        if (isset($goodData['images']) && is_array($goodData['images'])) {
            $imageStats = $this->processImages($existingGood, $goodData['images'], $attachImagesToVariation, $skipImagesIfExistsOnUpdate, $naming);
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј СЃРІРѕР№СЃС‚РІР° С‚РѕРІР°СЂРѕРІ
        if (isset($goodData['properties']) && is_array($goodData['properties'])) {
            $this->processProperties($existingGood, $goodData['properties']);
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР°
        if (isset($goodData['variation']) && is_array($goodData['variation'])) {
            // РџРµСЂРµРґР°РµРј nameTrimSymbol РІ goodData РґР»СЏ РѕР±СЂРµР·РєРё РЅР°Р·РІР°РЅРёСЏ С‚РѕРІР°СЂР°
            if (!isset($goodData['_nameTrimSymbol'])) {
                $goodData['_nameTrimSymbol'] = $nameTrimSymbol;
            }
            $this->processVariation($existingGood, $goodData['variation'], $goodData, $supplierStockFields);
        }

        // Р•СЃР»Рё С‚РѕРІР°СЂ РёРјРµРµС‚ РІР°СЂРёР°С†РёРё, РѕР±РЅРѕРІР»СЏРµРј РІСЃРµ РµРіРѕ РІР°СЂРёР°С†РёРё РґР°РЅРЅС‹РјРё РёР· С‚РѕРІР°СЂР°
        // Р­С‚Рѕ РїСЂРёРјРµРЅСЏРµС‚СЃСЏ РєРѕРіРґР° С‚РѕРІР°СЂ РЅР°Р№РґРµРЅ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ Рё РѕР±РЅРѕРІР»СЏРµС‚СЃСЏ, РЅРѕ РІР°СЂРёР°С†РёРё С‚РѕР¶Рµ РґРѕР»Р¶РЅС‹ РїРѕР»СѓС‡РёС‚СЊ РѕР±РЅРѕРІР»РµРЅРЅС‹Рµ РґР°РЅРЅС‹Рµ
        // РќР• РїСЂРёРјРµРЅСЏРµС‚СЃСЏ РєРѕРіРґР° РІРєР»СЋС‡РµРЅ РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… - РІ СЌС‚РѕРј СЃР»СѓС‡Р°Рµ РѕР±РЅРѕРІР»СЏРµС‚СЃСЏ РєРѕРЅРєСЂРµС‚РЅР°СЏ РІР°СЂРёР°С†РёСЏ
        if (!$hasVariation && !$searchByNameInVariations && $existingGood->variations()->count() > 0) {
            $variations = $existingGood->variations;

            foreach ($variations as $variation) {
                // РћР±РЅРѕРІР»СЏРµРј С†РµРЅС‹ РІР°СЂРёР°С†РёРё РґР°РЅРЅС‹РјРё РёР· С‚РѕРІР°СЂР° (РµСЃР»Рё РѕРЅРё РїРµСЂРµРґР°РЅС‹)
                if (isset($goodData['price']) && !in_array('price', $immutableFields)) {
                    $variation->price = $this->applyPriceModification($goodData['price'], $priceModification['regular'] ?? null);
                }

                // РћР±РЅРѕРІР»СЏРµРј Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ
                if ((isset($goodData['sale_price']) || isset($priceModification)) && !in_array('sale_price', $immutableFields)) {
                    if (isset($priceModification) && isset($priceModification['sale'])) {
                        $variation->sale_price = $this->applySalePriceModification($goodData, $priceModification);
                    } else {
                        $variation->sale_price = $goodData['sale_price'] ?? $variation->sale_price;
                    }
                }

                // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёРё РґР°РЅРЅС‹РјРё РёР· С‚РѕРІР°СЂР° СЃ РєРѕРЅРІРµСЂС‚Р°С†РёРµР№ С‚РёРїРѕРІ
                if (isset($goodData['stock_quantity']) && !in_array('stock_quantity', $immutableFields)) {
                    $stockValue = $goodData['stock_quantity'];
                    // РћР±РЅРѕРІР»СЏРµРј С‚РѕР»СЊРєРѕ РµСЃР»Рё Р·РЅР°С‡РµРЅРёРµ РЅРµ РїСѓСЃС‚РѕРµ
                    if ($stockValue !== null && $stockValue !== '' && trim($stockValue) !== '') {
                        $variation->stock_quantity = is_numeric($stockValue) ? (float) $stockValue : 0;
                    }
                }
                if (!in_array('remote_stock_quantity', $immutableFields)) {
                    $remoteValue = $goodData['remote_stock_quantity'] ?? null;
                    // РћР±РЅРѕРІР»СЏРµРј С‚РѕР»СЊРєРѕ РµСЃР»Рё Р·РЅР°С‡РµРЅРёРµ РЅРµ РїСѓСЃС‚РѕРµ
                    if ($remoteValue !== null && $remoteValue !== '' && trim($remoteValue) !== '') {
                        $variation->remote_stock_quantity = is_numeric($remoteValue) ? (string) $remoteValue : $remoteValue;
                    }
                }
                if (!in_array('fast_remote_stock_quantity', $immutableFields)) {
                    $fastRemoteValue = $goodData['fast_remote_stock_quantity'] ?? null;
                    // РћР±РЅРѕРІР»СЏРµРј С‚РѕР»СЊРєРѕ РµСЃР»Рё Р·РЅР°С‡РµРЅРёРµ РЅРµ РїСѓСЃС‚РѕРµ
                    if ($fastRemoteValue !== null && $fastRemoteValue !== '' && trim($fastRemoteValue) !== '') {
                        $variation->fast_remote_stock_quantity = is_numeric($fastRemoteValue) ? (string) $fastRemoteValue : $fastRemoteValue;
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
                // Р­С‚Рѕ ID РєР°С‚РµРіРѕСЂРёРё
                $categoryIds[] = (int) $category;
            } else {
                // Р­С‚Рѕ РЅР°Р·РІР°РЅРёРµ РєР°С‚РµРіРѕСЂРёРё
                $categorySlug = Str::slug($category);
                $existingCategory = ShopCategory::where('name', $category)
                    ->orWhere('slug', $categorySlug)
                    ->first();

                if ($existingCategory) {
                    $categoryIds[] = $existingCategory->id;
                } elseif ($autoCreate) {
                    // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° РЅР° СЃР»СѓС‡Р°Р№, РµСЃР»Рё slug СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
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
                // Р­С‚Рѕ ID Р±СЂРµРЅРґР°
                $brandIds[] = (int) $brand;
            } else {
                // Р­С‚Рѕ РЅР°Р·РІР°РЅРёРµ Р±СЂРµРЅРґР°
                $brandSlug = Str::slug($brand);
                $existingBrand = ShopBrand::where('name', $brand)
                    ->orWhere('slug', $brandSlug)
                    ->first();

                if ($existingBrand) {
                    $brandIds[] = $existingBrand->id;
                } elseif ($autoCreate) {
                    // РџСЂРѕРІРµСЂСЏРµРј, РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ Р»Рё СѓР¶Рµ Р±СЂРµРЅРґ СЃ С‚Р°РєРёРј slug
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
     * РћР±СЂР°Р±Р°С‚С‹РІР°РµС‚ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ: Р·Р°РіСЂСѓР·РєР° Рё РїСЂРёРІСЏР·РєР° Рє С‚РѕРІР°СЂСѓ РёР»Рё РІР°СЂРёР°С†РёРё.
     *
     * @param  object  $good  РўРѕРІР°СЂ (РґР»СЏ РѕС‡РёСЃС‚РєРё Excel-РёР·РѕР±СЂР°Р¶РµРЅРёР№ Рё РєРѕРіРґР° $variation РїСѓСЃС‚Р°СЏ)
     * @param  array  $images  РњР°СЃСЃРёРІ URL РёР»Рё РїСѓС‚РµР№ Рє РёР·РѕР±СЂР°Р¶РµРЅРёСЏРј
     * @param  object|null  $variation  Р’Р°СЂРёР°С†РёСЏ: РµСЃР»Рё РїРµСЂРµРґР°РЅР°, РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РїСЂРёРІСЏР·С‹РІР°СЋС‚СЃСЏ Рє РІР°СЂРёР°С†РёРё, Р° РЅРµ Рє С‚РѕРІР°СЂСѓ
     */
    private function processImages($good, $images, $variation = null, $skipIfHasImagesOnUpdate = false, $naming = 'hash')
    {
        \Log::error('BulkImport: processImages method called', ['naming' => $naming, 'images_count' => count($images)]);
        $stats = ['downloaded' => 0, 'failed' => 0];
        $attachToVariation = $variation !== null;

        $filteredImages = [];
        foreach ($images as $imageUrl) {
            if (!empty($imageUrl)) {
                $filteredImages[] = $imageUrl;
            }
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
                            $this->importLogService->logImageSkipped($firstImageUrl, 'РџСЂРѕРїСѓС‰РµРЅРѕ РїРѕ РЅР°СЃС‚СЂРѕР№РєРµ: Сѓ РІР°СЂРёР°С†РёРё СѓР¶Рµ РµСЃС‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ', $good->sku ?? null);
                        }

                        return $stats;
                    }
                } else {
                    $hasImages = \App\Models\ShopGoodImage::where('good_id', $good->id)->exists();
                    if ($hasImages) {
                        $firstImageUrl = $filteredImages[0] ?? null;
                        if ($firstImageUrl !== null) {
                            $this->importLogService->logImageSkipped($firstImageUrl, 'РџСЂРѕРїСѓС‰РµРЅРѕ РїРѕ РЅР°СЃС‚СЂРѕР№РєРµ: Сѓ С‚РѕРІР°СЂР° СѓР¶Рµ РµСЃС‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ', $good->sku ?? null);
                        }

                        return $stats;
                    }
                }
            } catch (\Exception $e) {
                // Р’ СЃР»СѓС‡Р°Рµ РѕС€РёР±РєРё РїСЂРѕРІРµСЂРєРё РїСЂРѕРґРѕР»Р¶Р°РµРј РѕР±С‹С‡РЅСѓСЋ РѕР±СЂР°Р±РѕС‚РєСѓ, С‡С‚РѕР±С‹ РЅРµ Р±Р»РѕРєРёСЂРѕРІР°С‚СЊ РёРјРїРѕСЂС‚
            }
        }

        // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё СЃСЂРµРґРё РЅРѕРІС‹С… РёР·РѕР±СЂР°Р¶РµРЅРёР№ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РёР· Excel
        $hasExcelImages = false;
        foreach ($images as $imageUrl) {
            if (str_starts_with($imageUrl, '/images/shop/goods/excel_')) {
                $hasExcelImages = true;
                break;
            }
        }

        // Р•СЃР»Рё РµСЃС‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РёР· Excel, СѓРґР°Р»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РёР· Excel
        if ($hasExcelImages) {
            if ($attachToVariation) {
                // РџСЂРёРІСЏР·РєР° Рє РІР°СЂРёР°С†РёРё: СѓРґР°Р»СЏРµРј С‚РѕР»СЊРєРѕ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ СЌС‚РѕР№ РІР°СЂРёР°С†РёРё РёР· Excel
                $existingExcelImages = ShopGoodImage::where('variation_id', $variation->id)
                    ->where('file_path', 'like', '/images/shop/goods/excel_%')
                    ->get();
            } else {
                // РџСЂРёРІСЏР·РєР° Рє С‚РѕРІР°СЂСѓ: СѓРґР°Р»СЏРµРј РўРћР›Р¬РљРћ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ СЃР°РјРѕРіРѕ С‚РѕРІР°СЂР° РёР· Excel (РќР• С‚СЂРѕРіР°РµРј РІР°СЂРёР°С†РёРё)
                $existingExcelImages = ShopGoodImage::where('good_id', $good->id)
                    ->whereNull('variation_id')
                    ->where('file_path', 'like', '/images/shop/goods/excel_%')
                    ->get();
            }

            foreach ($existingExcelImages as $existingImage) {
                $filePath = str_replace('/images/', '', $existingImage->file_path);
                $fullPath = frontend_public_path('images/' . $filePath);
                if (file_exists($fullPath)) {
                    unlink($fullPath); // РЈРґР°Р»СЏРµРј С„Р°Р№Р»
                }
                $existingImage->delete(); // РЈРґР°Р»СЏРµРј Р·Р°РїРёСЃСЊ
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

        foreach ($filteredImages as $imageUrl) {
            if (str_starts_with($imageUrl, '/')) {
                ShopGoodImage::create($imageRecord($imageUrl));
                $stats['downloaded']++;
            } else {
                try {
                    $downloadResponse = $this->downloadImage($imageUrl, $naming);
                    if ($downloadResponse && isset($downloadResponse['data']['path'])) {
                        if (isset($downloadResponse['data']['skipped']) && $downloadResponse['data']['skipped']) {
                            $this->importLogService->logImageSkipped($imageUrl, 'Р¤Р°Р№Р» СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ РЅР° РґРёСЃРєРµ', $good->sku ?? null);
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
        \Log::error('BulkImport: downloadImage started', ['naming' => $naming]);
        \Log::info('BulkImport: Sending download request', [
            'imageUrl' => $imageUrl,
            'naming' => $naming
        ]);

        // РСЃРїРѕР»СЊР·СѓРµРј С‚РѕС‚ Р¶Рµ РјРµС‚РѕРґ, С‡С‚Рѕ Рё РІ ShopGoodsController
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

        if ($response->successful()) {
            \Log::info('BulkImport: Download successful', ['data' => $response->json()]);
        } else {
            \Log::error('BulkImport: Download failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
        }

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
     * РџР°РєРµС‚РЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° РєР°С‚РµРіРѕСЂРёР№ Рё Р±СЂРµРЅРґРѕРІ
     */
    private function processCategoriesAndBrandsBatch(&$goods, $autoCreateCategories, $autoCreateBrands, $defaultCategory = null, $useDefaultCategory = false)
    {
        // РЎРѕР±РёСЂР°РµРј РІСЃРµ СѓРЅРёРєР°Р»СЊРЅС‹Рµ РЅР°Р·РІР°РЅРёСЏ РєР°С‚РµРіРѕСЂРёР№ Рё Р±СЂРµРЅРґРѕРІ
        $allCategories = collect();
        $allBrands = collect();

        foreach ($goods as $good) {
            // РЎРѕР±РёСЂР°РµРј РєР°С‚РµРіРѕСЂРёРё
            if (isset($good['category']) && is_string($good['category']) && !empty($good['category'])) {
                // РЎРЅР°С‡Р°Р»Р° СЂР°Р·РґРµР»СЏРµРј РїРѕ Р·Р°РїСЏС‚С‹Рј (РµСЃР»Рё РµСЃС‚СЊ РЅРµСЃРєРѕР»СЊРєРѕ РєР°С‚РµРіРѕСЂРёР№)
                $categoryStrings = array_map('trim', explode(',', $good['category']));

                foreach ($categoryStrings as $categoryString) {
                    if (empty($categoryString)) {
                        continue;
                    }

                    // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ СЃРѕРґРµСЂР¶РёС‚ РёРµСЂР°СЂС…РёСЋ (>), СЂР°Р·Р±РёРІР°РµРј Рё СЃРѕР±РёСЂР°РµРј РІСЃРµ С‡Р°СЃС‚Рё РѕС‚РґРµР»СЊРЅРѕ
                    // РќРћ СЃР°РјСѓ СЃС‚СЂРѕРєСѓ СЃ > РЅРµ РґРѕР±Р°РІР»СЏРµРј РІ СЃРїРёСЃРѕРє РґР»СЏ СЃРѕР·РґР°РЅРёСЏ
                    if (strpos($categoryString, '>') !== false) {
                        $categoryParts = array_map('trim', explode('>', $categoryString));
                        // Р”РѕР±Р°РІР»СЏРµРј С‚РѕР»СЊРєРѕ РѕС‚РґРµР»СЊРЅС‹Рµ С‡Р°СЃС‚Рё, РЅРµ СЃС‚СЂРѕРєСѓ С†РµР»РёРєРѕРј
                        foreach ($categoryParts as $part) {
                            if (!empty($part)) {
                                $allCategories->push($part);
                            }
                        }
                    } else {
                        // РћР±С‹С‡РЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ Р±РµР· РёРµСЂР°СЂС…РёРё
                        $allCategories->push($categoryString);
                    }
                }
            } elseif (isset($good['categories'])) {
                if (is_string($good['categories'])) {
                    // РЎРЅР°С‡Р°Р»Р° СЂР°Р·РґРµР»СЏРµРј РїРѕ СЂР°Р·РґРµР»РёС‚РµР»СЏРј (Р·Р°РїСЏС‚Р°СЏ, С‚РѕС‡РєР° СЃ Р·Р°РїСЏС‚РѕР№ Рё С‚.Рґ.)
                    $categoryNames = $this->parseDelimitedString($good['categories']);

                    foreach ($categoryNames as $categoryName) {
                        if (empty($categoryName)) {
                            continue;
                        }

                        // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ СЃРѕРґРµСЂР¶РёС‚ РёРµСЂР°СЂС…РёСЋ (>), СЂР°Р·Р±РёРІР°РµРј Рё СЃРѕР±РёСЂР°РµРј РІСЃРµ С‡Р°СЃС‚Рё РѕС‚РґРµР»СЊРЅРѕ
                        // РќРћ СЃР°РјСѓ СЃС‚СЂРѕРєСѓ СЃ > РЅРµ РґРѕР±Р°РІР»СЏРµРј РІ СЃРїРёСЃРѕРє РґР»СЏ СЃРѕР·РґР°РЅРёСЏ
                        if (strpos($categoryName, '>') !== false) {
                            $categoryParts = array_map('trim', explode('>', $categoryName));
                            // Р”РѕР±Р°РІР»СЏРµРј С‚РѕР»СЊРєРѕ РѕС‚РґРµР»СЊРЅС‹Рµ С‡Р°СЃС‚Рё, РЅРµ СЃС‚СЂРѕРєСѓ С†РµР»РёРєРѕРј
                            foreach ($categoryParts as $part) {
                                if (!empty($part)) {
                                    $allCategories->push($part);
                                }
                            }
                        } else {
                            // РћР±С‹С‡РЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ Р±РµР· РёРµСЂР°СЂС…РёРё
                            $allCategories->push($categoryName);
                        }
                    }
                } elseif (is_array($good['categories'])) {
                    foreach ($good['categories'] as $categoryName) {
                        if (empty($categoryName) || !is_string($categoryName)) {
                            continue;
                        }

                        // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ СЃРѕРґРµСЂР¶РёС‚ РёРµСЂР°СЂС…РёСЋ (>), СЂР°Р·Р±РёРІР°РµРј Рё СЃРѕР±РёСЂР°РµРј РІСЃРµ С‡Р°СЃС‚Рё РѕС‚РґРµР»СЊРЅРѕ
                        // РќРћ СЃР°РјСѓ СЃС‚СЂРѕРєСѓ СЃ > РЅРµ РґРѕР±Р°РІР»СЏРµРј РІ СЃРїРёСЃРѕРє РґР»СЏ СЃРѕР·РґР°РЅРёСЏ
                        if (strpos($categoryName, '>') !== false) {
                            $categoryParts = array_map('trim', explode('>', $categoryName));
                            // Р”РѕР±Р°РІР»СЏРµРј С‚РѕР»СЊРєРѕ РѕС‚РґРµР»СЊРЅС‹Рµ С‡Р°СЃС‚Рё, РЅРµ СЃС‚СЂРѕРєСѓ С†РµР»РёРєРѕРј
                            foreach ($categoryParts as $part) {
                                if (!empty($part)) {
                                    $allCategories->push($part);
                                }
                            }
                        } else {
                            // РћР±С‹С‡РЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ Р±РµР· РёРµСЂР°СЂС…РёРё
                            $allCategories->push($categoryName);
                        }
                    }
                }
            }

            // РЎРѕР±РёСЂР°РµРј Р±СЂРµРЅРґС‹
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

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё РїР°РєРµС‚РЅРѕ
        if ($allCategories->isNotEmpty()) {
            $this->processCategoriesBatch($allCategories, $autoCreateCategories);
        }

        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј Р±СЂРµРЅРґС‹ РїР°РєРµС‚РЅРѕ
        if ($allBrands->isNotEmpty()) {
            $this->processBrandsBatch($allBrands, $autoCreateBrands);
        }

        // РџСЂРёРјРµРЅСЏРµРј РЅР°Р№РґРµРЅРЅС‹Рµ ID Рє С‚РѕРІР°СЂР°Рј
        $this->applyCategoryAndBrandIds($goods, $autoCreateCategories, $autoCreateBrands, $defaultCategory, $useDefaultCategory);
    }

    /**
     * РџР°РєРµС‚РЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° РєР°С‚РµРіРѕСЂРёР№
     */
    private function processCategoriesBatch($allCategories, $autoCreate)
    {
        // Р—Р°РіСЂСѓР¶Р°РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё РїРѕ РёРјРµРЅРё Рё slug
        $existingCategories = ShopCategory::where(function ($query) use ($allCategories) {
            $query->whereIn('name', $allCategories->toArray())
                ->orWhereIn('slug', $allCategories->map(function ($name) {
                    return Str::slug($name);
                })->toArray());
        })->get();

        $categoryMap = collect();

        // РЎРѕР·РґР°РµРј РєР°СЂС‚Сѓ РїРѕ РёРјРµРЅРё Рё slug
        foreach ($existingCategories as $category) {
            $categoryMap[strtolower($category->name)] = $category->id;
            $categoryMap[strtolower($category->slug)] = $category->id;
        }

        // РЎРѕР·РґР°РµРј РЅРµРґРѕСЃС‚Р°СЋС‰РёРµ РєР°С‚РµРіРѕСЂРёРё
        // Р’РђР–РќРћ: Р¤РёР»СЊС‚СЂСѓРµРј РєР°С‚РµРіРѕСЂРёРё, РєРѕС‚РѕСЂС‹Рµ СЃРѕРґРµСЂР¶Р°С‚ > - С‚Р°РєРёРµ РєР°С‚РµРіРѕСЂРёРё РЅРµ РґРѕР»Р¶РЅС‹ СЃРѕР·РґР°РІР°С‚СЊСЃСЏ РЅР°РїСЂСЏРјСѓСЋ
        // РћРЅРё РѕР±СЂР°Р±Р°С‚С‹РІР°СЋС‚СЃСЏ С‡РµСЂРµР· processCategoryHierarchy РІ applyCategoryAndBrandIds
        $createdCategories = [];
        if ($autoCreate) {
            $categoriesToCreate = $allCategories->filter(function ($name) use ($categoryMap) {
                // РџСЂРѕРїСѓСЃРєР°РµРј РєР°С‚РµРіРѕСЂРёРё, РєРѕС‚РѕСЂС‹Рµ СЃРѕРґРµСЂР¶Р°С‚ > - СЌС‚Рѕ РёРµСЂР°СЂС…РёСЏ, Р° РЅРµ РЅР°Р·РІР°РЅРёРµ РєР°С‚РµРіРѕСЂРёРё
                if (strpos($name, '>') !== false) {
                    return false;
                }

                $nameLower = strtolower($name);
                $slugLower = strtolower(Str::slug($name));

                return !$categoryMap->has($nameLower) && !$categoryMap->has($slugLower);
            });

            foreach ($categoriesToCreate as $categoryName) {
                // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° РЅР° СЃР»СѓС‡Р°Р№, РµСЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ РІСЃРµ РµС‰Рµ СЃРѕРґРµСЂР¶РёС‚ >
                if (strpos($categoryName, '>') !== false) {
                    continue;
                }

                $categorySlug = Str::slug($categoryName);

                // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° РЅР° СЃР»СѓС‡Р°Р№, РµСЃР»Рё slug СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
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

        // РЎРѕС…СЂР°РЅСЏРµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ СЃРѕР·РґР°РЅРЅС‹С… РєР°С‚РµРіРѕСЂРёСЏС… РІ РєСЌС€Рµ
        cache(['created_categories_' . auth()->id() => $createdCategories], 300);

        // РЎРѕС…СЂР°РЅСЏРµРј РєР°СЂС‚Сѓ РІ РєСЌС€Рµ РґР»СЏ РёСЃРїРѕР»СЊР·РѕРІР°РЅРёСЏ РІ applyCategoryAndBrandIds
        cache(['category_map_' . auth()->id() => $categoryMap], 300); // 5 РјРёРЅСѓС‚
    }

    /**
     * РџР°РєРµС‚РЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° Р±СЂРµРЅРґРѕРІ
     */
    private function processBrandsBatch($allBrands, $autoCreate)
    {
        // Р—Р°РіСЂСѓР¶Р°РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ Р±СЂРµРЅРґС‹ РїРѕ РёРјРµРЅРё Рё slug
        $existingBrands = ShopBrand::where(function ($query) use ($allBrands) {
            $query->whereIn('name', $allBrands->toArray())
                ->orWhereIn('slug', $allBrands->map(function ($name) {
                    return Str::slug($name);
                })->toArray());
        })->get();

        $brandMap = collect();

        // РЎРѕР·РґР°РµРј РєР°СЂС‚Сѓ РїРѕ РёРјРµРЅРё Рё slug
        foreach ($existingBrands as $brand) {
            $brandMap[strtolower($brand->name)] = $brand->id;
            $brandMap[strtolower($brand->slug)] = $brand->id;
        }

        // РЎРѕР·РґР°РµРј РЅРµРґРѕСЃС‚Р°СЋС‰РёРµ Р±СЂРµРЅРґС‹
        $createdBrands = [];
        if ($autoCreate) {
            $brandsToCreate = $allBrands->filter(function ($name) use ($brandMap) {
                $nameLower = strtolower($name);
                $slugLower = strtolower(Str::slug($name));

                return !$brandMap->has($nameLower) && !$brandMap->has($slugLower);
            });

            foreach ($brandsToCreate as $brandName) {
                $brandSlug = Str::slug($brandName);

                // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° РЅР° СЃР»СѓС‡Р°Р№, РµСЃР»Рё slug СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
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

        // РЎРѕС…СЂР°РЅСЏРµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ СЃРѕР·РґР°РЅРЅС‹С… Р±СЂРµРЅРґР°С… РІ РєСЌС€Рµ
        cache(['created_brands_' . auth()->id() => $createdBrands], 300);

        // РЎРѕС…СЂР°РЅСЏРµРј РєР°СЂС‚Сѓ РІ РєСЌС€Рµ РґР»СЏ РёСЃРїРѕР»СЊР·РѕРІР°РЅРёСЏ РІ applyCategoryAndBrandIds
        cache(['brand_map_' . auth()->id() => $brandMap], 300); // 5 РјРёРЅСѓС‚
    }

    /**
     * РџСЂРёРјРµРЅРµРЅРёРµ РЅР°Р№РґРµРЅРЅС‹С… ID Рє С‚РѕРІР°СЂР°Рј
     */
    private function applyCategoryAndBrandIds(&$goods, $autoCreateCategories = true, $autoCreateBrands = true, $defaultCategory = null, $useDefaultCategory = false)
    {
        $categoryMap = cache('category_map_' . auth()->id(), collect());
        // РџСЂРµРѕР±СЂР°Р·СѓРµРј РєРѕР»Р»РµРєС†РёСЋ РІ РјР°СЃСЃРёРІ РґР»СЏ СѓРґРѕР±СЃС‚РІР° СЂР°Р±РѕС‚С‹
        $categoryMapArray = $categoryMap instanceof \Illuminate\Support\Collection ? $categoryMap->toArray() : (array) $categoryMap;
        $brandMap = cache('brand_map_' . auth()->id(), collect());

        foreach ($goods as &$good) {
            // Р—Р°РїРѕРјРёРЅР°РµРј, Р±С‹Р»Р° Р»Рё РєР°С‚РµРіРѕСЂРёСЏ РІ РёСЃС…РѕРґРЅС‹С… РґР°РЅРЅС‹С… (РґРѕ РѕР±СЂР°Р±РѕС‚РєРё)
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

            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°С‚РµРіРѕСЂРёРё
            if (isset($good['category']) && !empty($good['category'])) {
                // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ - СЃС‚СЂРѕРєР° РёР»Рё С‡РёСЃР»Рѕ, РїСЂРµРѕР±СЂР°Р·СѓРµРј РІ СЃС‚СЂРѕРєСѓ РґР»СЏ РѕР±СЂР°Р±РѕС‚РєРё
                $categoryValue = (string) $good['category'];
                if (!empty($categoryValue)) {
                    // РџСЂРѕРІРµСЂСЏРµРј, СЃРѕРґРµСЂР¶РёС‚ Р»Рё СЃС‚СЂРѕРєР° СЃРёРјРІРѕР» > (РёРµСЂР°СЂС…РёСЏ РєР°С‚РµРіРѕСЂРёР№)
                    if (strpos($categoryValue, '>') !== false) {
                        // Р Р°Р·Р±РёРІР°РµРј СЃС‚СЂРѕРєСѓ РїРѕ СЃРёРјРІРѕР»Сѓ > Рё РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј РёРµСЂР°СЂС…РёСЋ
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
                        // РћР±С‹С‡РЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° РѕРґРЅРѕР№ РєР°С‚РµРіРѕСЂРёРё
                        $categoryId = $categoryMapArray[strtolower($categoryValue)] ?? null;
                        if ($categoryId) {
                            $good['category'] = $categoryId;
                        } else {
                            if ($autoCreateCategories) {
                                // РЎРѕР·РґР°РµРј РЅРѕРІСѓСЋ РєР°С‚РµРіРѕСЂРёСЋ, РµСЃР»Рё РѕРЅР° РЅРµ РЅР°Р№РґРµРЅР°
                                $categoryName = trim($categoryValue);
                                $categorySlug = \Illuminate\Support\Str::slug($categoryName);

                                // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ slug
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

                                    // Р”РѕР±Р°РІР»СЏРµРј РІ РєР°СЂС‚Сѓ
                                    $categoryMapArray[strtolower($categoryName)] = $categoryId;

                                    // РћР±РЅРѕРІР»СЏРµРј РєРµС€ РєР°СЂС‚С‹
                                    cache(['category_map_' . auth()->id() => $categoryMapArray], 300);

                                    // Р”РѕР±Р°РІР»СЏРµРј РІ СЃРїРёСЃРѕРє СЃРѕР·РґР°РЅРЅС‹С… РєР°С‚РµРіРѕСЂРёР№
                                    $createdCategories = cache('created_categories_' . auth()->id(), []);
                                    if (!in_array($categoryId, $createdCategories)) {
                                        $createdCategories[] = $categoryId;
                                        cache(['created_categories_' . auth()->id() => $createdCategories], 300);
                                    }

                                    $good['category'] = $categoryId;
                                } catch (\Exception $e) {
                                    // Р•СЃР»Рё РѕС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё, СѓРґР°Р»СЏРµРј РєР°С‚РµРіРѕСЂРёСЋ
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
                        // РџСЂРµРѕР±СЂР°Р·СѓРµРј РІ СЃС‚СЂРѕРєСѓ РЅР° РІСЃСЏРєРёР№ СЃР»СѓС‡Р°Р№
                        $categoryName = (string) $categoryName;
                        // РџСЂРѕРІРµСЂСЏРµРј, СЃРѕРґРµСЂР¶РёС‚ Р»Рё РєР°С‚РµРіРѕСЂРёСЏ РёРµСЂР°СЂС…РёСЋ
                        if (strpos($categoryName, '>') !== false) {
                            $categoryPath = array_map('trim', explode('>', $categoryName));
                            $categoryId = $this->processCategoryHierarchy($categoryPath, $categoryMapArray, $autoCreateCategories);
                        } else {
                            $categoryId = $categoryMapArray[strtolower($categoryName)] ?? null;

                            // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ РЅРµ РЅР°Р№РґРµРЅР° Рё СЂР°Р·СЂРµС€РµРЅРѕ СЃРѕР·РґР°РЅРёРµ, СЃРѕР·РґР°РµРј РµС‘
                            if (!$categoryId && $autoCreateCategories) {
                                $categoryNameTrimmed = trim($categoryName);
                                $categorySlug = \Illuminate\Support\Str::slug($categoryNameTrimmed);

                                // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ slug
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

                                    // Р”РѕР±Р°РІР»СЏРµРј РІ РєР°СЂС‚Сѓ
                                    $categoryMapArray[strtolower($categoryNameTrimmed)] = $categoryId;

                                    // РћР±РЅРѕРІР»СЏРµРј РєРµС€ РєР°СЂС‚С‹
                                    cache(['category_map_' . auth()->id() => $categoryMapArray], 300);

                                    // Р”РѕР±Р°РІР»СЏРµРј РІ СЃРїРёСЃРѕРє СЃРѕР·РґР°РЅРЅС‹С… РєР°С‚РµРіРѕСЂРёР№
                                    $createdCategories = cache('created_categories_' . auth()->id(), []);
                                    if (!in_array($categoryId, $createdCategories)) {
                                        $createdCategories[] = $categoryId;
                                        cache(['created_categories_' . auth()->id() => $createdCategories], 300);
                                    }
                                } catch (\Exception $e) {
                                    // Р•СЃР»Рё РѕС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё, РїСЂРѕРїСѓСЃРєР°РµРј РєР°С‚РµРіРѕСЂРёСЋ
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

                            // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ РЅРµ РЅР°Р№РґРµРЅР° Рё СЂР°Р·СЂРµС€РµРЅРѕ СЃРѕР·РґР°РЅРёРµ, СЃРѕР·РґР°РµРј РµС‘
                            if (!$categoryId && $autoCreateCategories) {
                                $categoryNameTrimmed = trim($category);
                                $categorySlug = \Illuminate\Support\Str::slug($categoryNameTrimmed);

                                // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ slug
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

                                    // Р”РѕР±Р°РІР»СЏРµРј РІ РєР°СЂС‚Сѓ
                                    $categoryMapArray[strtolower($categoryNameTrimmed)] = $categoryId;

                                    // РћР±РЅРѕРІР»СЏРµРј РєРµС€ РєР°СЂС‚С‹
                                    cache(['category_map_' . auth()->id() => $categoryMapArray], 300);

                                    // Р”РѕР±Р°РІР»СЏРµРј РІ СЃРїРёСЃРѕРє СЃРѕР·РґР°РЅРЅС‹С… РєР°С‚РµРіРѕСЂРёР№
                                    $createdCategories = cache('created_categories_' . auth()->id(), []);
                                    if (!in_array($categoryId, $createdCategories)) {
                                        $createdCategories[] = $categoryId;
                                        cache(['created_categories_' . auth()->id() => $createdCategories], 300);
                                    }
                                } catch (\Exception $e) {
                                    // Р•СЃР»Рё РѕС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё, РїСЂРѕРїСѓСЃРєР°РµРј РєР°С‚РµРіРѕСЂРёСЋ
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

            // РџСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РўРћР›Р¬РљРћ РµСЃР»Рё РїРѕСЃР»Рµ РѕР±СЂР°Р±РѕС‚РєРё РЅРµС‚ РІР°Р»РёРґРЅРѕР№ РєР°С‚РµРіРѕСЂРёРё
            // Р’РђР–РќРћ: РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РќР• РґРѕР»Р¶РЅР° РїСЂРёРјРµРЅСЏС‚СЊСЃСЏ, РµСЃР»Рё РІ С„Р°Р№Р»Рµ Р±С‹Р»Р° РєР°С‚РµРіРѕСЂРёСЏ Рё РѕРЅР° РЅР°Р№РґРµРЅР°/СЃРѕР·РґР°РЅР°
            if ($useDefaultCategory && $defaultCategory !== null) {
                $hasValidCategory = false;

                // РџСЂРѕРІРµСЂСЏРµРј category (РїРѕСЃР»Рµ РѕР±СЂР°Р±РѕС‚РєРё СЌС‚Рѕ РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ ID РёР»Рё null)
                if (isset($good['category'])) {
                    // Р•СЃР»Рё category - СЌС‚Рѕ С‡РёСЃР»Рѕ (ID) Рё Р±РѕР»СЊС€Рµ 0, Р·РЅР°С‡РёС‚ РєР°С‚РµРіРѕСЂРёСЏ РЅР°Р№РґРµРЅР°/СЃРѕР·РґР°РЅР° РёР· С„Р°Р№Р»Р°
                    if (is_numeric($good['category']) && (int) $good['category'] > 0) {
                        $hasValidCategory = true;
                    }
                    // Р•СЃР»Рё category - СЌС‚Рѕ СЃС‚СЂРѕРєР° (РЅРµ РѕР±СЂР°Р±РѕС‚Р°РЅР°), РїСЂРѕРІРµСЂСЏРµРј С‡С‚Рѕ РѕРЅР° РЅРµ РїСѓСЃС‚Р°СЏ
                    elseif (is_string($good['category']) && trim($good['category']) !== '') {
                        $hasValidCategory = true;
                    }
                }

                // РџСЂРѕРІРµСЂСЏРµРј РјР°СЃСЃРёРІ categories (РїРѕСЃР»Рµ РѕР±СЂР°Р±РѕС‚РєРё РґРѕР»Р¶РЅС‹ Р±С‹С‚СЊ С‚РѕР»СЊРєРѕ ID)
                if (!$hasValidCategory && isset($good['categories'])) {
                    if (is_array($good['categories'])) {
                        // Р¤РёР»СЊС‚СЂСѓРµРј С‚РѕР»СЊРєРѕ РІР°Р»РёРґРЅС‹Рµ ID (С‡РёСЃР»Р° > 0)
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

                // РџСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РўРћР›Р¬РљРћ РµСЃР»Рё:
                // - Р’ С„Р°Р№Р»Рµ РќР• Р±С‹Р»Рѕ РєР°С‚РµРіРѕСЂРёРё (hadCategoryInFile = false), РР›Р
                // - Р’ С„Р°Р№Р»Рµ Р±С‹Р»Р° РєР°С‚РµРіРѕСЂРёСЏ, РЅРѕ РѕРЅР° РЅРµ РЅР°Р№РґРµРЅР°/РЅРµ СЃРѕР·РґР°РЅР° (hasValidCategory = false Р hadCategoryInFile = true)
                // РќРћ РќР• РїСЂРёРјРµРЅСЏРµРј, РµСЃР»Рё РІ С„Р°Р№Р»Рµ Р±С‹Р»Р° РєР°С‚РµРіРѕСЂРёСЏ Рё РѕРЅР° РЅР°Р№РґРµРЅР°/СЃРѕР·РґР°РЅР° (hasValidCategory = true)
                // Р“Р»Р°РІРЅРѕРµ РїСЂР°РІРёР»Рѕ: РµСЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ РёР· С„Р°Р№Р»Р° РЅР°Р№РґРµРЅР° РёР»Рё СЃРѕР·РґР°РЅР°, РѕРЅР° РёРјРµРµС‚ РїСЂРёРѕСЂРёС‚РµС‚ РЅР°Рґ РєР°С‚РµРіРѕСЂРёРµР№ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                // Р•СЃР»Рё РєР°С‚РµРіРѕСЂРёСЏ Р±С‹Р»Р° РІ С„Р°Р№Р»Рµ, РЅРѕ РЅРµ РЅР°Р№РґРµРЅР°/РЅРµ СЃРѕР·РґР°РЅР° - РќР• РїСЂРёРјРµРЅСЏРµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
                if (!$hasValidCategory && !$hadCategoryInFile) {
                    // РРЅРёС†РёР°Р»РёР·РёСЂСѓРµРј РјР°СЃСЃРёРІ categories, РµСЃР»Рё РµРіРѕ РЅРµС‚
                    if (!isset($good['categories']) || !is_array($good['categories'])) {
                        $good['categories'] = [];
                    }

                    // РЈР±РµР¶РґР°РµРјСЃСЏ, С‡С‚Рѕ РєР°С‚РµРіРѕСЂРёСЏ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ РµС‰Рµ РЅРµ РґРѕР±Р°РІР»РµРЅР°
                    $defaultCategoryId = (int) $defaultCategory;
                    $existingIds = array_map('intval', array_filter($good['categories'], 'is_numeric'));
                    if (!in_array($defaultCategoryId, $existingIds)) {
                        $good['categories'][] = $defaultCategoryId;
                    }
                }
            }

            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј Р±СЂРµРЅРґС‹
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

        // РЎРѕС…СЂР°РЅСЏРµРј РѕР±РЅРѕРІР»РµРЅРЅСѓСЋ РєР°СЂС‚Сѓ РєР°С‚РµРіРѕСЂРёР№ РѕР±СЂР°С‚РЅРѕ РІ РєСЌС€
        cache(['category_map_' . auth()->id() => collect($categoryMapArray)], 300);
    }

    /**
     * РџР°СЂСЃРёРЅРі СЃС‚СЂРѕРє СЃ СЂР°Р·РґРµР»РёС‚РµР»СЏРјРё
     */
    private function parseDelimitedString($str)
    {
        if (empty($str)) {
            return [];
        }

        // РџРѕРґРґРµСЂР¶РёРІР°РµРј СЂР°Р·Р»РёС‡РЅС‹Рµ СЂР°Р·РґРµР»РёС‚РµР»Рё: Р·Р°РїСЏС‚Р°СЏ, С‚РѕС‡РєР° СЃ Р·Р°РїСЏС‚РѕР№, РІРµСЂС‚РёРєР°Р»СЊРЅР°СЏ С‡РµСЂС‚Р°, РїРµСЂРµРЅРѕСЃ СЃС‚СЂРѕРєРё
        return collect(preg_split('/[,;|\n\r]+/', $str))
            ->map(function ($item) {
                return trim($item);
            })
            ->filter()
            ->toArray();
    }

    /**
     * РћР±СЂР°Р±РѕС‚РєР° РёРµСЂР°СЂС…РёРё РєР°С‚РµРіРѕСЂРёР№ (РљР°С‚РµРіРѕСЂРёСЏ>РџРѕРґРєР°С‚РµРіРѕСЂРёСЏ1>РџРѕРґРєР°С‚РµРіРѕСЂРёСЏ2)
     * РЎРѕР·РґР°РµС‚ РєР°С‚РµРіРѕСЂРёРё СЃ РїСЂР°РІРёР»СЊРЅРѕР№ РёРµСЂР°СЂС…РёРµР№, РµСЃР»Рё РёС… РЅРµС‚
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

            // РџСЂРѕРїСѓСЃРєР°РµРј РєР°С‚РµРіРѕСЂРёРё, РєРѕС‚РѕСЂС‹Рµ СЃРѕРґРµСЂР¶Р°С‚ > - СЌС‚Рѕ РЅРµ РЅР°Р·РІР°РЅРёРµ РєР°С‚РµРіРѕСЂРёРё, Р° РѕС€РёР±РєР° РїР°СЂСЃРёРЅРіР°
            if (strpos($categoryName, '>') !== false) {
                continue;
            }

            // РС‰РµРј РєР°С‚РµРіРѕСЂРёСЋ РїРѕ РёРјРµРЅРё Рё СЂРѕРґРёС‚РµР»СЋ
            $categoryKey = $parentId
                ? strtolower($categoryName) . '_parent_' . $parentId
                : strtolower($categoryName);

            // РЎРЅР°С‡Р°Р»Р° РїСЂРѕРІРµСЂСЏРµРј РІ РєР°СЂС‚Рµ
            $categoryId = $categoryMapArray[$categoryKey] ?? null;

            if (!$categoryId) {
                // РС‰РµРј РІ Р±Р°Р·Рµ РґР°РЅРЅС‹С… РїРѕ РёРјРµРЅРё Рё СЂРѕРґРёС‚РµР»СЋ (РїСЂРёРѕСЂРёС‚РµС‚ - С‚РѕС‡РЅРѕРµ СЃРѕРІРїР°РґРµРЅРёРµ)
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
                    // РўР°РєР¶Рµ РґРѕР±Р°РІР»СЏРµРј РІ РєР°СЂС‚Сѓ РїРѕ РёРјРµРЅРё РґР»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё
                    $categoryMapArray[strtolower($categoryName)] = $categoryId;
                } elseif ($autoCreate) {
                    // РЎРѕР·РґР°РµРј РЅРѕРІСѓСЋ РєР°С‚РµРіРѕСЂРёСЋ
                    $categoryNameSlug = \Illuminate\Support\Str::slug($categoryName);

                    // Р•СЃР»Рё РµСЃС‚СЊ СЂРѕРґРёС‚РµР»СЊ, С„РѕСЂРјРёСЂСѓРµРј slug РєР°Рє slug_СЂРѕРґРёС‚РµР»СЏ-slug_РєР°С‚РµРіРѕСЂРёРё
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

                    // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ slug РіР»РѕР±Р°Р»СЊРЅРѕ (slug РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ СѓРЅРёРєР°Р»СЊРЅС‹Рј РІ С‚Р°Р±Р»РёС†Рµ)
                    while (ShopCategory::where('slug', $categorySlug)->exists()) {
                        // Р•СЃР»Рё slug СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚, РїСЂРѕРІРµСЂСЏРµРј, РїРѕРґС…РѕРґРёС‚ Р»Рё СЃСѓС‰РµСЃС‚РІСѓСЋС‰Р°СЏ РєР°С‚РµРіРѕСЂРёСЏ
                        $existingBySlug = ShopCategory::where('slug', $categorySlug)->first();

                        // Р•СЃР»Рё СЃСѓС‰РµСЃС‚РІСѓСЋС‰Р°СЏ РєР°С‚РµРіРѕСЂРёСЏ РёРјРµРµС‚ РїСЂР°РІРёР»СЊРЅРѕРµ РёРјСЏ Рё СЂРѕРґРёС‚РµР»СЏ - РёСЃРїРѕР»СЊР·СѓРµРј РµС‘
                        if (
                            $existingBySlug &&
                            $existingBySlug->name === $categoryName &&
                            $existingBySlug->parent_id == $parentId
                        ) {
                            $categoryId = $existingBySlug->id;
                            break;
                        }

                        // Р•СЃР»Рё РЅРµ РїРѕРґС…РѕРґРёС‚, СЃРѕР·РґР°РµРј СѓРЅРёРєР°Р»СЊРЅС‹Р№ slug СЃ СЃСѓС„С„РёРєСЃРѕРј
                        $categorySlug = $baseSlug . '-' . $slugCounter;
                        $slugCounter++;
                    }

                    // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё РїРѕРґС…РѕРґСЏС‰СѓСЋ РєР°С‚РµРіРѕСЂРёСЋ, СЃРѕР·РґР°РµРј РЅРѕРІСѓСЋ
                    if (!$categoryId) {
                        try {
                            $newCategory = ShopCategory::create([
                                'name' => $categoryName,
                                'slug' => $categorySlug,
                                'parent_id' => $parentId,
                                'is_active' => true,
                            ]);
                            $categoryId = $newCategory->id;

                            // Р”РѕР±Р°РІР»СЏРµРј РІ СЃРїРёСЃРѕРє СЃРѕР·РґР°РЅРЅС‹С… РєР°С‚РµРіРѕСЂРёР№
                            $createdCategories = cache('created_categories_' . auth()->id(), []);
                            if (!in_array($categoryId, $createdCategories)) {
                                $createdCategories[] = $categoryId;
                                cache(['created_categories_' . auth()->id() => $createdCategories], 300);
                            }
                        } catch (\Exception $e) {
                            // Р•СЃР»Рё РѕС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё (РЅР°РїСЂРёРјРµСЂ, РґСѓР±Р»РёРєР°С‚ slug), РїС‹С‚Р°РµРјСЃСЏ РЅР°Р№С‚Рё СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ

                            // РџС‹С‚Р°РµРјСЃСЏ РЅР°Р№С‚Рё РєР°С‚РµРіРѕСЂРёСЋ РїРѕ РёРјРµРЅРё Рё СЂРѕРґРёС‚РµР»СЋ РµС‰Рµ СЂР°Р·
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
                                // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё, РїСЂРѕРїСѓСЃРєР°РµРј СЌС‚Сѓ РєР°С‚РµРіРѕСЂРёСЋ
                                continue;
                            }
                        }
                    }

                    if ($categoryId) {
                        $categoryMapArray[$categoryKey] = $categoryId;
                        // РўР°РєР¶Рµ РґРѕР±Р°РІР»СЏРµРј РІ РєР°СЂС‚Сѓ РїРѕ РёРјРµРЅРё РґР»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё
                        $categoryMapArray[strtolower($categoryName)] = $categoryId;
                    }
                } else {
                    // РќРµ СЃРѕР·РґР°РµРј РєР°С‚РµРіРѕСЂРёСЋ, РІРѕР·РІСЂР°С‰Р°РµРј null
                    return null;
                }
            }

            $lastCategoryId = $categoryId;
            $parentId = $categoryId; // РЎР»РµРґСѓСЋС‰Р°СЏ РєР°С‚РµРіРѕСЂРёСЏ Р±СѓРґРµС‚ РґРѕС‡РµСЂРЅРµР№ РґР»СЏ С‚РµРєСѓС‰РµР№
        }

        return $lastCategoryId;
    }

    /**
     * РЎРѕР±РёСЂР°РµС‚ РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ РЅРѕРІС‹С… РєР°С‚РµРіРѕСЂРёСЏС… Рё Р±СЂРµРЅРґР°С…
     */
    private function collectNewCategoriesAndBrands($goods, &$results)
    {
        $newCategories = [];
        $newBrands = [];

        // РџРѕР»СѓС‡Р°РµРј РєСЌС€ РєР°СЂС‚ РґР»СЏ РѕРїСЂРµРґРµР»РµРЅРёСЏ РЅРѕРІС‹С… СЌР»РµРјРµРЅС‚РѕРІ
        $categoryMap = cache('category_map_' . auth()->id(), collect());
        $brandMap = cache('brand_map_' . auth()->id(), collect());

        // РџРѕР»СѓС‡Р°РµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ С‚РѕРј, РєР°РєРёРµ СЌР»РµРјРµРЅС‚С‹ Р±С‹Р»Рё СЃРѕР·РґР°РЅС‹ РІ СЌС‚РѕРј СЃРµР°РЅСЃРµ
        $createdCategories = cache('created_categories_' . auth()->id(), []);
        $createdBrands = cache('created_brands_' . auth()->id(), []);

        foreach ($goods as $index => $goodData) {

            // РЎРѕР±РёСЂР°РµРј С‚РѕР»СЊРєРѕ С‚Рµ РєР°С‚РµРіРѕСЂРёРё, РєРѕС‚РѕСЂС‹Рµ Р±С‹Р»Рё СЃРѕР·РґР°РЅС‹ РІ СЌС‚РѕРј СЃРµР°РЅСЃРµ
            if (isset($goodData['categories']) && is_array($goodData['categories'])) {
                foreach ($goodData['categories'] as $categoryId) {
                    if (is_numeric($categoryId) && in_array($categoryId, $createdCategories)) {
                        // РС‰РµРј РЅР°Р·РІР°РЅРёРµ РєР°С‚РµРіРѕСЂРёРё РїРѕ ID РІ РєСЌС€Рµ
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

            // РЎРѕР±РёСЂР°РµРј С‚РѕР»СЊРєРѕ С‚Рµ Р±СЂРµРЅРґС‹, РєРѕС‚РѕСЂС‹Рµ Р±С‹Р»Рё СЃРѕР·РґР°РЅС‹ РІ СЌС‚РѕРј СЃРµР°РЅСЃРµ
            if (isset($goodData['brands']) && is_array($goodData['brands'])) {
                foreach ($goodData['brands'] as $brandId) {
                    if (is_numeric($brandId) && in_array($brandId, $createdBrands)) {
                        // РС‰РµРј РЅР°Р·РІР°РЅРёРµ Р±СЂРµРЅРґР° РїРѕ ID РІ РєСЌС€Рµ
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
     * РџСЂРёРјРµРЅСЏРµС‚ РјРѕРґРёС„РёРєР°С†РёСЋ Рє РѕР±С‹С‡РЅРѕР№ С†РµРЅРµ
     */
    private function applyPriceModification($price, $modification)
    {
        if (!$price || !$modification) {
            return $price;
        }

        $multiplier = $modification['multiplier'] ?? 1;
        $addType = $modification['addType'] ?? 'percent';
        $addValue = $modification['addValue'] ?? 0;

        // РЎРЅР°С‡Р°Р»Р° СѓРјРЅРѕР¶Р°РµРј С†РµРЅСѓ
        $newPrice = $price * $multiplier;

        // Р—Р°С‚РµРј РїСЂРёРјРµРЅСЏРµРј РґРѕР±Р°РІР»РµРЅРёРµ/РІС‹С‡РёС‚Р°РЅРёРµ
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
     * РџСЂРёРјРµРЅСЏРµС‚ РјРѕРґРёС„РёРєР°С†РёСЋ Рє Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅРµ
     */
    private function applySalePriceModification($goodData, $priceModification)
    {
        if (!$priceModification || !isset($priceModification['sale'])) {
            return $goodData['sale_price'] ?? null;
        }

        $saleModification = $priceModification['sale'];
        $source = $saleModification['source'] ?? 'file';

        if ($source === 'new_price') {
            // Р‘РµСЂРµРј РёР·РјРµРЅРµРЅРЅСѓСЋ РѕР±С‹С‡РЅСѓСЋ С†РµРЅСѓ
            $regularPrice = $this->applyPriceModification($goodData['price'] ?? 0, $priceModification['regular'] ?? null);

            return $this->applyPriceModification($regularPrice, $saleModification);
        } else {
            // Р‘РµСЂРµРј Р·РЅР°С‡РµРЅРёРµ РёР· С„Р°Р№Р»Р°
            $salePrice = $goodData['sale_price'] ?? null;
            if (!$salePrice) {
                return null;
            }

            return $this->applyPriceModification($salePrice, $saleModification);
        }
    }

    /**
     * РћР±СЂР°Р±Р°С‚С‹РІР°РµС‚ СЃРІРѕР№СЃС‚РІР° С‚РѕРІР°СЂР°
     * РќРµ СѓРґР°Р»СЏРµС‚ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ СЃРІРѕР№СЃС‚РІР°, С‚РѕР»СЊРєРѕ РѕР±РЅРѕРІР»СЏРµС‚ РёР»Рё РґРѕР±Р°РІР»СЏРµС‚ РЅРѕРІС‹Рµ
     */
    private function processProperties($good, $properties)
    {
        if (empty($properties)) {
            return;
        }

        // РџРѕР»СѓС‡Р°РµРј С‚РµРєСѓС‰РёРµ СЃРІРѕР№СЃС‚РІР° С‚РѕРІР°СЂР°
        // РљРѕР»РѕРЅРєР° variation_id Р±С‹Р»Р° СѓРґР°Р»РµРЅР° РёР· С‚Р°Р±Р»РёС†С‹, РїРѕСЌС‚РѕРјСѓ С„РёР»СЊС‚СЂСѓРµРј С‚РѕР»СЊРєРѕ РїРѕ good_id
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
                    // РС‰РµРј Р·РЅР°С‡РµРЅРёРµ РІ С‚Р°Р±Р»РёС†Рµ shop_property_values
                    $propertyValue = ShopPropertyValue::where('property_id', $propertyId)
                        ->whereRaw('LOWER(value) = ?', [strtolower($valueString)])
                        ->first();

                    // Р•СЃР»Рё РЅРµ РЅР°Р№РґРµРЅРѕ - СЃРѕР·РґР°РµРј РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ
                    if (!$propertyValue) {
                        $propertyValue = ShopPropertyValue::create([
                            'property_id' => $propertyId,
                            'value' => $valueString,
                            'is_active' => true,
                        ]);
                    }

                    // РџСЂРѕРІРµСЂСЏРµРј, СЃСѓС‰РµСЃС‚РІСѓРµС‚ Р»Рё СѓР¶Рµ СЌС‚Рѕ СЃРІРѕР№СЃС‚РІРѕ Сѓ С‚РѕРІР°СЂР°
                    $existingProperty = $existingProperties->get($propertyId);

                    if ($existingProperty) {
                        // РЎРІРѕР№СЃС‚РІРѕ СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ - РїСЂРѕРІРµСЂСЏРµРј Р·РЅР°С‡РµРЅРёРµ
                        $currentValueId = $existingProperty->shop_property_value_id;

                        // РЎСЂР°РІРЅРёРІР°РµРј Р·РЅР°С‡РµРЅРёСЏ (СѓС‡РёС‚С‹РІР°РµРј null)
                        if ($currentValueId === null || $currentValueId != $propertyValue->id) {
                            // Р—РЅР°С‡РµРЅРёРµ РёР·РјРµРЅРёР»РѕСЃСЊ РёР»Рё Р±С‹Р»Рѕ null - РѕР±РЅРѕРІР»СЏРµРј
                            $existingProperty->shop_property_value_id = $propertyValue->id;
                            $existingProperty->save();

                        } else {
                            // Р—РЅР°С‡РµРЅРёРµ РЅРµ РёР·РјРµРЅРёР»РѕСЃСЊ - РїСЂРѕРїСѓСЃРєР°РµРј
                        }
                    } else {
                        // РЎРІРѕР№СЃС‚РІР° РЅРµС‚ - СЃРѕР·РґР°РµРј РЅРѕРІРѕРµ
                        ShopGoodProperty::create([
                            'good_id' => $good->id,
                            'property_id' => $propertyId,
                            'shop_property_value_id' => $propertyValue->id,
                        ]);

                    }
                } catch (\Exception $e) {
                    // РџСЂРѕРїСѓСЃРєР°РµРј СЌС‚Рѕ СЃРІРѕР№СЃС‚РІРѕ РїСЂРё РѕС€РёР±РєРµ
                    continue;
                }
            }
        }

        // РЎС‚Р°СЂС‹Рµ СЃРІРѕР№СЃС‚РІР°, РєРѕС‚РѕСЂС‹С… РЅРµС‚ РІ РЅРѕРІС‹С… РґР°РЅРЅС‹С…, РЅРµ С‚СЂРѕРіР°РµРј (РЅРµ СѓРґР°Р»СЏРµРј)
    }

    /**
     * РЎРѕР·РґР°С‘С‚ РёР»Рё РѕР±РЅРѕРІР»СЏРµС‚ РІР°СЂРёР°С†РёСЋ Р±РµР· Р°С‚СЂРёР±СѓС‚РѕРІ (С‚РѕР»СЊРєРѕ sku, С†РµРЅР°, РѕСЃС‚Р°С‚РєРё).
     * РСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РїСЂРё В«РџРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС…В» РїРѕ SKU: С‚РѕРІР°СЂ РЅР°Р№РґРµРЅ РїРѕ РЅР°Р·РІР°РЅРёСЋ вЂ” РґРѕР±Р°РІР»СЏРµРј РІР°СЂРёР°С†РёСЋ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ РёР· СЃС‚СЂРѕРєРё.
     * РђС‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё (Р Р°Р·РјРµСЂ, Р¦РІРµС‚ Рё С‚.Рї.) РЅРµ С‚СЂРµР±СѓСЋС‚СЃСЏ.
     *
     * @return int|null ID РІР°СЂРёР°С†РёРё РёР»Рё null РїСЂРё РѕС€РёР±РєРµ
     */
    private function createSimpleVariation($good, $goodData, $supplierStockFields = null)
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
            // Р’Р°Р¶РЅРѕ: РїРѕР»СЏ СѓРґР°Р»С‘РЅРЅС‹С… РѕСЃС‚Р°С‚РєРѕРІ С…СЂР°РЅРёРј РєР°Рє СЃС‚СЂРѕРєРё "РєР°Рє РµСЃС‚СЊ"
            // Р•СЃР»Рё Р·РЅР°С‡РµРЅРёРµ С‡РёСЃР»РѕРІРѕРµ вЂ” РїСЂРёРІРѕРґРёРј Рє СЃС‚СЂРѕРєРµ, РёРЅР°С‡Рµ РёСЃРїРѕР»СЊР·СѓРµРј РёСЃС…РѕРґРЅСѓСЋ СЃС‚СЂРѕРєСѓ
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

            $existing = ShopGoodVariation::where('good_id', $good->id)->where('sku', $sku);
            if ($variationSupplier) {
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
     * РћР±СЂР°Р±РѕС‚РєР° РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР° РїСЂРё РёРјРїРѕСЂС‚Рµ
     *
     * @return int|null ID РІР°СЂРёР°С†РёРё РёР»Рё null, РµСЃР»Рё РІР°СЂРёР°С†РёСЏ РЅРµ Р±С‹Р»Р° СЃРѕР·РґР°РЅР°/РѕР±РЅРѕРІР»РµРЅР°
     */
    private function processVariation($good, $variationData, $goodData, $supplierStockFields = null)
    {
        try {
            // РљР РРўРР§РќРћ: РћР±СЂРµР·Р°РµРј Рё РѕР±РЅРѕРІР»СЏРµРј РЅР°Р·РІР°РЅРёРµ С‚РѕРІР°СЂР° РџР•Р Р•Р” РѕР±СЂР°Р±РѕС‚РєРѕР№ РІР°СЂРёР°С†РёРё
            // Р­С‚Рѕ РіР°СЂР°РЅС‚РёСЂСѓРµС‚, С‡С‚Рѕ РѕР±СЂРµР·РєР° Р±СѓРґРµС‚ РїСЂРёРјРµРЅРµРЅР° РґР»СЏ РІСЃРµС… С‚РѕРІР°СЂРѕРІ СЃ РІР°СЂРёР°С†РёСЏРјРё
            // РџРѕР»СѓС‡Р°РµРј nameTrimSymbol РёР· goodData РёР»Рё РёР· РіР»РѕР±Р°Р»СЊРЅРѕРіРѕ РєРѕРЅС‚РµРєСЃС‚Р°
            $nameTrimSymbol = $goodData['_nameTrimSymbol'] ?? null;
            if (empty($nameTrimSymbol)) {
                // РџСЂРѕР±СѓРµРј РїРѕР»СѓС‡РёС‚СЊ РёР· request С‡РµСЂРµР· РіР»РѕР±Р°Р»СЊРЅС‹Р№ РєРѕРЅС‚РµРєСЃС‚
                try {
                    $request = request();
                    $nameTrimSymbol = $request ? $request->input('name_trim_symbol') : null;
                } catch (\Exception $e) {
                    $nameTrimSymbol = null;
                }
            }

            // РџСЂРёРјРµРЅСЏРµРј РѕР±СЂРµР·РєСѓ РЅР°Р·РІР°РЅРёСЏ С‚РѕРІР°СЂР°, РµСЃР»Рё СѓРєР°Р·Р°РЅ СЃРёРјРІРѕР» РѕР±СЂРµР·РєРё
            // РСЃРїРѕР»СЊР·СѓРµРј РѕС‚РґРµР»СЊРЅСѓСЋ С„СѓРЅРєС†РёСЋ РґР»СЏ РѕР±СЂРµР·РєРё, РєРѕС‚РѕСЂР°СЏ РїСЂРѕРІРµСЂСЏРµС‚ РЅР°Р»РёС‡РёРµ СЃРёРјРІРѕР»Р° РІ РЅР°Р·РІР°РЅРёРё С‚РѕРІР°СЂР°
            $nameFromData = isset($goodData['name']) ? trim($goodData['name']) : null;
            $immutableFields = []; // Р’ processVariation immutableFields РЅРµ РїРµСЂРµРґР°СЋС‚СЃСЏ, РёСЃРїРѕР»СЊР·СѓРµРј РїСѓСЃС‚РѕР№ РјР°СЃСЃРёРІ
            $this->trimGoodName($good, $nameTrimSymbol, $immutableFields, $nameFromData);

            // РџСЂРѕРІРµСЂСЏРµРј РЅР°Р»РёС‡РёРµ РґР°РЅРЅС‹С… РІР°СЂРёР°С†РёРё
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

            // РљРѕРЅРІРµСЂС‚РёСЂСѓРµРј РѕСЃС‚Р°С‚РєРё РёР· СЃС‚СЂРѕРє РІ С‡РёСЃР»Р°
            $variationStockQuantity = $variationData['stock_quantity'] ?? $goodData['stock_quantity'] ?? 0;
            $variationStockQuantity = is_numeric($variationStockQuantity) ? (float) $variationStockQuantity : 0;

            $variationRemoteStockQuantity = $variationData['remote_stock_quantity'] ?? $goodData['remote_stock_quantity'] ?? null;
            $variationRemoteStockQuantity = $variationRemoteStockQuantity !== null && is_numeric($variationRemoteStockQuantity) ? (string) $variationRemoteStockQuantity : $variationRemoteStockQuantity;

            $variationFastRemoteStockQuantity = $variationData['fast_remote_stock_quantity'] ?? $goodData['fast_remote_stock_quantity'] ?? null;
            $variationFastRemoteStockQuantity = $variationFastRemoteStockQuantity !== null && is_numeric($variationFastRemoteStockQuantity) ? (string) $variationFastRemoteStockQuantity : $variationFastRemoteStockQuantity;

            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё Сѓ С‚РѕРІР°СЂР° СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РІР°СЂРёР°С†РёРё
            $hasExistingVariations = ShopGoodVariation::where('good_id', $good->id)->exists();

            // РџРѕР»СѓС‡Р°РµРј СЃРїРёСЃРѕРє СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёС… Р°С‚СЂРёР±СѓС‚РѕРІ С‚РѕРІР°СЂР° (РµСЃР»Рё РµСЃС‚СЊ РІР°СЂРёР°С†РёРё)
            $existingAttributeIds = [];
            if ($hasExistingVariations) {
                // РџРѕР»СѓС‡Р°РµРј РІСЃРµ ID Р°С‚СЂРёР±СѓС‚РѕРІ, РёСЃРїРѕР»СЊР·СѓРµРјС‹С… РІ РІР°СЂРёР°С†РёСЏС… СЌС‚РѕРіРѕ С‚РѕРІР°СЂР°
                $variationIds = ShopGoodVariation::where('good_id', $good->id)->pluck('id')->toArray();
                $existingAttributeValueIds = DB::table('shop_variation_attributes_values')
                    ->whereIn('variation_id', $variationIds)
                    ->pluck('attribute_value_id')
                    ->unique()
                    ->toArray();

                // РџРѕР»СѓС‡Р°РµРј ID Р°С‚СЂРёР±СѓС‚РѕРІ РёР· Р·РЅР°С‡РµРЅРёР№
                if (!empty($existingAttributeValueIds)) {
                    $existingAttributeIds = DB::table('shop_variation_attribute_values')
                        ->whereIn('id', $existingAttributeValueIds)
                        ->pluck('attribute_id')
                        ->unique()
                        ->toArray();
                }
            }

            // РќР°С…РѕРґРёРј РёР»Рё СЃРѕР·РґР°РµРј Р°С‚СЂРёР±СѓС‚С‹ Рё РёС… Р·РЅР°С‡РµРЅРёСЏ
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

                // РќР°С…РѕРґРёРј Р°С‚СЂРёР±СѓС‚ (РЅРµ СЃРѕР·РґР°РµРј РЅРѕРІС‹Рµ)
                $attribute = $this->findOrCreateVariationAttribute($attributeName, $hasExistingVariations, $existingAttributeIds);
                if (!$attribute) {
                    // РђС‚СЂРёР±СѓС‚ РЅРµ РЅР°Р№РґРµРЅ - РїСЂРѕРїСѓСЃРєР°РµРј СЌС‚Сѓ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєСѓ
                    continue;
                }

                // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ Р°С‚СЂРёР±СѓС‚ РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РІ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёС… РІР°СЂРёР°С†РёСЏС… (РµСЃР»Рё РѕРЅРё РµСЃС‚СЊ)
                // Р’СЂРµРјРµРЅРЅРѕ РѕС‚РєР»СЋС‡РµРЅРѕ РґР»СЏ РѕС‚Р»Р°РґРєРё РїСЂРѕР±Р»РµРјС‹ РїРѕРёСЃРєР° РІР°СЂРёР°С†РёР№ Р±РµР· РїРѕСЃС‚Р°РІС‰РёРєР°
                // if ($hasExistingVariations && !in_array($attribute->id, $existingAttributeIds)) {
                //     // РђС‚СЂРёР±СѓС‚ РЅРµ РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РІ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёС… РІР°СЂРёР°С†РёСЏС… - РїСЂРѕРїСѓСЃРєР°РµРј
                //     continue;
                // }

                // РќР°С…РѕРґРёРј РёР»Рё СЃРѕР·РґР°РµРј Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
                $attributeValueId = $this->findOrCreateAttributeValue($attribute->id, $attributeValue);
                if (!$attributeValueId) {
                    $hasErrors = true;

                    continue;
                }

                $attributeValueIds[] = $attributeValueId;
            }

            // Р•СЃР»Рё Р±С‹Р»Рё РѕС€РёР±РєРё РёР»Рё РЅРµС‚ РІР°Р»РёРґРЅС‹С… Р°С‚СЂРёР±СѓС‚РѕРІ - РЅРµ СЃРѕР·РґР°РµРј РІР°СЂРёР°С†РёСЋ
            if ($hasErrors || empty($attributeValueIds)) {
                // Р•СЃР»Рё РЅРµС‚ РІР°Р»РёРґРЅС‹С… Р°С‚СЂРёР±СѓС‚РѕРІ - РїСЂРѕСЃС‚Рѕ РїСЂРѕРїСѓСЃРєР°РµРј РІР°СЂРёР°С†РёСЋ Р±РµР· Р»РѕРіРёСЂРѕРІР°РЅРёСЏ
                // (С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё РЅРµ РЅР°Р№РґРµРЅС‹, СЌС‚Рѕ РЅРѕСЂРјР°Р»СЊРЅРѕРµ РїРѕРІРµРґРµРЅРёРµ)
                return null;
            }

            // РЎРѕСЂС‚РёСЂСѓРµРј ID Р°С‚СЂРёР±СѓС‚РѕРІ РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ
            sort($attributeValueIds);

            // РџРѕР»СѓС‡Р°РµРј РїРѕСЃС‚Р°РІС‰РёРєР° РґР»СЏ РІР°СЂРёР°С†РёРё
            $variationSupplier = isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '' ? trim($goodData['supplier_name']) : null;

            // РС‰РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ РІР°СЂРёР°С†РёСЋ СЃ С‚Р°РєРѕР№ Р¶Рµ РєРѕРјР±РёРЅР°С†РёРµР№ Р°С‚СЂРёР±СѓС‚РѕРІ Рё РїРѕСЃС‚Р°РІС‰РёРєРѕРј
            $existingVariation = $this->findVariationByAttributes($good->id, $attributeValueIds, $variationSupplier);

            if ($existingVariation) {
                // Р’Р°СЂРёР°С†РёСЏ СЃСѓС‰РµСЃС‚РІСѓРµС‚ - РѕР±РЅРѕРІР»СЏРµРј РІСЃРµ РїРѕР»СЏ РёР· goodData

                // РћР±РЅРѕРІР»СЏРµРј С†РµРЅСѓ
                $existingVariation->price = $variationPrice;

                // РћР±РЅРѕРІР»СЏРµРј Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ, РµСЃР»Рё РїРµСЂРµРґР°РЅР°
                if (isset($variationData['sale_price'])) {
                    $existingVariation->sale_price = $variationSalePrice;
                } elseif (isset($goodData['sale_price'])) {
                    $existingVariation->sale_price = $goodData['sale_price'];
                }

                // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё
                if ($supplierStockFields && isset($supplierStockFields['stock_quantity']) && $supplierStockFields['stock_quantity']) {
                    $existingVariation->stock_quantity = $variationStockQuantity;
                }
                if (isset($variationData['remote_stock_quantity']) || isset($goodData['remote_stock_quantity'])) {
                    $existingVariation->remote_stock_quantity = $variationRemoteStockQuantity;
                }
                if (isset($variationData['fast_remote_stock_quantity']) || isset($goodData['fast_remote_stock_quantity'])) {
                    $existingVariation->fast_remote_stock_quantity = $variationFastRemoteStockQuantity;
                }

                // РћР±РЅРѕРІР»СЏРµРј SKU РІР°СЂРёР°С†РёРё РёР· РґР°РЅРЅС‹С… С‚РѕРІР°СЂР° РёР»Рё РёР· goodData
                // Р”СѓР±Р»РёСЂРѕРІР°РЅРёРµ SKU СЂР°Р·СЂРµС€РµРЅРѕ РґР»СЏ РІР°СЂРёР°С†РёР№, РїРѕСЌС‚РѕРјСѓ РЅРµ РїСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ
                if (isset($goodData['sku']) && !empty($goodData['sku'])) {
                    $existingVariation->sku = $goodData['sku'];
                } elseif ($good->sku) {
                    $existingVariation->sku = $good->sku;
                }

                // РћР±РЅРѕРІР»СЏРµРј СЂР°Р·РјРµСЂС‹ Рё РІРµСЃ, РµСЃР»Рё РїРµСЂРµРґР°РЅС‹
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

                // РћР±РЅРѕРІР»СЏРµРј С„Р»Р°Рі Р°РєС‚РёРІРЅРѕСЃС‚Рё, РµСЃР»Рё РїРµСЂРµРґР°РЅ
                if (isset($goodData['is_active'])) {
                    $existingVariation->is_active = $goodData['is_active'];
                }

                // РћР±РЅРѕРІР»СЏРµРј РїРѕСЃС‚Р°РІС‰РёРєР° РІР°СЂРёР°С†РёРё
                if (isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '') {
                    $existingVariation->supplier = trim($goodData['supplier_name']);
                }

                try {
                    $existingVariation->save();
                } catch (QueryException $e) {
                    // РРіРЅРѕСЂРёСЂСѓРµРј РѕС€РёР±РєРё РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ SKU РґР»СЏ РІР°СЂРёР°С†РёР№ (РґСѓР±Р»РёСЂРѕРІР°РЅРёРµ СЂР°Р·СЂРµС€РµРЅРѕ)
                    if (
                        strpos($e->getMessage(), 'Duplicate entry') !== false ||
                        strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                        $e->getCode() == 23000
                    ) {
                        // РџСЂРѕРїСѓСЃРєР°РµРј РѕС€РёР±РєСѓ РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ SKU - СЌС‚Рѕ СЂР°Р·СЂРµС€РµРЅРѕ РґР»СЏ РІР°СЂРёР°С†РёР№
                    } else {
                        // Р•СЃР»Рё СЌС‚Рѕ РґСЂСѓРіР°СЏ РѕС€РёР±РєР° - РїСЂРѕР±СЂР°СЃС‹РІР°РµРј РґР°Р»СЊС€Рµ
                        throw $e;
                    }
                }

                // Р›РѕРіРёСЂСѓРµРј РѕР±РЅРѕРІР»РµРЅРёРµ РІР°СЂРёР°С†РёРё
                $this->importLogService->logVariation(
                    'РћР‘РќРћР’Р›Р•РќРђ Р’РђР РРђР¦РРЇ',
                    $good->name,
                    $good->sku ?? 'РЅРµС‚ SKU',
                    $attributes,
                    $existingVariation->id,
                    "Р¦РµРЅР°: {$variationPrice}, РћСЃС‚Р°С‚РѕРє: {$variationStockQuantity}"
                    . ($variationRemoteStockQuantity !== null ? ", РЈРґР°Р»РµРЅРЅС‹Р№ СЃРєР»Р°Рґ: {$variationRemoteStockQuantity}" : '')
                    . ($variationFastRemoteStockQuantity !== null ? ", Р‘С‹СЃС‚СЂС‹Р№ СѓРґР°Р»РµРЅРЅС‹Р№ СЃРєР»Р°Рґ: {$variationFastRemoteStockQuantity}" : '')
                    . (isset($existingVariation->supplier) && trim((string) $existingVariation->supplier) !== '' ? ", РџРѕСЃС‚Р°РІС‰РёРє: {$existingVariation->supplier}" : '')
                    . (isset($good->id) ? ", ID С‚РѕРІР°СЂР°: {$good->id}" : '')
                );

                // Р’РѕР·РІСЂР°С‰Р°РµРј ID РІР°СЂРёР°С†РёРё РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                return $existingVariation->id;
            } else {
                // Р’Р°СЂРёР°С†РёСЏ РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ - СЃРѕР·РґР°РµРј РЅРѕРІСѓСЋ
                // РСЃРїРѕР»СЊР·СѓРµРј SKU РёР· goodData, РµСЃР»Рё РµСЃС‚СЊ, РёРЅР°С‡Рµ РёР· good
                $variationSku = isset($goodData['sku']) && !empty($goodData['sku']) ? $goodData['sku'] : ($good->sku ?? null);

                // РћРїСЂРµРґРµР»СЏРµРј sort_order РґР»СЏ РЅРѕРІРѕР№ РІР°СЂРёР°С†РёРё
                $maxSortOrder = $good->variations()->max('sort_order');
                $sortOrder = $maxSortOrder !== null ? ($maxSortOrder + 1) : 0;

                // Р”СѓР±Р»РёСЂРѕРІР°РЅРёРµ SKU СЂР°Р·СЂРµС€РµРЅРѕ РґР»СЏ РІР°СЂРёР°С†РёР№, РїРѕСЌС‚РѕРјСѓ РЅРµ РїСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ
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

                    // Р”РѕР±Р°РІР»СЏРµРј РѕСЃС‚Р°С‚РєРё С‚РѕР»СЊРєРѕ РґР»СЏ СЂР°Р·СЂРµС€РµРЅРЅС‹С… РїРѕР»РµР№
                    // РЈСЃС‚Р°РЅР°РІР»РёРІР°РµРј РѕСЃС‚Р°С‚РєРё РґР»СЏ РІР°СЂРёР°С†РёРё
                    if (isset($variationData['remote_stock_quantity']) || isset($goodData['remote_stock_quantity'])) {
                        $variationDataToCreate['remote_stock_quantity'] = $variationRemoteStockQuantity;
                    }
                    // Р•СЃР»Рё РїРѕР»Рµ РЅРµ СѓСЃС‚Р°РЅРѕРІР»РµРЅРѕ, РѕСЃС‚Р°РІР»СЏРµРј Р·РЅР°С‡РµРЅРёРµ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ (РЅРµ СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј null)

                    if (isset($variationData['fast_remote_stock_quantity']) || isset($goodData['fast_remote_stock_quantity'])) {
                        $variationDataToCreate['fast_remote_stock_quantity'] = $variationFastRemoteStockQuantity;
                    }
                    // Р•СЃР»Рё РїРѕР»Рµ РЅРµ СѓСЃС‚Р°РЅРѕРІР»РµРЅРѕ, РѕСЃС‚Р°РІР»СЏРµРј Р·РЅР°С‡РµРЅРёРµ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ (РЅРµ СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј null)

                    $variationDataToCreate['stock_quantity'] = $variationStockQuantity;

                    $variation = ShopGoodVariation::create($variationDataToCreate);
                } catch (QueryException $e) {
                    // РРіРЅРѕСЂРёСЂСѓРµРј РѕС€РёР±РєРё РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ SKU РґР»СЏ РІР°СЂРёР°С†РёР№ (РґСѓР±Р»РёСЂРѕРІР°РЅРёРµ СЂР°Р·СЂРµС€РµРЅРѕ)
                    if (
                        strpos($e->getMessage(), 'Duplicate entry') !== false ||
                        strpos($e->getMessage(), 'UNIQUE constraint') !== false ||
                        $e->getCode() == 23000
                    ) {
                        // РџСЂРѕРїСѓСЃРєР°РµРј РѕС€РёР±РєСѓ РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ SKU - СЌС‚Рѕ СЂР°Р·СЂРµС€РµРЅРѕ РґР»СЏ РІР°СЂРёР°С†РёР№
                        // РџСЂРѕР±СѓРµРј РЅР°Р№С‚Рё СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ РІР°СЂРёР°С†РёСЋ СЃ С‚Р°РєРёРј Р¶Рµ SKU Рё РѕР±РЅРѕРІРёС‚СЊ РµС‘

                        // РС‰РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ РІР°СЂРёР°С†РёСЋ СЃ С‚Р°РєРёРј Р¶Рµ SKU Рё РїРѕСЃС‚Р°РІС‰РёРєРѕРј
                        $supplierName = isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '' ? trim($goodData['supplier_name']) : null;
                        $skuQuery = ShopGoodVariation::where('good_id', $good->id)
                            ->where('sku', $variationSku);
                        if ($supplierName) {
                            $skuQuery->where('supplier', $supplierName);
                        }
                        $existingVariationBySku = $skuQuery->first();

                        if ($existingVariationBySku) {
                            // РћР±РЅРѕРІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ РІР°СЂРёР°С†РёСЋ - РІСЃРµ РїРѕР»СЏ РёР· goodData
                            $existingVariationBySku->price = $variationPrice;

                            // РћР±РЅРѕРІР»СЏРµРј Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ, РµСЃР»Рё РїРµСЂРµРґР°РЅР°
                            if (isset($variationData['sale_price'])) {
                                $existingVariationBySku->sale_price = $variationSalePrice;
                            } elseif (isset($goodData['sale_price'])) {
                                $existingVariationBySku->sale_price = $goodData['sale_price'];
                            }

                            // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё С‚РѕР»СЊРєРѕ РµСЃР»Рё Р·РЅР°С‡РµРЅРёСЏ РЅРµ РїСѓСЃС‚С‹Рµ
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

                            // РћР±РЅРѕРІР»СЏРµРј СЂР°Р·РјРµСЂС‹ Рё РІРµСЃ, РµСЃР»Рё РїРµСЂРµРґР°РЅС‹
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

                            // РћР±РЅРѕРІР»СЏРµРј С„Р»Р°Рі Р°РєС‚РёРІРЅРѕСЃС‚Рё, РµСЃР»Рё РїРµСЂРµРґР°РЅ
                            if (isset($goodData['is_active'])) {
                                $existingVariationBySku->is_active = $goodData['is_active'];
                            }

                            // РћР±РЅРѕРІР»СЏРµРј РїРѕСЃС‚Р°РІС‰РёРєР° РІР°СЂРёР°С†РёРё
                            if (isset($goodData['supplier_name']) && trim($goodData['supplier_name']) !== '') {
                                $existingVariationBySku->supplier = trim($goodData['supplier_name']);
                            }

                            try {
                                $existingVariationBySku->save();
                            } catch (QueryException $saveException) {
                                // РРіРЅРѕСЂРёСЂСѓРµРј РѕС€РёР±РєРё РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ SKU РїСЂРё СЃРѕС…СЂР°РЅРµРЅРёРё
                                if (
                                    strpos($saveException->getMessage(), 'Duplicate entry') !== false ||
                                    strpos($saveException->getMessage(), 'UNIQUE constraint') !== false ||
                                    $saveException->getCode() == 23000
                                ) {
                                    // РџСЂРѕРїСѓСЃРєР°РµРј РѕС€РёР±РєСѓ РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ SKU
                                } else {
                                    throw $saveException;
                                }
                            }

                            $variation = $existingVariationBySku;
                            $wasCreated = false;
                        } else {
                            // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё - РїСЂРѕР±СЂР°СЃС‹РІР°РµРј РѕС€РёР±РєСѓ РґР°Р»СЊС€Рµ
                            throw $e;
                        }
                    } else {
                        // Р•СЃР»Рё СЌС‚Рѕ РґСЂСѓРіР°СЏ РѕС€РёР±РєР° - РїСЂРѕР±СЂР°СЃС‹РІР°РµРј РґР°Р»СЊС€Рµ
                        throw $e;
                    }
                }

                // РџСЂРёРІСЏР·С‹РІР°РµРј Р·РЅР°С‡РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚РѕРІ Рє РІР°СЂРёР°С†РёРё
                foreach ($attributeValueIds as $attributeValueId) {
                    DB::table('shop_variation_attributes_values')->insert([
                        'variation_id' => $variation->id,
                        'attribute_value_id' => $attributeValueId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Р›РѕРіРёСЂСѓРµРј РґРµР№СЃС‚РІРёРµ РЅР°Рґ РІР°СЂРёР°С†РёРµР№
                $this->importLogService->logVariation(
                    $wasCreated ? 'РЎРћР—Р”РђРќРђ Р’РђР РРђР¦РРЇ' : 'РћР‘РќРћР’Р›Р•РќРђ Р’РђР РРђР¦РРЇ',
                    $good->name,
                    $variationSku ?? 'РЅРµС‚ SKU',
                    $attributes,
                    $variation->id,
                    "Р¦РµРЅР°: {$variationPrice}, РћСЃС‚Р°С‚РѕРє: {$variationStockQuantity}"
                    . ($variationRemoteStockQuantity !== null ? ", РЈРґР°Р»РµРЅРЅС‹Р№ СЃРєР»Р°Рґ: {$variationRemoteStockQuantity}" : '')
                    . ($variationFastRemoteStockQuantity !== null ? ", Р‘С‹СЃС‚СЂС‹Р№ СѓРґР°Р»РµРЅРЅС‹Р№ СЃРєР»Р°Рґ: {$variationFastRemoteStockQuantity}" : '')
                    . (isset($variation->supplier) && trim((string) $variation->supplier) !== '' ? ", РџРѕСЃС‚Р°РІС‰РёРє: {$variation->supplier}" : '')
                    . (isset($good->id) ? ", ID С‚РѕРІР°СЂР°: {$good->id}" : '')
                );

                // Р’РѕР·РІСЂР°С‰Р°РµРј ID РІР°СЂРёР°С†РёРё РґР»СЏ СЃРІСЏР·Рё СЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё
                return $variation->id;
            }

            return null;
        } catch (\Exception $e) {

            // Р›РѕРіРёСЂСѓРµРј РѕС€РёР±РєСѓ
            $this->importLogService->logVariation(
                'РћРЁРР‘РљРђ Р’РђР РРђР¦РР',
                $good->name ?? 'РЅРµРёР·РІРµСЃС‚РЅРѕ',
                $good->sku ?? 'РЅРµС‚ SKU',
                $variationData['attributes'] ?? [],
                null,
                "РћС€РёР±РєР°: {$e->getMessage()}"
            );

            // Р’РѕР·РІСЂР°С‰Р°РµРј null РїСЂРё РѕС€РёР±РєРµ (С„СѓРЅРєС†РёСЏ РґРѕР»Р¶РЅР° РІРѕР·РІСЂР°С‰Р°С‚СЊ int|null)
            return null;
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ РІР°СЂРёР°С†РёСЋ РёР· РґР°РЅРЅС‹С… goodData
     *
     * @param  ShopGoodVariation  $variation  Р’Р°СЂРёР°С†РёСЏ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
     * @param  array  $goodData  Р”Р°РЅРЅС‹Рµ С‚РѕРІР°СЂР°
     * @param  bool  $searchByNameInVariations  Р•СЃР»Рё true, РїРѕР»Рµ name РЅРµ РѕР±РЅРѕРІР»СЏРµС‚СЃСЏ
     * @return int ID РІР°СЂРёР°С†РёРё
     */
    private function updateVariationFromGoodData($variation, $goodData, $searchByNameInVariations = false)
    {
        // РћР±РЅРѕРІР»СЏРµРј С†РµРЅСѓ
        if (isset($goodData['price'])) {
            $priceModification = $goodData["price_modification"] ?? null;
            $variation->price = $this->applyPriceModification($goodData["price"], $priceModification["regular"] ?? null);
        }

        // РћР±РЅРѕРІР»СЏРµРј Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ, РµСЃР»Рё РїРµСЂРµРґР°РЅР°
        if (isset($goodData['sale_price'])) {
            if (isset($goodData["sale_price"]) || (isset($priceModification) && isset($priceModification["sale"]))) {
                $variation->sale_price = $this->applySalePriceModification($goodData, $priceModification);
            }
        }

        // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё СЃ РєРѕРЅРІРµСЂС‚Р°С†РёРµР№ С‚РёРїРѕРІ
        if (isset($goodData['stock_quantity'])) {
            $variation->stock_quantity = is_numeric($goodData['stock_quantity']) ? (float) $goodData['stock_quantity'] : 0;
        }
        if (isset($goodData['remote_stock_quantity'])) {
            $variation->remote_stock_quantity = $goodData['remote_stock_quantity'] !== null && is_numeric($goodData['remote_stock_quantity']) ? (string) $goodData['remote_stock_quantity'] : $goodData['remote_stock_quantity'];
        }
        if (isset($goodData['fast_remote_stock_quantity'])) {
            $variation->fast_remote_stock_quantity = $goodData['fast_remote_stock_quantity'] !== null && is_numeric($goodData['fast_remote_stock_quantity']) ? (string) $goodData['fast_remote_stock_quantity'] : $goodData['fast_remote_stock_quantity'];
        }

        // РћР±РЅРѕРІР»СЏРµРј SKU РІР°СЂРёР°С†РёРё РёР· РґР°РЅРЅС‹С… С‚РѕРІР°СЂР° РёР»Рё РёР· goodData
        if (isset($goodData['sku']) && !empty($goodData['sku'])) {
            $variation->sku = $goodData['sku'];
        }

        // РћР±РЅРѕРІР»СЏРµРј СЂР°Р·РјРµСЂС‹ Рё РІРµСЃ, РµСЃР»Рё РїРµСЂРµРґР°РЅС‹
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

        // РћР±РЅРѕРІР»СЏРµРј С„Р»Р°Рі Р°РєС‚РёРІРЅРѕСЃС‚Рё, РµСЃР»Рё РїРµСЂРµРґР°РЅ
        if (isset($goodData['is_active'])) {
            $variation->is_active = $goodData['is_active'];
        }

        // РћР±РЅРѕРІР»СЏРµРј РёРјСЏ РІР°СЂРёР°С†РёРё, РµСЃР»Рё РїРµСЂРµРґР°РЅРѕ Рё РЅРµ РІРєР»СЋС‡РµРЅ РїРѕРёСЃРє РїРѕ РёРјРµРЅР°Рј РІ РІР°СЂРёР°С†РёСЏС…
        // Р•СЃР»Рё РІРєР»СЋС‡РµРЅ РїРѕРёСЃРє РїРѕ РёРјРµРЅР°Рј, РїРѕР»Рµ name РЅРµР»СЊР·СЏ РёР·РјРµРЅСЏС‚СЊ
        if (isset($goodData['name']) && !empty($goodData['name']) && !$searchByNameInVariations) {
            $variation->name = $goodData['name'];
        }

        // РћР±РЅРѕРІР»СЏРµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё, РµСЃР»Рё РѕРЅРё РїРµСЂРµРґР°РЅС‹
        if (isset($goodData['variation']) && isset($goodData['variation']['attributes']) && is_array($goodData['variation']['attributes'])) {
            $newAttributes = $goodData['variation']['attributes'];

            // РЈРґР°Р»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ СЃРІСЏР·Рё СЃ Р°С‚СЂРёР±СѓС‚Р°РјРё
            DB::table('shop_variation_attributes_values')->where('variation_id', $variation->id)->delete();

            // Р”РѕР±Р°РІР»СЏРµРј РЅРѕРІС‹Рµ СЃРІСЏР·Рё СЃ Р°С‚СЂРёР±СѓС‚Р°РјРё
            foreach ($newAttributes as $attr) {
                if (isset($attr['name']) && isset($attr['value'])) {
                    $attributeName = trim($attr['name']);
                    $attributeValue = trim($attr['value']);

                    if (!empty($attributeName) && !empty($attributeValue)) {
                        // РќР°С…РѕРґРёРј РёР»Рё СЃРѕР·РґР°РµРј Р°С‚СЂРёР±СѓС‚
                        $attribute = DB::table('shop_variation_attributes')
                            ->where('name', $attributeName)
                            ->first();

                        if ($attribute) {
                            try {
                                // РќР°С…РѕРґРёРј РёР»Рё СЃРѕР·РґР°РµРј Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
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

                                    // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ insertGetId РІРµСЂРЅСѓР» РІР°Р»РёРґРЅС‹Р№ ID
                                    if ($newAttributeValueId && $newAttributeValueId > 0) {
                                        $attributeValueRecord = (object) ['id' => $newAttributeValueId];
                                    } else {
                                        // Р•СЃР»Рё РЅРµ СѓРґР°Р»РѕСЃСЊ СЃРѕР·РґР°С‚СЊ Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°, РїСЂРѕРїСѓСЃРєР°РµРј РµРіРѕ
                                        continue;
                                    }
                                }

                                // Р”РѕР±Р°РІР»СЏРµРј СЃРІСЏР·СЊ РІР°СЂРёР°С†РёРё СЃ Р·РЅР°С‡РµРЅРёРµРј Р°С‚СЂРёР±СѓС‚Р°
                                if ($attributeValueRecord && isset($attributeValueRecord->id)) {
                                    DB::table('shop_variation_attributes_values')->insert([
                                        'variation_id' => $variation->id,
                                        'attribute_value_id' => $attributeValueRecord->id,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            } catch (\Exception $e) {
                                // Р›РѕРіРёСЂСѓРµРј РѕС€РёР±РєСѓ, РЅРѕ РїСЂРѕРґРѕР»Р¶Р°РµРј РѕР±СЂР°Р±РѕС‚РєСѓ РѕСЃС‚Р°Р»СЊРЅС‹С… Р°С‚СЂРёР±СѓС‚РѕРІ
                                \Log::error('РћС€РёР±РєР° РїСЂРё РѕР±СЂР°Р±РѕС‚РєРµ Р°С‚СЂРёР±СѓС‚Р° РІР°СЂРёР°С†РёРё', [
                                    'variation_id' => $variation->id,
                                    'attribute_name' => $attributeName,
                                    'attribute_value' => $attributeValue,
                                    'error' => $e->getMessage(),
                                ]);

                                // РџСЂРѕРїСѓСЃРєР°РµРј СЌС‚РѕС‚ Р°С‚СЂРёР±СѓС‚ Рё РїСЂРѕРґРѕР»Р¶Р°РµРј СЃРѕ СЃР»РµРґСѓСЋС‰РёРј
                                continue;
                            }
                        }
                    }
                }
            }
        }

        try {
            $variation->save();
        } catch (QueryException $e) {
            // РРіРЅРѕСЂРёСЂСѓРµРј РѕС€РёР±РєРё РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ SKU РґР»СЏ РІР°СЂРёР°С†РёР№ (РґСѓР±Р»РёСЂРѕРІР°РЅРёРµ СЂР°Р·СЂРµС€РµРЅРѕ)
            if (
                strpos($e->getMessage(), 'Duplicate entry') === false &&
                strpos($e->getMessage(), 'UNIQUE constraint') === false &&
                $e->getCode() != 23000
            ) {
                // Р•СЃР»Рё СЌС‚Рѕ РґСЂСѓРіР°СЏ РѕС€РёР±РєР° - РїСЂРѕР±СЂР°СЃС‹РІР°РµРј РґР°Р»СЊС€Рµ
                throw $e;
            }
        }

        return $variation->id;
    }

    /**
     * РќР°Р№С‚Рё РёР»Рё СЃРѕР·РґР°С‚СЊ Р°С‚СЂРёР±СѓС‚ РІР°СЂРёР°С†РёРё
     *
     * @param  string  $attributeName  РќР°Р·РІР°РЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
     * @param  bool  $hasExistingVariations  Р•СЃС‚СЊ Р»Рё Сѓ С‚РѕРІР°СЂР° СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РІР°СЂРёР°С†РёРё
     * @param  array  $existingAttributeIds  РњР°СЃСЃРёРІ ID СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёС… Р°С‚СЂРёР±СѓС‚РѕРІ С‚РѕРІР°СЂР°
     * @return object|null РћР±СЉРµРєС‚ Р°С‚СЂРёР±СѓС‚Р° РёР»Рё null, РµСЃР»Рё РЅРµ РЅР°Р№РґРµРЅ Рё РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ СЃРѕР·РґР°РЅ
     */
    private function findOrCreateVariationAttribute($attributeName, $hasExistingVariations = false, $existingAttributeIds = [])
    {
        $attribute = DB::table('shop_variation_attributes')
            ->where('name', $attributeName)
            ->first();

        if ($attribute) {
            return $attribute;
        }

        // РќРµ СЃРѕР·РґР°РµРј РЅРѕРІС‹Рµ Р°С‚СЂРёР±СѓС‚С‹ - РїСЂРѕСЃС‚Рѕ РІРѕР·РІСЂР°С‰Р°РµРј null, РµСЃР»Рё Р°С‚СЂРёР±СѓС‚ РЅРµ РЅР°Р№РґРµРЅ
        return null;
    }

    /**
     * РќР°Р№С‚Рё РёР»Рё СЃРѕР·РґР°С‚СЊ Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
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

        // РЎРѕР·РґР°РµРј РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
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
     * РќР°Р№С‚Рё РІР°СЂРёР°С†РёСЋ РїРѕ РёРјРµРЅРё/SKU Рё Р°С‚СЂРёР±СѓС‚Р°Рј
     * Р’РѕР·РІСЂР°С‰Р°РµС‚ РІР°СЂРёР°С†РёСЋ, РµСЃР»Рё РЅР°Р№РґРµРЅР° СЃ С‚РѕС‡РЅРѕ СЃРѕРІРїР°РґР°СЋС‰РёРјРё Р°С‚СЂРёР±СѓС‚Р°РјРё, РёРЅР°С‡Рµ null
     */
    private function findVariationByNameAndAttributes($searchField, $searchValue, $importAttributes, $supplier = null)
    {
        // Р•СЃР»Рё РЅРµС‚ Р°С‚СЂРёР±СѓС‚РѕРІ РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ, РІРѕР·РІСЂР°С‰Р°РµРј null (РЅРµ РёСЃРїРѕР»СЊР·СѓРµРј СЃС‚Р°СЂС‹Р№ РїРѕРёСЃРє)
        if (empty($importAttributes) || !is_array($importAttributes)) {
            return null;
        }

        // РЎС‚СЂРѕРёРј Р·Р°РїСЂРѕСЃ РґР»СЏ РїРѕРёСЃРєР° РІР°СЂРёР°С†РёР№ РїРѕ РёРјРµРЅРё/SKU
        $query = ShopGoodVariation::where($searchField, $searchField === 'name' ? $this->normalizeTextForSearch($searchValue) : $searchValue);

        // Р¤РёР»СЊС‚СЂ РїРѕ РїРѕСЃС‚Р°РІС‰РёРєСѓ, РµСЃР»Рё СѓРєР°Р·Р°РЅ
        if ($supplier !== null && $supplier !== '') {
            $query->where('supplier', $supplier);
        }

        // РџРѕР»СѓС‡Р°РµРј РІСЃРµ РЅР°Р№РґРµРЅРЅС‹Рµ РІР°СЂРёР°С†РёРё
        $variations = $query->get();

        // Р”Р»СЏ РєР°Р¶РґРѕР№ РІР°СЂРёР°С†РёРё РїСЂРѕРІРµСЂСЏРµРј СЃРѕРІРїР°РґРµРЅРёРµ Р°С‚СЂРёР±СѓС‚РѕРІ
        foreach ($variations as $variation) {
            // РџРѕР»СѓС‡Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµР№ РІР°СЂРёР°С†РёРё
            $existingAttributes = $this->getVariationAttributes($variation);

            // РЎСЂР°РІРЅРёРІР°РµРј Р°С‚СЂРёР±СѓС‚С‹
            if ($this->attributesMatch($existingAttributes, $importAttributes)) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * РџСЂРѕРІРµСЂСЏРµС‚, СЃРѕРІРїР°РґР°СЋС‚ Р»Рё РґРІР° РјР°СЃСЃРёРІР° Р°С‚СЂРёР±СѓС‚РѕРІ
     */
    private function attributesMatch($existingAttributes, $importAttributes)
    {
        if (count($importAttributes) !== count($existingAttributes)) {
            return false;
        }

        // РЎРѕСЂС‚РёСЂСѓРµРј РѕР±Р° РјР°СЃСЃРёРІР° РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ
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
     * РќР°Р№С‚Рё РІР°СЂРёР°С†РёСЋ РїРѕ РєРѕРјР±РёРЅР°С†РёРё Р°С‚СЂРёР±СѓС‚РѕРІ
     */
    private function findVariationByAttributes($goodId, $attributeValueIds, $supplier = null)
    {
        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РјР°СЃСЃРёРІ Р°С‚СЂРёР±СѓС‚РѕРІ РЅРµ РїСѓСЃС‚РѕР№
        if (empty($attributeValueIds)) {
            return null;
        }

        sort($attributeValueIds); // РЎРѕСЂС‚РёСЂСѓРµРј РґР»СЏ РєРѕРЅСЃРёСЃС‚РµРЅС‚РЅРѕСЃС‚Рё

        // РЎРЅР°С‡Р°Р»Р° РёС‰РµРј РІР°СЂРёР°С†РёСЋ СЃСЂРµРґРё РІР°СЂРёР°С†РёР№ СѓРєР°Р·Р°РЅРЅРѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР° (РµСЃР»Рё РїРѕСЃС‚Р°РІС‰РёРє СѓРєР°Р·Р°РЅ)
        if ($supplier !== null) {
            $supplierVariations = ShopGoodVariation::where('good_id', $goodId)
                ->where('supplier', $supplier)
                ->get();

            foreach ($supplierVariations as $variation) {
                // РџРѕР»СѓС‡Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµР№ РІР°СЂРёР°С†РёРё
                $existingAttributeValueIds = DB::table('shop_variation_attributes_values')
                    ->where('variation_id', $variation->id)
                    ->pluck('attribute_value_id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->toArray();

                sort($existingAttributeValueIds);

                // РЎСЂР°РІРЅРёРІР°РµРј РєРѕРјР±РёРЅР°С†РёРё Р°С‚СЂРёР±СѓС‚РѕРІ
                if ($existingAttributeValueIds === $attributeValueIds) {
                    return $variation;
                }
            }
        }

        // Р•СЃР»Рё РЅРµ РЅР°Р№РґРµРЅР° РІР°СЂРёР°С†РёСЏ РїРѕСЃС‚Р°РІС‰РёРєР° РёР»Рё РїРѕСЃС‚Р°РІС‰РёРє РЅРµ СѓРєР°Р·Р°РЅ,
        // РёС‰РµРј СЃСЂРµРґРё РІР°СЂРёР°С†РёР№ Р±РµР· РїСЂРёРІСЏР·Р°РЅРЅРѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР° (null РёР»Рё РїСѓСЃС‚Р°СЏ СЃС‚СЂРѕРєР°)
        $nullSupplierVariations = ShopGoodVariation::where('good_id', $goodId)
            ->where(function ($query) {
                $query->whereNull('supplier')
                    ->orWhere('supplier', '');
            })
            ->get();

        foreach ($nullSupplierVariations as $variation) {
            // РџРѕР»СѓС‡Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµР№ РІР°СЂРёР°С†РёРё
            $existingAttributeValueIds = DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->pluck('attribute_value_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            sort($existingAttributeValueIds);

            // РЎСЂР°РІРЅРёРІР°РµРј РєРѕРјР±РёРЅР°С†РёРё Р°С‚СЂРёР±СѓС‚РѕРІ
            if ($existingAttributeValueIds === $attributeValueIds) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * РћР±РЅСѓР»РёС‚СЊ РѕСЃС‚Р°С‚РєРё Сѓ РІСЃРµС… С‚РѕРІР°СЂРѕРІ СѓРєР°Р·Р°РЅРЅРѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°
     */
    private function resetSupplierStock($supplierId)
    {
        try {
            // РџРѕР»СѓС‡Р°РµРј РёРјСЏ РїРѕСЃС‚Р°РІС‰РёРєР° РїРѕ ID
            $supplier = ShopSupplier::find($supplierId);
            if (!$supplier) {
                return;
            }
            $supplierName = $supplier->name;

            // РћР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё РЅР° СѓРґР°Р»РµРЅРЅРѕРј СЃРєР»Р°РґРµ Рё РѕСЃС‚Р°С‚РєРё Сѓ/СЃ Р±С‹СЃС‚СЂРѕ Сѓ С‚РѕРІР°СЂРѕРІ СЃ СѓРєР°Р·Р°РЅРЅС‹Рј РїРѕСЃС‚Р°РІС‰РёРєРѕРј
            ShopGood::where('supplier_id', $supplierId)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null,
                ]);

            // РљР РРўРР§РќРћ: РћР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё Сѓ РІР°СЂРёР°С†РёР№, РєРѕС‚РѕСЂС‹Рµ РїСЂРёРІСЏР·Р°РЅС‹ Рє РїРѕСЃС‚Р°РІС‰РёРєСѓ РїРѕ РїРѕР»СЋ supplier РІР°СЂРёР°С†РёРё,
            // Р° РЅРµ С‡РµСЂРµР· СЃРІСЏР·СЊ СЃ С‚РѕРІР°СЂРѕРј. Р’Р°СЂРёР°С†РёРё РјРѕРіСѓС‚ Р±С‹С‚СЊ РїСЂРёРІСЏР·Р°РЅС‹ Рє РґСЂСѓРіРѕРјСѓ РїРѕСЃС‚Р°РІС‰РёРєСѓ, С‡РµРј РіР»Р°РІРЅС‹Р№ С‚РѕРІР°СЂ.
            ShopGoodVariation::where('supplier', $supplierName)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null,
                ]);
        } catch (\Exception $e) {
            // Р›РѕРіРёСЂСѓРµРј РѕС€РёР±РєСѓ, РЅРѕ РЅРµ РїСЂРµСЂС‹РІР°РµРј РёРјРїРѕСЂС‚
            Log::error('РћС€РёР±РєР° РїСЂРё РѕР±РЅСѓР»РµРЅРёРё РѕСЃС‚Р°С‚РєРѕРІ РїРѕСЃС‚Р°РІС‰РёРєР°: ' . $e->getMessage());
        }
    }

    /**
     * РћР±РЅСѓР»СЏРµС‚ РѕСЃС‚Р°С‚РєРё С‚РѕРІР°СЂРѕРІ Рё РІР°СЂРёР°С†РёР№ РїРѕСЃС‚Р°РІС‰РёРєР°
     */
    private function resetSupplierStocks($supplierName, $supplierStockFields = null)
    {
        $updatedGoods = 0;
        $updatedVariations = 0;

        // Р•СЃР»Рё РЅРµ СѓРєР°Р·Р°РЅС‹ РїРѕР»СЏ РґР»СЏ РѕР±РЅСѓР»РµРЅРёСЏ, РѕР±РЅСѓР»СЏРµРј РІСЃРµ (РѕР±СЂР°С‚РЅР°СЏ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚СЊ)
        if ($supplierStockFields === null) {
            $supplierStockFields = [
                'stock_quantity' => true,
                'remote_stock_quantity' => true,
                'fast_remote_stock_quantity' => true,
            ];
        } else {
        }

        // Р›РѕРіРёСЂСѓРµРј РїРѕР»СѓС‡РµРЅРЅС‹Рµ РЅР°СЃС‚СЂРѕР№РєРё РїРѕР»РµР№ РѕСЃС‚Р°С‚РєРѕРІ
        try {
            // РЎРЅР°С‡Р°Р»Р° РїСЂРѕРІРµСЂРёРј, СЃРєРѕР»СЊРєРѕ С‚РѕРІР°СЂРѕРІ Сѓ РїРѕСЃС‚Р°РІС‰РёРєР°
            $goodsCount = ShopGood::where('supplier', $supplierName)->count();

            // РџРѕР»СѓС‡Р°РµРј С‚РѕРІР°СЂС‹ РїРµСЂРµРґ РѕР±РЅРѕРІР»РµРЅРёРµРј РґР»СЏ РїСЂРѕРІРµСЂРєРё
            $goodsBefore = ShopGood::where('supplier', $supplierName)
                ->select('id', 'name', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity')
                ->limit(5)
                ->get()
                ->toArray();

            // Р¤РѕСЂРјРёСЂСѓРµРј РјР°СЃСЃРёРІ РїРѕР»РµР№ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ РЅР° РѕСЃРЅРѕРІРµ РЅР°СЃС‚СЂРѕРµРє
            $updateFields = [];

            // РџСЂРµРѕР±СЂР°Р·СѓРµРј Р·РЅР°С‡РµРЅРёСЏ РІ boolean РґР»СЏ РЅР°РґРµР¶РЅРѕСЃС‚Рё
            $stockQuantityEnabled = isset($supplierStockFields['stock_quantity']) &&
                filter_var($supplierStockFields['stock_quantity'], FILTER_VALIDATE_BOOLEAN);
            $remoteStockEnabled = isset($supplierStockFields['remote_stock_quantity']) &&
                filter_var($supplierStockFields['remote_stock_quantity'], FILTER_VALIDATE_BOOLEAN);
            $fastRemoteStockEnabled = isset($supplierStockFields['fast_remote_stock_quantity']) &&
                filter_var($supplierStockFields['fast_remote_stock_quantity'], FILTER_VALIDATE_BOOLEAN);

            if ($stockQuantityEnabled) {
                $updateFields['stock_quantity'] = 0; // Р§РёСЃР»РѕРІРѕРµ РїРѕР»Рµ - СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј 0
            }
            if ($remoteStockEnabled) {
                $updateFields['remote_stock_quantity'] = null; // РЎС‚СЂРѕРєРѕРІРѕРµ РїРѕР»Рµ - СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј null
            }
            if ($fastRemoteStockEnabled) {
                $updateFields['fast_remote_stock_quantity'] = null; // РЎС‚СЂРѕРєРѕРІРѕРµ РїРѕР»Рµ - СѓСЃС‚Р°РЅР°РІР»РёРІР°РµРј null
            }

            // Р•СЃР»Рё РЅРµС‚ РїРѕР»РµР№ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ, РїСЂРѕРїСѓСЃРєР°РµРј
            if (empty($updateFields)) {
                return [
                    'goods_updated' => 0,
                    'variations_updated' => 0,
                    'message' => 'РќРµС‚ РїРѕР»РµР№ РѕСЃС‚Р°С‚РєРѕРІ РґР»СЏ РѕР±РЅСѓР»РµРЅРёСЏ',
                ];
            }

            // РћР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё Р’РЎР•РҐ С‚РѕРІР°СЂРѕРІ РїРѕСЃС‚Р°РІС‰РёРєР° С‚РѕР»СЊРєРѕ РґР»СЏ РІС‹Р±СЂР°РЅРЅС‹С… РїРѕР»РµР№
            $goodsUpdated = ShopGood::where('supplier', $supplierName)
                ->update($updateFields);

            $updatedGoods = $goodsUpdated;

            // РџРѕР»СѓС‡Р°РµРј С‚РѕРІР°СЂС‹ РїРѕСЃР»Рµ РѕР±РЅРѕРІР»РµРЅРёСЏ РґР»СЏ РїСЂРѕРІРµСЂРєРё
            $goodsAfter = ShopGood::where('supplier', $supplierName)
                ->select('id', 'name', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity')
                ->limit(5)
                ->get()
                ->toArray();

            // РџСЂРѕРІРµСЂСЏРµРј С‚РѕРІР°СЂС‹ РїРѕСЃР»Рµ РѕР±РЅРѕРІР»РµРЅРёСЏ
            $goodsAfter = ShopGood::where('supplier', $supplierName)
                ->select('id', 'name', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity')
                ->limit(5)
                ->get()
                ->toArray();

            // РџСЂРѕРІРµСЂСЏРµРј РІР°СЂРёР°С†РёРё СЃ СѓРєР°Р·Р°РЅРЅС‹Рј РїРѕСЃС‚Р°РІС‰РёРєРѕРј
            $variationsCount = ShopGoodVariation::where('supplier', $supplierName)->count();

            // РћР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёР№ СЃ СѓРєР°Р·Р°РЅРЅС‹Рј РїРѕСЃС‚Р°РІС‰РёРєРѕРј С‚РѕР»СЊРєРѕ РґР»СЏ РІС‹Р±СЂР°РЅРЅС‹С… РїРѕР»РµР№
            $variationsUpdated = ShopGoodVariation::where('supplier', $supplierName)
                ->update($updateFields);

            $updatedVariations = $variationsUpdated;

        } catch (\Exception $e) {
            Log::error('РћС€РёР±РєР° РїСЂРё РѕР±РЅСѓР»РµРЅРёРё РѕСЃС‚Р°С‚РєРѕРІ РїРѕСЃС‚Р°РІС‰РёРєР°', [
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
     * РћР±РЅСѓР»СЏРµС‚ РѕСЃС‚Р°С‚РєРё РІСЃРµС… С‚РѕРІР°СЂРѕРІ Рё РІР°СЂРёР°С†РёР№
     */
    private function resetAllStocks()
    {
        $updatedGoods = 0;
        $updatedVariations = 0;

        try {
            // РћР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё РІСЃРµС… С‚РѕРІР°СЂРѕРІ
            $goodsUpdated = ShopGood::query()->update([
                'stock_quantity' => 0,
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null,
            ]);

            $updatedGoods = $goodsUpdated;

            // РћР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё РІСЃРµС… РІР°СЂРёР°С†РёР№
            $variationsUpdated = ShopGoodVariation::query()->update([
                'stock_quantity' => 0,
                'remote_stock_quantity' => null,
                'fast_remote_stock_quantity' => null,
            ]);

            $updatedVariations = $variationsUpdated;

        } catch (\Exception $e) {
            Log::error('РћС€РёР±РєР° РїСЂРё РѕР±РЅСѓР»РµРЅРёРё РѕСЃС‚Р°С‚РєРѕРІ РІСЃРµС… С‚РѕРІР°СЂРѕРІ', [
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

    /**
     * РћРўРљР›Р®Р§Р•РќРћ: РўРµСЃС‚РѕРІС‹Р№ РјРµС‚РѕРґ РґР»СЏ РїСЂРѕРІРµСЂРєРё РѕР±РЅСѓР»РµРЅРёСЏ РѕСЃС‚Р°С‚РєРѕРІ РїРѕСЃС‚Р°РІС‰РёРєР°
     */
    public function testResetSupplierStocks(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'РћР±РЅСѓР»РµРЅРёРµ РѕСЃС‚Р°С‚РєРѕРІ РѕС‚РєР»СЋС‡РµРЅРѕ',
            'reason' => 'Р¤СѓРЅРєС†РёСЏ РѕР±РЅСѓР»РµРЅРёСЏ РѕСЃС‚Р°С‚РєРѕРІ РїРѕР»РЅРѕСЃС‚СЊСЋ РѕС‚РєР»СЋС‡РµРЅР° РїРѕ С‚СЂРµР±РѕРІР°РЅРёСЋ',
        ], 403);
    }

    /**
     * РћРўРљР›Р®Р§Р•РќРћ: РўРµСЃС‚РѕРІС‹Р№ РјРµС‚РѕРґ РґР»СЏ РїСЂРѕРІРµСЂРєРё РѕР±РЅСѓР»РµРЅРёСЏ РѕСЃС‚Р°С‚РєРѕРІ РІСЃРµС… С‚РѕРІР°СЂРѕРІ
     */
    public function testResetAllStocks(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'РћР±РЅСѓР»РµРЅРёРµ РѕСЃС‚Р°С‚РєРѕРІ РѕС‚РєР»СЋС‡РµРЅРѕ',
            'reason' => 'Р¤СѓРЅРєС†РёСЏ РѕР±РЅСѓР»РµРЅРёСЏ РѕСЃС‚Р°С‚РєРѕРІ РїРѕР»РЅРѕСЃС‚СЊСЋ РѕС‚РєР»СЋС‡РµРЅР° РїРѕ С‚СЂРµР±РѕРІР°РЅРёСЋ',
        ], 403);
    }

    /**
     * РћР±РЅСѓР»СЏРµС‚ РѕСЃС‚Р°С‚РєРё С‚РѕРІР°СЂРѕРІ РїРѕСЃС‚Р°РІС‰РёРєР° РїРѕ РёРјРµРЅРё (С‚РµРєСЃС‚РѕРІРѕРµ РїРѕР»Рµ)
     */
    private function resetSupplierStockByName($supplierName)
    {
        try {
            // РћР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё РЅР° СѓРґР°Р»РµРЅРЅРѕРј СЃРєР»Р°РґРµ Рё РѕСЃС‚Р°С‚РєРё Сѓ/СЃ Р±С‹СЃС‚СЂРѕ Сѓ С‚РѕРІР°СЂРѕРІ СЃ СѓРєР°Р·Р°РЅРЅС‹Рј РїРѕСЃС‚Р°РІС‰РёРєРѕРј
            ShopGood::where('supplier', $supplierName)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null,
                ]);

            // РљР РРўРР§РќРћ: РћР±РЅСѓР»СЏРµРј РѕСЃС‚Р°С‚РєРё Сѓ РІР°СЂРёР°С†РёР№, РєРѕС‚РѕСЂС‹Рµ РїСЂРёРІСЏР·Р°РЅС‹ Рє РїРѕСЃС‚Р°РІС‰РёРєСѓ РїРѕ РїРѕР»СЋ supplier РІР°СЂРёР°С†РёРё,
            // Р° РЅРµ С‡РµСЂРµР· СЃРІСЏР·СЊ СЃ С‚РѕРІР°СЂРѕРј. Р’Р°СЂРёР°С†РёРё РјРѕРіСѓС‚ Р±С‹С‚СЊ РїСЂРёРІСЏР·Р°РЅС‹ Рє РґСЂСѓРіРѕРјСѓ РїРѕСЃС‚Р°РІС‰РёРєСѓ, С‡РµРј РіР»Р°РІРЅС‹Р№ С‚РѕРІР°СЂ.
            ShopGoodVariation::where('supplier', $supplierName)
                ->update([
                    'remote_stock_quantity' => null,
                    'fast_remote_stock_quantity' => null,
                ]);
        } catch (\Exception $e) {
            // Р›РѕРіРёСЂСѓРµРј РѕС€РёР±РєСѓ, РЅРѕ РЅРµ РїСЂРµСЂС‹РІР°РµРј РёРјРїРѕСЂС‚
            Log::error('РћС€РёР±РєР° РїСЂРё РѕР±РЅСѓР»РµРЅРёРё РѕСЃС‚Р°С‚РєРѕРІ РїРѕСЃС‚Р°РІС‰РёРєР° РїРѕ РёРјРµРЅРё: ' . $e->getMessage());
        }
    }

    /**
     * РџСЂРѕРІРµСЂСЏРµС‚, СЃРѕРІРїР°РґР°СЋС‚ Р»Рё Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё СЃ Р°С‚СЂРёР±СѓС‚Р°РјРё РІ goodData
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

        // РЎРѕСЂС‚РёСЂСѓРµРј РѕР±Р° РјР°СЃСЃРёРІР° РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ
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
     * РџРѕР»СѓС‡Р°РµС‚ Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё РІ С„РѕСЂРјР°С‚Рµ РјР°СЃСЃРёРІР°
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
     * РџР°СЂСЃРёС‚ Р·Р°РіСЂСѓР¶РµРЅРЅС‹Р№ YML С„Р°Р№Р» РїРѕ РёРјРµРЅРё С„Р°Р№Р»Р°
     */
    public function parseUploadedYMLFile(Request $request)
    {
        try {
            $request->validate([
                'filename' => 'required|string',
            ]);

            $filename = $request->input('filename');

            // Р’Р°Р»РёРґР°С†РёСЏ РёРјРµРЅРё С„Р°Р№Р»Р° РґР»СЏ Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё
            if (!preg_match('/^temp_[a-f0-9\-]+\.(xml|yml|txt)$/i', $filename)) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµРІРµСЂРЅРѕРµ РёРјСЏ С„Р°Р№Р»Р°',
                ], 400);
            }

            $filePath = storage_path('app/temp/' . $filename);

            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Р¤Р°Р№Р» РЅРµ РЅР°Р№РґРµРЅ',
                ], 404);
            }

            // Р§РёС‚Р°РµРј СЃРѕРґРµСЂР¶РёРјРѕРµ С„Р°Р№Р»Р°
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµ СѓРґР°Р»РѕСЃСЊ РїСЂРѕС‡РёС‚Р°С‚СЊ С„Р°Р№Р»',
                ], 500);
            }

            // РџР°СЂСЃРёРј YML/XML
            $ymlData = $this->parseYMLContent($fileContent);

            return response()->json([
                'success' => true,
                'ymlData' => $ymlData,
            ]);

        } catch (\Exception $e) {
            \Log::error('РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° Р·Р°РіСЂСѓР¶РµРЅРЅРѕРіРѕ YML С„Р°Р№Р»Р°', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Р¤РѕСЂРјРёСЂСѓРµРј Р±РѕР»РµРµ РїРѕРЅСЏС‚РЅРѕРµ СЃРѕРѕР±С‰РµРЅРёРµ РѕР± РѕС€РёР±РєРµ
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Opening and ending tag mismatch') !== false) {
                $errorMessage = 'РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° YML: РќРµСЃРѕРѕС‚РІРµС‚СЃС‚РІРёРµ РѕС‚РєСЂС‹РІР°СЋС‰РёС… Рё Р·Р°РєСЂС‹РІР°СЋС‰РёС… С‚РµРіРѕРІ РІ XML С„Р°Р№Р»Рµ. ' .
                    'РџСЂРѕРІРµСЂСЊС‚Рµ РєРѕСЂСЂРµРєС‚РЅРѕСЃС‚СЊ СЃС‚СЂСѓРєС‚СѓСЂС‹ XML С„Р°Р№Р»Р°. ' .
                    'Р’РѕР·РјРѕР¶РЅРѕ, РЅРµРєРѕС‚РѕСЂС‹Рµ С‚РµРіРё РЅРµ Р·Р°РєСЂС‹С‚С‹ РёР»Рё Р·Р°РєСЂС‹С‚С‹ РЅРµРїСЂР°РІРёР»СЊРЅРѕ.';
            } elseif (strpos($errorMessage, 'parser error') !== false) {
                $errorMessage = 'РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° YML: ' . $errorMessage;
            }

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'message' => $errorMessage,
            ], 500);
        }
    }

    /**
     * РџР°СЂСЃРёС‚ СЃРѕРґРµСЂР¶РёРјРѕРµ YML/XML СЃС‚СЂРѕРєРё
     */
    private function parseYMLContent($content)
    {
        try {
            // Р’РєР»СЋС‡Р°РµРј РѕР±СЂР°Р±РѕС‚РєСѓ РѕС€РёР±РѕРє РґР»СЏ РїРѕР»СѓС‡РµРЅРёСЏ РґРµС‚Р°Р»СЊРЅРѕР№ РёРЅС„РѕСЂРјР°С†РёРё РѕР± РѕС€РёР±РєР°С… РїР°СЂСЃРёРЅРіР°
            libxml_use_internal_errors(true);
            libxml_clear_errors();

            // Р—Р°РіСЂСѓР¶Р°РµРј XML
            $xml = simplexml_load_string($content);

            // РџРѕР»СѓС‡Р°РµРј РѕС€РёР±РєРё РїР°СЂСЃРёРЅРіР°, РµСЃР»Рё РѕРЅРё РµСЃС‚СЊ
            $xmlErrors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            if ($xml === false) {
                $errorMessage = 'РќРµ СѓРґР°Р»РѕСЃСЊ Р·Р°РіСЂСѓР·РёС‚СЊ XML';
                if (!empty($xmlErrors)) {
                    $errorDetails = [];
                    foreach ($xmlErrors as $error) {
                        $errorDetails[] = sprintf(
                            'РЎС‚СЂРѕРєР° %d: %s',
                            $error->line,
                            trim($error->message)
                        );
                    }
                    $errorMessage .= '. ' . implode('; ', $errorDetails);
                }
                throw new \Exception($errorMessage);
            }

            // РћРїСЂРµРґРµР»СЏРµРј СЃС‚СЂСѓРєС‚СѓСЂСѓ С„Р°Р№Р»Р°
            $isStandardYML = isset($xml->shop) && isset($xml->shop->offers);
            $isCustomFormat = isset($xml->shop) && isset($xml->shop->items);
            $alternativeStructure = false;

            if (!$isStandardYML && !$isCustomFormat) {
                // РџРѕРїСЂРѕР±СѓРµРј РЅР°Р№С‚Рё РґСЂСѓРіРёРµ РІРѕР·РјРѕР¶РЅС‹Рµ СЃС‚СЂСѓРєС‚СѓСЂС‹
                $alternativeStructure = $this->detectAlternativeXMLStructure($xml);
                if (!$alternativeStructure) {
                    throw new \Exception('Р¤Р°Р№Р» РЅРµ СЃРѕРґРµСЂР¶РёС‚ РєРѕСЂСЂРµРєС‚РЅСѓСЋ СЃС‚СЂСѓРєС‚СѓСЂСѓ YML РёР»Рё РїРѕРґРґРµСЂР¶РёРІР°РµРјРѕРіРѕ С„РѕСЂРјР°С‚Р° XML');
                }
            }

            // РџР°СЂСЃРёРј С‚РѕРІР°СЂС‹ Рё РєР°С‚РµРіРѕСЂРёРё
            $categories = [];
            $offers = [];
            $shop = null;

            if ($isStandardYML) {
                // РЎС‚Р°РЅРґР°СЂС‚РЅС‹Р№ YML С„РѕСЂРјР°С‚
                $shop = $xml->shop;

                // РџР°СЂСЃРёРј РєР°С‚РµРіРѕСЂРёРё
                if (isset($shop->categories) && isset($shop->categories->category)) {
                    foreach ($shop->categories->category as $category) {
                        $categories[] = [
                            'id' => (string) $category['id'],
                            'parentId' => (string) ($category['parentId'] ?? null),
                            'name' => (string) $category,
                        ];
                    }
                }

                // РџР°СЂСЃРёРј С‚РѕРІР°СЂС‹
                if (isset($shop->offers) && isset($shop->offers->offer)) {
                    foreach ($shop->offers->offer as $offer) {
                        $offers[] = $this->parseStandardYMLOffer($offer);
                    }
                }
            } elseif ($isCustomFormat) {
                // РљР°СЃС‚РѕРјРЅС‹Р№ С„РѕСЂРјР°С‚ (С‚РёРїР° Р’Р•Р›Рћ_2025-12-22.xml)
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
                // РђР»СЊС‚РµСЂРЅР°С‚РёРІРЅР°СЏ СЃС‚СЂСѓРєС‚СѓСЂР° XML
                [$categories, $offers] = $this->parseAlternativeXMLStructure($xml, $alternativeStructure);
                // Р”Р»СЏ Р°Р»СЊС‚РµСЂРЅР°С‚РёРІРЅС‹С… СЃС‚СЂСѓРєС‚СѓСЂ shop РјРѕР¶РµС‚ Р±С‹С‚СЊ РІ РґСЂСѓРіРѕРј РјРµСЃС‚Рµ РёР»Рё РѕС‚СЃСѓС‚СЃС‚РІРѕРІР°С‚СЊ
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
            throw new \Exception('РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° YML: ' . $e->getMessage());
        }
    }

    /**
     * РџР°СЂСЃРёС‚ С‚РѕРІР°СЂ РІ СЃС‚Р°РЅРґР°СЂС‚РЅРѕРј YML С„РѕСЂРјР°С‚Рµ
     */
    private function parseStandardYMLOffer($offer)
    {
        $rawXmlPrice = (string) ($offer->price ?? 0);
        $parsedPrice = (float) preg_replace('/\s+/', '', str_replace(["\xC2\xA0", 'СЂСѓР±.', 'СЂСѓР±', 'СЂ.', 'СЂ'], '', $rawXmlPrice));

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
     * РџР°СЂСЃРёС‚ С‚РѕРІР°СЂ РІ РєР°СЃС‚РѕРјРЅРѕРј С„РѕСЂРјР°С‚Рµ (Р’Р•Р›Рћ_2025-12-22.xml)
     */
    private function parseCustomFormatItem($item, &$categories)
    {
        $rawPrice = (string) $item->price;

        // РСЃРїРѕР»СЊР·СѓРµРј СЂР°СЃС€РёСЂРµРЅРЅСѓСЋ РѕС‡РёСЃС‚РєСѓ С†РµРЅС‹
        $cleanedPrice = str_replace("\xC2\xA0", '', $rawPrice); // РЈРґР°Р»СЏРµРј РЅРµСЂР°Р·СЂС‹РІРЅС‹Р№ РїСЂРѕР±РµР» (UTF-8: C2 A0)
        $cleanedPrice = preg_replace('/\s+/', '', $cleanedPrice); // РЈРґР°Р»СЏРµРј РѕСЃС‚Р°Р»СЊРЅС‹Рµ РїСЂРѕР±РµР»СЊРЅС‹Рµ СЃРёРјРІРѕР»С‹
        $cleanedPrice = str_replace(['СЂСѓР±.', 'СЂСѓР±', 'СЂ.', 'СЂ'], '', $cleanedPrice); // РЈРґР°Р»СЏРµРј РѕР±РѕР·РЅР°С‡РµРЅРёСЏ СЂСѓР±Р»РµР№

        $parsedPrice = (float) $cleanedPrice;

        // РР·РІР»РµРєР°РµРј РґР°РЅРЅС‹Рµ С‚РѕРІР°СЂР°
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

        // РџР°СЂСЃРёРј РїР°СЂР°РјРµС‚СЂС‹
        if (isset($item->param)) {
            foreach ($item->param as $param) {
                $paramName = (string) $param['name'];
                $paramValue = (string) $param;

                $offerData['params'][] = [
                    'name' => $paramName,
                    'value' => $paramValue,
                ];

                // РР·РІР»РµРєР°РµРј РїСЂРѕРёР·РІРѕРґРёС‚РµР»СЏ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ
                if ($paramName === 'РџСЂРѕРёР·РІРѕРґРёС‚РµР»СЊ') {
                    $offerData['vendor'] = $paramValue;
                }
            }
        }

        // РЎРѕР·РґР°РµРј РєР°С‚РµРіРѕСЂРёРё РёР· level РґР°РЅРЅС‹С…
        $this->extractCategoriesFromItem($item, $categories);

        return $offerData;
    }

    /**
     * РџР°СЂСЃРёС‚ РїР°СЂР°РјРµС‚СЂС‹ YML С‚РѕРІР°СЂР°
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
     * РР·РІР»РµРєР°РµС‚ РєР°С‚РµРіРѕСЂРёРё РёР· level РїРѕР»РµР№ С‚РѕРІР°СЂР°
     */
    private function extractCategoriesFromItem($item, &$categories)
    {
        // РџСЂРѕС…РѕРґРёРј РїРѕ РІСЃРµРј level РїРѕР»СЏРј (РѕС‚ 0 РґРѕ 3)
        for ($level = 0; $level <= 3; $level++) {
            $idField = "level_id_$level";
            $nameField = "level_name_$level";

            if (isset($item->$idField) && isset($item->$nameField)) {
                $categoryId = (string) $item->$idField;
                $categoryName = (string) $item->$nameField;

                // РџСЂРѕРІРµСЂСЏРµРј, РЅРµ РґРѕР±Р°РІР»РµРЅР° Р»Рё СѓР¶Рµ СЌС‚Р° РєР°С‚РµРіРѕСЂРёСЏ
                $existingCategory = array_filter($categories, function ($cat) use ($categoryId) {
                    return $cat['id'] === $categoryId;
                });

                if (empty($existingCategory)) {
                    // РћРїСЂРµРґРµР»СЏРµРј parentId (РїСЂРµРґС‹РґСѓС‰РёР№ СѓСЂРѕРІРµРЅСЊ)
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
     * РћРїСЂРµРґРµР»СЏРµС‚ Р°Р»СЊС‚РµСЂРЅР°С‚РёРІРЅС‹Рµ СЃС‚СЂСѓРєС‚СѓСЂС‹ XML С„Р°Р№Р»РѕРІ
     */
    private function detectAlternativeXMLStructure($xml)
    {
        // РџСЂРѕРІРµСЂСЏРµРј СЂР°Р·Р»РёС‡РЅС‹Рµ РІРѕР·РјРѕР¶РЅС‹Рµ СЃС‚СЂСѓРєС‚СѓСЂС‹

        // РЎС‚СЂСѓРєС‚СѓСЂР°: <yml_catalog><shop><items><item>...</item></items></shop></yml_catalog>
        if ($xml->getName() === 'yml_catalog' && isset($xml->shop) && isset($xml->shop->items)) {
            return 'yml_catalog_custom';
        }

        // РЎС‚СЂСѓРєС‚СѓСЂР°: <offers><offer>...</offer></offers> (Р±РµР· shop)
        if ($xml->getName() === 'offers' && isset($xml->offer)) {
            return 'offers_only';
        }

        // РЎС‚СЂСѓРєС‚СѓСЂР°: <items><item>...</item></items> (Р±РµР· shop)
        if ($xml->getName() === 'items' && isset($xml->item)) {
            return 'items_only';
        }

        // Р”СЂСѓРіРёРµ РІРѕР·РјРѕР¶РЅС‹Рµ СЃС‚СЂСѓРєС‚СѓСЂС‹ РјРѕР¶РЅРѕ РґРѕР±Р°РІРёС‚СЊ Р·РґРµСЃСЊ

        return false;
    }

    /**
     * РџР°СЂСЃРёС‚ С‚РѕРІР°СЂС‹ РёР· Р°Р»СЊС‚РµСЂРЅР°С‚РёРІРЅС‹С… СЃС‚СЂСѓРєС‚СѓСЂ XML
     */
    private function parseAlternativeXMLStructure($xml, $structureType)
    {
        $categories = [];
        $offers = [];

        switch ($structureType) {
            case 'yml_catalog_custom':
                // РЎС‚СЂСѓРєС‚СѓСЂР°: <yml_catalog><shop><items><item>...</item></items></shop></yml_catalog>
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
                // РЎС‚СЂСѓРєС‚СѓСЂР°: <offers><offer>...</offer></offers>
                if (isset($xml->offer)) {
                    foreach ($xml->offer as $offer) {
                        $offers[] = $this->parseStandardYMLOffer($offer);
                    }
                }
                break;

            case 'items_only':
                // РЎС‚СЂСѓРєС‚СѓСЂР°: <items><item>...</item></items>
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
     * РџР°СЂСЃРёС‚ YML/XML РїРѕ URL
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

            // Р—Р°РіСЂСѓР¶Р°РµРј XML РїРѕ URL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            // HTTP Basic Authentication, РµСЃР»Рё СѓРєР°Р·Р°РЅС‹ СѓС‡РµС‚РЅС‹Рµ РґР°РЅРЅС‹Рµ
            if (!empty($username) && !empty($password)) {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
            }

            // РќР°СЃС‚СЂРѕР№РєРё SSL (Р°РЅР°Р»РѕРіРёС‡РЅРѕ РјРµС‚РѕРґСѓ proxyYMLFeed)
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
                $errorMessage = $curlError ?: 'РќРµРёР·РІРµСЃС‚РЅР°СЏ РѕС€РёР±РєР° cURL';
                if ($curlErrno) {
                    $errorMessage .= ' (РєРѕРґ РѕС€РёР±РєРё: ' . $curlErrno . ')';
                }

                \Log::error('РћС€РёР±РєР° cURL РїСЂРё Р·Р°РіСЂСѓР·РєРµ YML РїРѕ URL', [
                    'url' => $url,
                    'curl_error' => $curlError,
                    'curl_errno' => $curlErrno,
                    'http_code' => $httpCode,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'РћС€РёР±РєР° Р·Р°РіСЂСѓР·РєРё С„РёРґР°: ' . $errorMessage,
                ], 500);
            }

            if ($httpCode !== 200) {
                return response()->json([
                    'success' => false,
                    'error' => "РћС€РёР±РєР° Р·Р°РіСЂСѓР·РєРё С„РёРґР°: HTTP {$httpCode}",
                ], $httpCode);
            }

            if (empty($xmlContent)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Р¤РёРґ РїСѓСЃС‚',
                ], 400);
            }

            // РџР°СЂСЃРёРј XML СЃРѕРґРµСЂР¶РёРјРѕРµ
            $ymlData = $this->parseYMLContent($xmlContent);

            return response()->json([
                'success' => true,
                'ymlData' => $ymlData,
            ]);

        } catch (\Exception $e) {
            \Log::error('РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° YML РїРѕ URL', [
                'url' => $request->input('url'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Р¤РѕСЂРјРёСЂСѓРµРј Р±РѕР»РµРµ РїРѕРЅСЏС‚РЅРѕРµ СЃРѕРѕР±С‰РµРЅРёРµ РѕР± РѕС€РёР±РєРµ
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Opening and ending tag mismatch') !== false) {
                $errorMessage = 'РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° YML: РќРµСЃРѕРѕС‚РІРµС‚СЃС‚РІРёРµ РѕС‚РєСЂС‹РІР°СЋС‰РёС… Рё Р·Р°РєСЂС‹РІР°СЋС‰РёС… С‚РµРіРѕРІ РІ XML С„Р°Р№Р»Рµ. ' .
                    'РџСЂРѕРІРµСЂСЊС‚Рµ РєРѕСЂСЂРµРєС‚РЅРѕСЃС‚СЊ СЃС‚СЂСѓРєС‚СѓСЂС‹ XML С„Р°Р№Р»Р°. ' .
                    'Р’РѕР·РјРѕР¶РЅРѕ, РЅРµРєРѕС‚РѕСЂС‹Рµ С‚РµРіРё РЅРµ Р·Р°РєСЂС‹С‚С‹ РёР»Рё Р·Р°РєСЂС‹С‚С‹ РЅРµРїСЂР°РІРёР»СЊРЅРѕ.';
            } elseif (strpos($errorMessage, 'parser error') !== false) {
                $errorMessage = 'РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° YML: ' . $errorMessage;
            }

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'message' => $errorMessage,
            ], 500);
        }
    }
}
