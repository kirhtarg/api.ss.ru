<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImageDownloadTaskJob;
use App\Jobs\PostProcessImage;
use App\Models\ShopBrand;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;
use App\Models\ShopGoodVariation;
use App\Models\ShopLabel;
use App\Models\ShopProperty;
use App\Models\ShopPropertyValue;
use App\Models\ShopTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ShopGoodsController extends Controller
{
    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃРїРёСЃРѕРє С‚РѕРІР°СЂРѕРІ СЃ С„РёР»СЊС‚СЂР°С†РёРµР№ Рё РїР°РіРёРЅР°С†РёРµР№
     */
    public function index(Request $request): JsonResponse
    {

        $query = ShopGood::select([
            'id', 'name', 'slug', 'sku', 'description', 'short_description',
            'price', 'sale_price', 'demping_price', 'show_demping', 'label_id', 'supplier',
            'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'rating', 'reviews_count',
            'width', 'height', 'depth', 'weight',
            'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'is_show', 'sort_order',
            'created_at', 'updated_at',
        ])->with([
            'categories:id,name',
            'brands:id,name',
            'tags:id,name,color',
            'label:id,name,color',
            'properties:id,name,slug',
            'images:id,good_id,file_path,alt_text,is_main,sort_order',
            'variations:id,good_id,name,sku,price,sale_price,demping_price,show_demping,stock_quantity,remote_stock_quantity,fast_remote_stock_quantity,is_active,supplier',
            'variations.images:id,variation_id,file_path,alt_text,is_main,sort_order',
        ])->withCount('variations');

        // Р¤Р»Р°Рі: РїСЂРёРјРµРЅСЏС‚СЊ С„РёР»СЊС‚СЂС‹ РѕСЃС‚Р°С‚РєРѕРІ С‚РѕР»СЊРєРѕ Рє РѕСЃРЅРѕРІРЅРѕРјСѓ С‚РѕРІР°СЂСѓ, РёРіРЅРѕСЂРёСЂСѓСЏ РІР°СЂРёР°С†РёРё
        $stockOnlyGoods = $request->boolean('stock_only_goods', false);

        // Р—Р°РіСЂСѓР¶Р°РµРј pivot РґР°РЅРЅС‹Рµ РґР»СЏ СЃРІРѕР№СЃС‚РІ (РїРѕРґРґРµСЂР¶РёРІР°РµРј СЂР°Р·РЅС‹Рµ СЃС…РµРјС‹: value РёР»Рё shop_property_value_id)
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        $query->with(['properties' => function ($query) use ($hasValueCol) {
            if ($hasValueCol) {
                $query->withPivot('shop_property_value_id', 'value');
            } else {
                $query->withPivot('shop_property_value_id');
            }
        }]);

        // Р¤РёР»СЊС‚СЂ РїРѕ РјР°СЃСЃРёРІСѓ ID (РґР»СЏ РјР°СЃСЃРѕРІРѕР№ Р·Р°РіСЂСѓР·РєРё Рё СЌРєСЃРїРѕСЂС‚Р°) - РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РїРµСЂРІС‹Рј
        // РџСЂРѕРІРµСЂСЏРµРј selected_ids (РґР»СЏ СЌРєСЃРїРѕСЂС‚Р° РІС‹Р±СЂР°РЅРЅС‹С… С‚РѕРІР°СЂРѕРІ)
        if ($request->has('selected_ids') && ! empty($request->input('selected_ids'))) {
            $selectedIdsRaw = $request->input('selected_ids');

            // Р•СЃР»Рё СЌС‚Рѕ СЃС‚СЂРѕРєР° СЃ Р·Р°РїСЏС‚С‹РјРё, СЂР°Р·Р±РёРІР°РµРј РЅР° РјР°СЃСЃРёРІ
            if (is_string($selectedIdsRaw) && strpos($selectedIdsRaw, ',') !== false) {
                $selectedIds = explode(',', $selectedIdsRaw);
            } elseif (is_array($selectedIdsRaw)) {
                $selectedIds = $selectedIdsRaw;
            } else {
                $selectedIds = [$selectedIdsRaw];
            }

            $selectedIds = array_map('intval', array_filter($selectedIds, function ($id) {
                return is_numeric($id) && $id > 0;
            }));

            if (! empty($selectedIds)) {
                $query->whereIn('id', $selectedIds);
                // РљРѕРіРґР° РµСЃС‚СЊ selected_ids, РїСЂРѕРїСѓСЃРєР°РµРј РІСЃРµ РѕСЃС‚Р°Р»СЊРЅС‹Рµ С„РёР»СЊС‚СЂС‹
                // (СЌРєСЃРїРѕСЂС‚РёСЂСѓРµРј С‚РѕР»СЊРєРѕ РІС‹Р±СЂР°РЅРЅС‹Рµ С‚РѕРІР°СЂС‹ Р±РµР· РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅС‹С… С„РёР»СЊС‚СЂРѕРІ)
            }
        } else {
            // РћР±С‹С‡РЅР°СЏ С„РёР»СЊС‚СЂР°С†РёСЏ
            // РџСЂРѕРІРµСЂСЏРµРј РѕР±Р° РІР°СЂРёР°РЅС‚Р°: ids[] Рё ids (РґР»СЏ РґСЂСѓРіРёС… СЃР»СѓС‡Р°РµРІ)
            $ids = null;
            if ($request->has('ids')) {
                $ids = $request->input('ids');
            } elseif ($request->has('ids[]')) {
                $ids = $request->input('ids[]');
            }

            if ($ids !== null) {
                // Р•СЃР»Рё СЌС‚Рѕ РЅРµ РјР°СЃСЃРёРІ, РїС‹С‚Р°РµРјСЃСЏ РїСЂРµРѕР±СЂР°Р·РѕРІР°С‚СЊ
                if (! is_array($ids)) {
                    $ids = [$ids];
                }

                if (! empty($ids)) {
                    $ids = array_map('intval', $ids);
                    $ids = array_filter($ids, function ($id) {
                        return $id > 0;
                    });

                    if (! empty($ids)) {
                        $query->whereIn('id', $ids);
                    }
                }
            }

            // РџСЂРёРјРµРЅСЏРµРј С„РёР»СЊС‚СЂС‹ С‚РѕР»СЊРєРѕ РµСЃР»Рё РЅРµС‚ ids
            if (! $ids || empty($ids)) {
                // $this->applyGoodsFilters($query, $request);
            }
        }
        if ($request->filled('search')) {
            $search = $request->get('search');
            // Р•СЃР»Рё РїРµСЂРµРґР°РЅ РїР°СЂР°РјРµС‚СЂ search_only_name_sku, РёС‰РµРј С‚РѕР»СЊРєРѕ РїРѕ РЅР°Р·РІР°РЅРёСЋ Рё Р°СЂС‚РёРєСѓР»Сѓ
            $searchOnlyNameSku = $request->input('search_only_name_sku');
            if ($searchOnlyNameSku && ($searchOnlyNameSku === '1' || $searchOnlyNameSku === 1 || $searchOnlyNameSku === true || $searchOnlyNameSku === 'true')) {
                $query->searchNameSku($search);
            } else {
                $query->search($search);
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р·РІР°РЅРёСЋ (С‚РѕС‡РЅРѕРµ РІС…РѕР¶РґРµРЅРёРµ С‚РµРєСЃС‚Р°)
        if ($request->filled('name_search')) {
            $nameSearch = $request->get('name_search');
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($nameSearch).'%']);
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ (С‚РѕС‡РЅРѕРµ РІС…РѕР¶РґРµРЅРёРµ С‚РµРєСЃС‚Р°) - РѕР±РЅРѕРІР»РµРЅРЅР°СЏ Р»РѕРіРёРєР° СЃ СѓС‡РµС‚РѕРј РІР°СЂРёР°С†РёР№
        if ($request->filled('sku_search')) {
            $skuSearch = $request->get('sku_search');
            $skuSearchLower = '%'.mb_strtolower($skuSearch).'%';

            $query->where(function ($mainQuery) use ($skuSearchLower) {
                // РџРѕРёСЃРє РїРѕ Р°СЂС‚РёРєСѓР»Сѓ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                $mainQuery->whereRaw('LOWER(sku) LIKE ?', [$skuSearchLower])
                    // РџРѕРёСЃРє РїРѕ Р°СЂС‚РёРєСѓР»Р°Рј РІР°СЂРёР°С†РёР№
                    ->orWhereHas('variations', function ($variationQuery) use ($skuSearchLower) {
                        $variationQuery->whereRaw('LOWER(sku) LIKE ?', [$skuSearchLower]);
                    });
            });
        }

        // Р›РѕРіРёСЂРѕРІР°РЅРёРµ С„РёР»СЊС‚СЂР° РїРѕ Р°СЂС‚РёРєСѓР»Сѓ
        if ($request->filled('sku_search')) {
        }

        // Р¤РёР»СЊС‚СЂ: РґСѓР±Р»Рё РїРѕ РЅР°Р·РІР°РЅРёСЋ (РЅРѕСЂРјР°Р»РёР·РѕРІР°РЅРѕ РїРѕ LOWER(TRIM(name)))
        if ($request->filled('duplicate_names')) {
            $query->whereNotNull('name')
                ->whereRaw('LENGTH(TRIM(name)) > 0');

            // РџРѕРґРєР»СЋС‡Р°РµРј РїРѕРґР·Р°РїСЂРѕСЃ СЃ РіСЂСѓРїРїРёСЂРѕРІРєРѕР№ РґСѓР±Р»РµР№ Рё РѕС‚С„РёР»СЊС‚СЂРѕРІС‹РІР°РµРј РїРѕ РЅРµРјСѓ
            $duplicatesSubquery = DB::table('shop_goods')
                ->select(DB::raw('LOWER(TRIM(name)) as norm_name'))
                ->whereNotNull('name')
                ->whereRaw('LENGTH(TRIM(name)) > 0')
                ->groupBy(DB::raw('LOWER(TRIM(name))'))
                ->havingRaw('COUNT(*) > 1');

            $query->joinSub($duplicatesSubquery, 'dup', function ($join) {
                $join->on(DB::raw('LOWER(TRIM(shop_goods.name))'), '=', 'dup.norm_name');
            })->distinct('shop_goods.id');
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РєР°С‚РµРіРѕСЂРёРё
        if ($request->filled('category_id')) {
            $query->byCategory($request->get('category_id'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рј РєР°С‚РµРіРѕСЂРёСЏРј
        if ($request->has('categories')) {
            $categoryIds = $request->input('categories');
            if (is_array($categoryIds) && ! empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('shop_categories.id', $categoryIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р±СЂРµРЅРґСѓ
        if ($request->filled('brand_id')) {
            $query->byBrand($request->get('brand_id'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рј Р±СЂРµРЅРґР°Рј
        if ($request->has('brands')) {
            $brandIds = $request->input('brands');
            if (is_array($brandIds) && ! empty($brandIds)) {
                $query->whereHas('brands', function ($q) use ($brandIds) {
                    $q->whereIn('shop_brands.id', $brandIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ С‚РµРіСѓ
        if ($request->filled('tag_id')) {
            $query->byTag($request->get('tag_id'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рј С‚РµРіР°Рј
        if ($request->has('tags')) {
            $tagIds = $request->input('tags');
            if (is_array($tagIds) && ! empty($tagIds)) {
                $query->whereHas('tags', function ($q) use ($tagIds) {
                    $q->whereIn('shop_tags.id', $tagIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РїРѕСЃС‚Р°РІС‰РёРєСѓ (С‚РµРєСЃС‚РѕРІРѕРµ РїРѕР»Рµ)
        if ($request->filled('supplier')) {
            $supplier = $request->get('supplier');
            $query->where('supplier', $supplier);
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рј РїРѕСЃС‚Р°РІС‰РёРєР°Рј
        if ($request->has('suppliers')) {
            $supplierIds = $request->input('suppliers');
            $includeVariations = $request->boolean('suppliers_include_variations', false);

            if (is_array($supplierIds) && ! empty($supplierIds)) {
                if ($includeVariations) {
                    // Р’РєР»СЋС‡Р°РµРј С‚РѕРІР°СЂС‹ СЃ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё Р С‚РѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё СЃ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                    $query->where(function ($q) use ($supplierIds) {
                        // РўРѕРІР°СЂС‹ СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                        $q->whereIn('supplier', $supplierIds)
                          // РР»Рё С‚РѕРІР°СЂС‹, Сѓ РєРѕС‚РѕСЂС‹С… РµСЃС‚СЊ РІР°СЂРёР°С†РёРё СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                            ->orWhereHas('variations', function ($varQ) use ($supplierIds) {
                                $varQ->whereIn('supplier', $supplierIds);
                            });
                    });
                } else {
                    // РўРѕР»СЊРєРѕ С‚РѕРІР°СЂС‹ СЃ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё (Р±РµР· СѓС‡РµС‚Р° РІР°СЂРёР°С†РёР№)
                    $query->whereIn('supplier', $supplierIds);
                }
            }
        }

        // Р¤РёР»СЊС‚СЂ "Р‘РµР· РїРѕСЃС‚Р°РІС‰РёРєРѕРІ"
        if ($request->has('supplier_empty')) {
            $includeVariations = $request->boolean('supplier_empty_include_variations', false);

            if ($includeVariations) {
                // РСЃРєР»СЋС‡Р°РµРј С‚РѕРІР°СЂС‹ СЃ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё Р С‚РѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё СЃ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                $query->where(function ($q) {
                    $q->whereNull('supplier')
                        ->orWhere('supplier', '');
                })->whereDoesntHave('variations', function ($varQ) {
                    $varQ->whereNotNull('supplier')
                        ->where('supplier', '!=', '');
                });
            } else {
                // РўРѕР»СЊРєРѕ С‚РѕРІР°СЂС‹ Р±РµР· РїРѕСЃС‚Р°РІС‰РёРєР° РІ РѕСЃРЅРѕРІРЅРѕРј С‚РѕРІР°СЂРµ
                $query->where(function ($q) {
                    $q->whereNull('supplier')
                        ->orWhere('supplier', '');
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ "РџРѕСЃС‚Р°РІС‰РёРєРё Р±РµР· С‚РѕРІР°СЂРѕРІ" - РЅРѕРІС‹Р№ С„РёР»СЊС‚СЂ РґР»СЏ РїРѕСЃС‚Р°РІС‰РёРєРѕРІ, РЅРµ РїСЂРёРІСЏР·Р°РЅРЅС‹С… Рє С‚РѕРІР°СЂР°Рј
        // Р¤РёР»СЊС‚СЂ "Р‘РµР· РїРѕСЃС‚Р°РІС‰РёРєРѕРІ" - Р·РµСЂРєР°Р»СЊРЅС‹Р№ С„РёР»СЊС‚СЂ "РЎ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё"
        if ($request->has('unlinked_suppliers')) {
            $unlinkedSupplierNames = $request->input('unlinked_suppliers');
            $includeVariations = $request->boolean('unlinked_suppliers_include_variations', false);

            if (is_array($unlinkedSupplierNames) && ! empty($unlinkedSupplierNames)) {
                if ($includeVariations) {
                    // Р—РµСЂРєР°Р»СЊРЅРѕ: РїРѕРєР°Р·С‹РІР°РµРј С‚РѕРІР°СЂС‹, Сѓ РєРѕС‚РѕСЂС‹С… РќР•Рў РІС‹Р±СЂР°РЅРЅС‹С… РїРѕСЃС‚Р°РІС‰РёРєРѕРІ (РІРєР»СЋС‡Р°СЏ РІР°СЂРёР°С†РёРё)
                    $query->where(function ($q) use ($unlinkedSupplierNames) {
                        // РўРѕРІР°СЂС‹ Р±РµР· РІС‹Р±СЂР°РЅРЅС‹С… РїРѕСЃС‚Р°РІС‰РёРєРѕРІ
                        $q->whereNotIn('supplier', $unlinkedSupplierNames)
                          // Р С‚РѕРІР°СЂС‹, Сѓ РєРѕС‚РѕСЂС‹С… РЅРµС‚ РІР°СЂРёР°С†РёР№ СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                            ->whereDoesntHave('variations', function ($varQ) use ($unlinkedSupplierNames) {
                                $varQ->whereIn('supplier', $unlinkedSupplierNames);
                            });
                    });
                } else {
                    // Р—РµСЂРєР°Р»СЊРЅРѕ: РїРѕРєР°Р·С‹РІР°РµРј С‚РѕРІР°СЂС‹, Сѓ РєРѕС‚РѕСЂС‹С… РќР•Рў РІС‹Р±СЂР°РЅРЅС‹С… РїРѕСЃС‚Р°РІС‰РёРєРѕРІ (С‚РѕР»СЊРєРѕ РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ)
                    $query->whereNotIn('supplier', $unlinkedSupplierNames);
                }
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р»РµР№Р±Р»Р°Рј
        if ($request->has('labels')) {
            $labelIds = $request->input('labels');
            if (is_array($labelIds) && ! empty($labelIds)) {
                $query->whereIn('label_id', $labelIds);
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РґРµРјРїРёРЅРіСѓ (РїРѕ РїРѕР»СЋ show_demping)
        if ($request->filled('has_demping')) {
            $hasDemping = $request->get('has_demping');
            if ($hasDemping === 'true') {
                // РўРѕРІР°СЂС‹ СЃ РґРµРјРїРёРЅРіРѕРј РІ РѕСЃРЅРѕРІРЅРѕРј С‚РѕРІР°СЂРµ
                $query->where('show_demping', true);
            } elseif ($hasDemping === 'false') {
                $query->where(function ($q) {
                    $q->where('show_demping', false)
                        ->orWhereNull('show_demping');
                });
            } elseif ($hasDemping === 'variations') {
                // РўРѕРІР°СЂС‹ СЃ РґРµРјРїРёРЅРіРѕРј РІ РІР°СЂРёР°С†РёСЏС…
                $query->whereHas('variations', function ($q) {
                    $q->where('show_demping', true);
                });
            } elseif ($hasDemping === 'both') {
                // РўРѕРІР°СЂС‹ СЃ РґРµРјРїРёРЅРіРѕРј РІ РѕСЃРЅРѕРІРЅРѕРј С‚РѕРІР°СЂРµ РР›Р РІ РІР°СЂРёР°С†РёСЏС… (РёР»Рё РІ РѕР±РѕРёС…)
                $query->where(function ($q) {
                    $q->where('show_demping', true)
                        ->orWhereHas('variations', function ($subQ) {
                            $subQ->where('show_demping', true);
                        });
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р»РёС‡РёСЋ С‚РµРіРѕРІ
        if ($request->filled('has_tags')) {
            $hasTags = $request->get('has_tags');
            if ($hasTags === 'true') {
                $query->whereHas('tags');
            } elseif ($hasTags === 'false') {
                $query->whereDoesntHave('tags');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РґРµРјРїРёРЅРіРѕРІРѕР№ С†РµРЅРµ (РїСѓСЃС‚РѕРµ/РЅРµ РїСѓСЃС‚РѕРµ)
        if ($request->filled('has_demping_price')) {
            $hasDempingPrice = $request->get('has_demping_price');
            if ($hasDempingPrice === 'true') {
                $query->whereNotNull('demping_price')
                    ->where('demping_price', '>', 0);
            } elseif ($hasDempingPrice === 'false') {
                $query->where(function ($q) {
                    $q->whereNull('demping_price')
                        ->orWhere('demping_price', '=', 0);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р»РёС‡РёСЋ Р»РµР№Р±Р»Р°
        if ($request->filled('has_label')) {
            $hasLabel = $request->get('has_label');
            if ($hasLabel === 'true') {
                $query->whereNotNull('label_id');
            } elseif ($hasLabel === 'false') {
                $query->whereNull('label_id');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ С†РµРЅРµ
        $minPrice = $request->has('min_price') ? $request->get('min_price') : null;
        $maxPrice = $request->has('max_price') ? $request->get('max_price') : null;

        if ($minPrice !== null || $maxPrice !== null) {
            $query->priceRange($minPrice, $maxPrice);
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р»РёС‡РёСЋ Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅС‹ (has_sale_price)
        if ($request->filled('has_sale_price')) {
            $hasSalePrice = $request->get('has_sale_price');
            if ($hasSalePrice === 'true') {
                $query->where(function ($q) {
                    // РџСЂРѕРІРµСЂСЏРµРј Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                    $q->whereNotNull('sale_price')
                        ->where('sale_price', '>', 0)
                      // РР»Рё Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ РІ РІР°СЂРёР°С†РёСЏС…
                        ->orWhereHas('variations', function ($varQ) {
                            $varQ->whereNotNull('sale_price')
                                ->where('sale_price', '>', 0);
                        });
                });
            } elseif ($hasSalePrice === 'false') {
                $query->where(function ($q) {
                    // РћСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ Р±РµР· Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅС‹
                    $q->where(function ($mainQ) {
                        $mainQ->whereNull('sale_price')
                            ->orWhere('sale_price', '=', 0);
                    })
                    // Р РЅРµС‚ РІР°СЂРёР°С†РёР№ СЃ Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅРѕР№
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->whereNotNull('sale_price')
                                ->where('sale_price', '>', 0);
                        });
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅРµ
        $minSalePrice = $request->has('min_sale_price') ? $request->get('min_sale_price') : null;
        $maxSalePrice = $request->has('max_sale_price') ? $request->get('max_sale_price') : null;

        if ($minSalePrice !== null || $maxSalePrice !== null) {
            // РўРѕС‡РЅРѕРµ Р·РЅР°С‡РµРЅРёРµ (РєРѕРіРґР° min Рё max СЂР°РІРЅС‹)
            if ($minSalePrice !== null && $maxSalePrice !== null && $minSalePrice == $maxSalePrice) {
                $query->where('sale_price', '=', $minSalePrice);
            } else {
                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј min Рё max РѕС‚РґРµР»СЊРЅРѕ
                if ($minSalePrice !== null) {
                    // Р”Р»СЏ min_sale_price > 0 (not_zero) - РёСЃРєР»СЋС‡Р°РµРј null Рё 0
                    if ($minSalePrice > 0) {
                        $query->whereNotNull('sale_price')
                            ->where('sale_price', '>=', $minSalePrice);
                    } else {
                        $query->where(function ($q) use ($minSalePrice) {
                            $q->whereNotNull('sale_price')
                                ->where('sale_price', '>=', $minSalePrice);
                        });
                    }
                }
                if ($maxSalePrice !== null) {
                    if ($maxSalePrice == 0) {
                        // Р¤РёР»СЊС‚СЂ "СЂР°РІРЅР° 0" - РІРєР»СЋС‡Р°РµРј null Рё 0
                        $query->where(function ($q) {
                            $q->whereNull('sale_price')
                                ->orWhere('sale_price', '=', 0);
                        });
                    } else {
                        // Р”Р»СЏ max_sale_price > 0
                        if ($minSalePrice !== null && $minSalePrice > 0) {
                            // Р•СЃР»Рё РµСЃС‚СЊ min > 0, С‚Рѕ null СѓР¶Рµ РёСЃРєР»СЋС‡РµРЅ
                            $query->where('sale_price', '<=', $maxSalePrice);
                        } else {
                            // Р•СЃР»Рё РЅРµС‚ min РёР»Рё min <= 0, С‚Рѕ РІРєР»СЋС‡Р°РµРј null РІ СЂРµР·СѓР»СЊС‚Р°С‚
                            $query->where(function ($q) use ($maxSalePrice) {
                                $q->whereNull('sale_price')
                                    ->orWhere('sale_price', '<=', $maxSalePrice);
                            });
                        }
                    }
                }
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЂРµР№С‚РёРЅРіСѓ
        if ($request->filled('min_rating')) {
            $query->rating($request->get('min_rating'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р»РёС‡РёСЋ (in_stock) - РЅРѕРІР°СЏ Р»РѕРіРёРєР° РґР»СЏ СЂР°Р±РѕС‚С‹ СЃ РІР°СЂРёР°С†РёСЏРјРё
        if ($request->filled('stock_variations_not_empty') || $request->filled('stock_goods_not_empty') ||
            $request->filled('stock_variations_empty') || $request->filled('stock_goods_empty') ||
            $request->filled('stock_variations_exact') || $request->filled('stock_goods_exact') ||
            $request->filled('stock_variations_low') || $request->filled('stock_goods_low')) {

            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('stock_variations_not_empty') === '1' || $request->get('stock_goods_not_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёР№ (С…РѕС‚СЏ Р±С‹ РѕРґРЅР° РІР°СЂРёР°С†РёСЏ СЃ РѕСЃС‚Р°С‚РєРѕРј > 0)
                    $mainQuery->whereHas('variations', function ($varQ) {
                        $varQ->where('stock_quantity', '>', 0);
                    })
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('stock_quantity', '>', 0);
                        });
                });
            } elseif ($request->get('stock_variations_empty') === '1' || $request->get('stock_goods_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ СЃСѓРјРјР° РѕСЃС‚Р°С‚РєРѕРІ РІСЃРµС… РІР°СЂРёР°С†РёР№ = 0
                    $mainQuery->whereHas('variations')
                        ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) = 0')
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where(function ($stockQuery) {
                                    $stockQuery->where('stock_quantity', '=', 0)
                                        ->orWhereNull('stock_quantity');
                                });
                        });
                });
            } elseif ($request->get('stock_variations_low') === '1' || $request->get('stock_goods_low') === '1') {
                // РњРµРЅСЊС€Рµ 3-С…, РёСЃРєР»СЋС‡Р°СЏ 0
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ СЃСѓРјРјР° РѕСЃС‚Р°С‚РєРѕРІ РІР°СЂРёР°С†РёР№ < 3 Рё > 0
                    $mainQuery->whereHas('variations')
                        ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) < 3')
                        ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) > 0')
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('stock_quantity', '<', 3)
                                ->where('stock_quantity', '>', 0);
                        });
                });
            } elseif ($request->filled('stock_variations_exact') || $request->filled('stock_goods_exact')) {
                $exactValue = $request->get('stock_variations_exact') ?: $request->get('stock_goods_exact');

                if ($exactValue !== '') {
                    $query->where(function ($mainQuery) use ($exactValue) {
                        // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј СЃСѓРјРјСѓ РѕСЃС‚Р°С‚РєРѕРІ РІР°СЂРёР°С†РёР№
                        $mainQuery->whereHas('variations')
                            ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) = ?', [$exactValue])
                        // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                            ->orWhere(function ($noVariationsQuery) use ($exactValue) {
                                $noVariationsQuery->whereDoesntHave('variations')
                                    ->where('stock_quantity', '=', $exactValue);
                            });
                    });
                }
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РґРёР°РїР°Р·РѕРЅСѓ РѕСЃС‚Р°С‚РєРѕРІ (stock_quantity_min/max) - РЅРѕРІР°СЏ Р»РѕРіРёРєР°
        if (($request->filled('stock_quantity_variations_min') || $request->filled('stock_quantity_goods_min')) || ($request->filled('stock_quantity_variations_max') || $request->filled('stock_quantity_goods_max'))) {
            $min = null;
            $max = null;

            if ($request->filled('stock_quantity_variations_min')) {
                $min = (int) $request->get('stock_quantity_variations_min');
            } elseif ($request->filled('stock_quantity_goods_min')) {
                $min = (int) $request->get('stock_quantity_goods_min');
            }

            if ($request->filled('stock_quantity_variations_max')) {
                $max = (int) $request->get('stock_quantity_variations_max');
            } elseif ($request->filled('stock_quantity_goods_max')) {
                $max = (int) $request->get('stock_quantity_goods_max');
            }

            $query->where(function ($mainQuery) use ($min, $max) {
                // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёР№
                $mainQuery->whereHas('variations', function ($varQ) use ($min, $max) {
                    if ($min !== null && $max !== null) {
                        if ($min === $max) {
                            $varQ->where('stock_quantity', '=', $min);
                        } else {
                            $varQ->whereBetween('stock_quantity', [$min, $max]);
                        }
                    } elseif ($min !== null) {
                        $varQ->where('stock_quantity', '>=', $min);
                    } elseif ($max !== null) {
                        $varQ->where('stock_quantity', '<=', $max);
                    }
                })
                // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                    ->orWhere(function ($noVariationsQuery) use ($min, $max) {
                        $noVariationsQuery->whereDoesntHave('variations')
                            ->where(function ($stockQuery) use ($min, $max) {
                                if ($min !== null && $max !== null) {
                                    if ($min === $max) {
                                        $stockQuery->where('stock_quantity', '=', $min);
                                    } else {
                                        $stockQuery->whereBetween('stock_quantity', [$min, $max]);
                                    }
                                } elseif ($min !== null) {
                                    $stockQuery->where('stock_quantity', '>=', $min);
                                } elseif ($max !== null) {
                                    $stockQuery->where('stock_quantity', '<=', $max);
                                }
                            });
                    });
            });
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РІР°СЂРёР°С†РёСЏРј
        if ($request->filled('has_variations')) {
            $hasVariations = $request->get('has_variations');
            if ($hasVariations === 'true') {
                $query->whereHas('variations');
            } elseif ($hasVariations === 'false') {
                $query->whereDoesntHave('variations');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РєР°С‚РµРіРѕСЂРёСЏРј
        if ($request->filled('has_categories')) {
            $hasCategories = $request->get('has_categories');
            if ($hasCategories === 'true') {
                $query->whereHas('categories');
            } elseif ($hasCategories === 'false') {
                $query->whereDoesntHave('categories');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р±СЂРµРЅРґР°Рј
        if ($request->filled('has_brands')) {
            $hasBrands = $request->get('has_brands');
            if ($hasBrands === 'true') {
                $query->whereHas('brands');
            } elseif ($hasBrands === 'false') {
                $query->whereDoesntHave('brands');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РёСЃРєР»СЋС‡РµРЅРёСЋ РєР°С‚РµРіРѕСЂРёР№ (С‚РѕРІР°СЂС‹ Р‘Р•Р— РІС‹Р±СЂР°РЅРЅС‹С… РєР°С‚РµРіРѕСЂРёР№)
        if ($request->has('exclude_categories')) {
            $excludeCategoryIds = $request->input('exclude_categories');
            if (is_array($excludeCategoryIds) && ! empty($excludeCategoryIds)) {
                $query->whereDoesntHave('categories', function ($q) use ($excludeCategoryIds) {
                    $q->whereIn('shop_categories.id', $excludeCategoryIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РёСЃРєР»СЋС‡РµРЅРёСЋ Р±СЂРµРЅРґРѕРІ (С‚РѕРІР°СЂС‹ Р‘Р•Р— РІС‹Р±СЂР°РЅРЅС‹С… Р±СЂРµРЅРґРѕРІ)
        if ($request->has('exclude_brands')) {
            $excludeBrandIds = $request->input('exclude_brands');
            if (is_array($excludeBrandIds) && ! empty($excludeBrandIds)) {
                $query->whereDoesntHave('brands', function ($q) use ($excludeBrandIds) {
                    $q->whereIn('shop_brands.id', $excludeBrandIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РёСЃРєР»СЋС‡РµРЅРёСЋ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє (С‚РѕРІР°СЂС‹ Р‘Р•Р— РІС‹Р±СЂР°РЅРЅС‹С… С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє)
        if ($request->has('exclude_properties')) {
            $excludeProperties = $request->input('exclude_properties');
            if (is_array($excludeProperties) && ! empty($excludeProperties)) {
                $query->where(function ($q) use ($excludeProperties) {
                    foreach ($excludeProperties as $propertyId => $valueIds) {
                        if (is_array($valueIds) && ! empty($valueIds)) {
                            $q->whereDoesntHave('properties', function ($propQuery) use ($propertyId, $valueIds) {
                                $propQuery->where('shop_properties.id', $propertyId)
                                    ->whereIn('shop_good_properties.shop_property_value_id', $valueIds);
                            });
                        }
                    }
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РёСЃРєР»СЋС‡РµРЅРёСЋ Р»РµР№Р±Р»РѕРІ (С‚РѕРІР°СЂС‹ Р‘Р•Р— РІС‹Р±СЂР°РЅРЅС‹С… Р»РµР№Р±Р»РѕРІ)
        if ($request->has('exclude_labels')) {
            $excludeLabelIds = $request->input('exclude_labels');
            if (is_array($excludeLabelIds) && ! empty($excludeLabelIds)) {
                $query->where(function ($q) use ($excludeLabelIds) {
                    $q->whereNull('label_id')
                        ->orWhereNotIn('label_id', $excludeLabelIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РёСЃРєР»СЋС‡РµРЅРёСЋ С‚РµРіРѕРІ (С‚РѕРІР°СЂС‹ Р‘Р•Р— РІС‹Р±СЂР°РЅРЅС‹С… С‚РµРіРѕРІ)
        if ($request->has('exclude_tags')) {
            $excludeTagIds = $request->input('exclude_tags');
            if (is_array($excludeTagIds) && ! empty($excludeTagIds)) {
                $query->whereDoesntHave('tags', function ($q) use ($excludeTagIds) {
                    $q->whereIn('shop_tags.id', $excludeTagIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂС‹ РїРѕ РѕРїРёСЃР°РЅРёСЏРј (СЃ СѓС‡С‘С‚РѕРј СѓРґР°Р»РµРЅРёСЏ HTML-С‚РµРіРѕРІ Рё РїСЂРѕР±РµР»РѕРІ)
        // РџРѕРґРґРµСЂР¶РёРІР°СЋС‚СЃСЏ РІР°СЂРёР°РЅС‚С‹:
        // - description_not_empty=1 | description_empty=1
        // - short_description_not_empty=1 | short_description_empty=1
        // - descriptions_both_not_empty=1 | descriptions_both_empty=1
        // Р”Р»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё С‚Р°РєР¶Рµ РїРѕРґРґРµСЂР¶РёРІР°РµС‚СЃСЏ has_description=1 (С‚РѕР»СЊРєРѕ РЅР°Р»РёС‡РёРµ РїРѕР»РЅРѕРіРѕ РѕРїРёСЃР°РЅРёСЏ)
        $cleanDescExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description, '<p>', ''), '</p>', ''), '<br>', ''), '<br/>', ''), '<br />', ''), '&nbsp;', ''), '<div>', ''), '</div>', ''), '<span>', ''), '</span>', '')";
        $cleanShortDescExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(short_description, '<p>', ''), '</p>', ''), '<br>', ''), '<br/>', ''), '<br />', ''), '&nbsp;', ''), '<div>', ''), '</div>', ''), '<span>', ''), '</span>', '')";

        if ($request->filled('description_not_empty')) {
            $query->whereNotNull('description')
                ->whereRaw("LENGTH(TRIM($cleanDescExpr)) > 0");
        } elseif ($request->filled('description_empty')) {
            $query->where(function ($q) use ($cleanDescExpr) {
                $q->whereNull('description')
                    ->orWhereRaw("LENGTH(TRIM($cleanDescExpr)) = 0");
            });
        }

        if ($request->filled('short_description_not_empty')) {
            $query->whereNotNull('short_description')
                ->whereRaw("LENGTH(TRIM($cleanShortDescExpr)) > 0");
        } elseif ($request->filled('short_description_empty')) {
            $query->where(function ($q) use ($cleanShortDescExpr) {
                $q->whereNull('short_description')
                    ->orWhereRaw("LENGTH(TRIM($cleanShortDescExpr)) = 0");
            });
        }

        if ($request->filled('descriptions_both_not_empty')) {
            $query->whereNotNull('description')
                ->whereRaw("LENGTH(TRIM($cleanDescExpr)) > 0")
                ->whereNotNull('short_description')
                ->whereRaw("LENGTH(TRIM($cleanShortDescExpr)) > 0");
        } elseif ($request->filled('descriptions_both_empty')) {
            $query->where(function ($q) use ($cleanDescExpr) {
                $q->whereNull('description')
                    ->orWhereRaw("LENGTH(TRIM($cleanDescExpr)) = 0");
            })->where(function ($q) use ($cleanShortDescExpr) {
                $q->whereNull('short_description')
                    ->orWhereRaw("LENGTH(TRIM($cleanShortDescExpr)) = 0");
            });
        }

        // РћР±СЂР°С‚РЅР°СЏ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚СЊ: СЃС‚Р°СЂС‹Р№ РїР°СЂР°РјРµС‚СЂ has_description=1
        if ($request->filled('has_description')) {
            $hasDescription = $request->get('has_description');
            if ($hasDescription === '1' || $hasDescription === 'true') {
                $query->whereNotNull('description')
                    ->whereRaw("LENGTH(TRIM($cleanDescExpr)) > 0");
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЃС‚Р°С‚СѓСЃСѓ
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ is_new
        if ($request->filled('is_new')) {
            $query->where('is_new', $request->boolean('is_new'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ is_featured
        if ($request->filled('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ is_sale
        if ($request->filled('is_sale')) {
            $query->where('is_sale', $request->boolean('is_sale'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ is_preorder
        if ($request->filled('is_preorder')) {
            $query->where('is_preorder', $request->boolean('is_preorder'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ is_show
        if ($request->filled('is_show')) {
            $query->where('is_show', $request->boolean('is_show'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєР°Рј (properties[property_id][])
        if ($request->has('properties')) {
            $properties = $request->input('properties');
            if (is_array($properties) && ! empty($properties)) {
                // Р›РѕРіРёРєР° РР›Р - С‚РѕРІР°СЂ РґРѕР»Р¶РµРЅ РёРјРµС‚СЊ С…РѕС‚СЏ Р±С‹ РѕРґРЅСѓ РёР· РІС‹Р±СЂР°РЅРЅС‹С… С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє
                $query->where(function ($q) use ($properties) {
                    foreach ($properties as $propertyId => $valueIds) {
                        if (is_array($valueIds) && ! empty($valueIds)) {
                            $q->orWhereHas('properties', function ($propQuery) use ($propertyId, $valueIds) {
                                $propQuery->where('shop_properties.id', $propertyId)
                                    ->whereIn('shop_good_properties.shop_property_value_id', $valueIds);
                            });
                        }
                    }
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РєРѕР»РёС‡РµСЃС‚РІСѓ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє
        if ($request->filled('properties_count_type')) {
            $countType = $request->get('properties_count_type');
            if ($countType === 'none') {
                $query->whereDoesntHave('properties');
            } elseif ($countType === 'with') {
                $query->whereHas('properties');
            } elseif ($countType === 'exact' && $request->filled('properties_count')) {
                $exactCount = (int) $request->get('properties_count');
                $query->has('properties', '=', $exactCount);
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ
        if ($request->filled('sku_filter_type')) {
            $skuFilterType = $request->get('sku_filter_type');
            if ($skuFilterType === 'empty') {
                $query->where(function ($q) {
                    $q->whereNull('sku')
                        ->orWhere('sku', '=', '');
                });
            } elseif ($skuFilterType === 'not_empty') {
                $query->whereNotNull('sku')
                    ->where('sku', '!=', '');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РѕСЃС‚Р°С‚РєСѓ Сѓ/СЃ (remote_stock_quantity) - РЅРѕРІР°СЏ Р»РѕРіРёРєР°
        if ($request->filled('remote_stock_variations_not_empty') && $request->filled('remote_stock_goods_not_empty')) {
            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('remote_stock_variations_not_empty') === '1' || $request->get('remote_stock_goods_not_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёР№
                    $mainQuery->whereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('remote_stock_quantity')
                            ->where('remote_stock_quantity', '!=', '')
                            ->where('remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                    })
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->whereNotNull('remote_stock_quantity')
                                ->where('remote_stock_quantity', '!=', '')
                                ->where('remote_stock_quantity', '!=', '0')
                                ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                        });
                });
            }
        } elseif ($request->filled('remote_stock_variations_empty') && $request->filled('remote_stock_goods_empty')) {
            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('remote_stock_variations_empty') === '1' || $request->get('remote_stock_goods_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РІСЃРµ РІР°СЂРёР°С†РёРё РїСѓСЃС‚С‹Рµ
                    $mainQuery->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->whereNotNull('remote_stock_quantity')
                                ->where('remote_stock_quantity', '!=', '')
                                ->where('remote_stock_quantity', '!=', '0')
                                ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                        })
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where(function ($remoteCondition) {
                                    $remoteCondition->whereNull('remote_stock_quantity')
                                        ->orWhere('remote_stock_quantity', '=', '0')
                                        ->orWhere('remote_stock_quantity', '=', '')
                                        ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                                });
                        });
                });
            }
        } elseif ($request->filled('remote_stock_variations_exact') && $request->filled('remote_stock_goods_exact')) {
            $exactValue = $request->get('remote_stock_variations_exact');

            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('remote_stock_variations_exact') !== '' || $request->get('remote_stock_goods_exact') !== '') {
                $query->where(function ($mainQuery) use ($exactValue) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёР№
                    $mainQuery->whereHas('variations', function ($varQ) use ($exactValue) {
                        $varQ->where('remote_stock_quantity', '=', $exactValue);
                    })
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) use ($exactValue) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('remote_stock_quantity', '=', $exactValue);
                        });
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РѕСЃС‚Р°С‚РєСѓ Сѓ/СЃ Р±С‹СЃС‚СЂРѕ (fast_remote_stock_quantity) - РЅРѕРІР°СЏ Р»РѕРіРёРєР°
        if ($request->filled('fast_remote_stock_variations_not_empty') && $request->filled('fast_remote_stock_goods_not_empty')) {
            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('fast_remote_stock_variations_not_empty') === '1' || $request->get('fast_remote_stock_goods_not_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёР№
                    $mainQuery->whereHas('variations', function ($varQ) {
                        $varQ->whereNotNull('fast_remote_stock_quantity')
                            ->where('fast_remote_stock_quantity', '!=', '')
                            ->where('fast_remote_stock_quantity', '!=', '0')
                            ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                    })
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->whereNotNull('fast_remote_stock_quantity')
                                ->where('fast_remote_stock_quantity', '!=', '')
                                ->where('fast_remote_stock_quantity', '!=', '0')
                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                        });
                });
            }
        } elseif ($request->filled('fast_remote_stock_variations_empty') && $request->filled('fast_remote_stock_goods_empty')) {
            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('fast_remote_stock_variations_empty') === '1' || $request->get('fast_remote_stock_goods_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РІСЃРµ РІР°СЂРёР°С†РёРё РїСѓСЃС‚С‹Рµ
                    $mainQuery->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->whereNotNull('fast_remote_stock_quantity')
                                ->where('fast_remote_stock_quantity', '!=', '')
                                ->where('fast_remote_stock_quantity', '!=', '0')
                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                        })
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where(function ($fastRemoteCondition) {
                                    $fastRemoteCondition->whereNull('fast_remote_stock_quantity')
                                        ->orWhere('fast_remote_stock_quantity', '=', '0')
                                        ->orWhere('fast_remote_stock_quantity', '=', '')
                                        ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                                });
                        });
                });
            }
        } elseif ($request->filled('fast_remote_stock_variations_exact') && $request->filled('fast_remote_stock_goods_exact')) {
            $exactValue = $request->get('fast_remote_stock_variations_exact');

            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('fast_remote_stock_variations_exact') !== '' || $request->get('fast_remote_stock_goods_exact') !== '') {
                $query->where(function ($mainQuery) use ($exactValue) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёР№
                    $mainQuery->whereHas('variations', function ($varQ) use ($exactValue) {
                        $varQ->where('fast_remote_stock_quantity', '=', $exactValue);
                    })
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) use ($exactValue) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('fast_remote_stock_quantity', '=', $exactValue);
                        });
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РѕСЃРЅРѕРІРЅРѕРјСѓ РѕСЃС‚Р°С‚РєСѓ (stock_quantity) - РЅРѕРІР°СЏ Р»РѕРіРёРєР° РґР»СЏ СЂР°Р±РѕС‚С‹ СЃ РІР°СЂРёР°С†РёСЏРјРё
        if ($request->filled('stock_variations_not_empty') && $request->filled('stock_goods_not_empty')) {
            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('stock_variations_not_empty') === '1' || $request->get('stock_goods_not_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РІР°СЂРёР°С†РёР№ (С…РѕС‚СЏ Р±С‹ РѕРґРЅР° РІР°СЂРёР°С†РёСЏ СЃ РѕСЃС‚Р°С‚РєРѕРј > 0)
                    $mainQuery->whereHas('variations', function ($varQ) {
                        $varQ->where('stock_quantity', '>', 0);
                    })
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('stock_quantity', '>', 0);
                        });
                });
            }
        } elseif ($request->filled('stock_variations_empty') && $request->filled('stock_goods_empty')) {
            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('stock_variations_empty') === '1' || $request->get('stock_goods_empty') === '1') {
                $query->where(function ($mainQuery) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ СЃСѓРјРјР° РѕСЃС‚Р°С‚РєРѕРІ РІСЃРµС… РІР°СЂРёР°С†РёР№ = 0
                    $mainQuery->whereHas('variations')
                        ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) = 0')
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where(function ($stockQuery) {
                                    $stockQuery->where('stock_quantity', '=', 0)
                                        ->orWhereNull('stock_quantity');
                                });
                        });
                });
            }
        } elseif ($request->filled('stock_variations_exact') && $request->filled('stock_goods_exact')) {
            $exactValue = $request->get('stock_variations_exact');

            // РџСЂРѕРІРµСЂСЏРµРј, РµСЃР»Рё С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ РёР· РїР°СЂР°РјРµС‚СЂРѕРІ РІРєР»СЋС‡РµРЅ
            if ($request->get('stock_variations_exact') !== '' || $request->get('stock_goods_exact') !== '') {
                $query->where(function ($mainQuery) use ($exactValue) {
                    // Р’Р°СЂРёР°РЅС‚ 1: РўРѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё - РїСЂРѕРІРµСЂСЏРµРј СЃСѓРјРјСѓ РѕСЃС‚Р°С‚РєРѕРІ РІР°СЂРёР°С†РёР№
                    $mainQuery->whereHas('variations')
                        ->whereRaw('(SELECT COALESCE(SUM(stock_quantity), 0) FROM shop_good_variations WHERE good_id = shop_goods.id) = ?', [$exactValue])
                    // Р’Р°СЂРёР°РЅС‚ 2: РўРѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ - РїСЂРѕРІРµСЂСЏРµРј РѕСЃС‚Р°С‚РєРё РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                        ->orWhere(function ($noVariationsQuery) use ($exactValue) {
                            $noVariationsQuery->whereDoesntHave('variations')
                                ->where('stock_quantity', '=', $exactValue);
                        });
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РѕР±С‰РµРјСѓ РѕСЃС‚Р°С‚РєСѓ (total_stock) - РёСЃРїСЂР°РІР»РµРЅРЅР°СЏ Р»РѕРіРёРєР°
        // Р¤РёР»СЊС‚СЂ "РћР±С‰РёР№ РѕСЃС‚Р°С‚РѕРє"
        if ($request->get('total_stock_variations_not_empty') === '1' && $request->get('total_stock_goods_not_empty') === '1') {
            $query->where(function ($q) {
                // Case 1: Products WITH variations: at least ONE variation must be in stock.
                $q->whereHas('variations', function ($varQ) {
                    $varQ->where('stock_quantity', '>', 0)
                        ->orWhere(function ($remote) {
                            $remote->whereNotNull('remote_stock_quantity')->whereNotIn('remote_stock_quantity', ['0', '']);
                        })
                        ->orWhere(function ($fastRemote) {
                            $fastRemote->whereNotNull('fast_remote_stock_quantity')->whereNotIn('fast_remote_stock_quantity', ['0', '']);
                        });
                });
                // Case 2: Products WITHOUT variations: at least ONE of the three stock fields must be non-empty.
                $q->orWhere(function ($noVars) {
                    $noVars->whereDoesntHave('variations')
                        ->where(function ($stock) {
                            $stock->where('stock_quantity', '>', 0)
                                ->orWhere(function ($remote) {
                                    $remote->whereNotNull('remote_stock_quantity')->whereNotIn('remote_stock_quantity', ['0', '']);
                                })
                                ->orWhere(function ($fastRemote) {
                                    $fastRemote->whereNotNull('fast_remote_stock_quantity')->whereNotIn('fast_remote_stock_quantity', ['0', '']);
                                });
                        });
                });
            });
        } elseif ($request->get('total_stock_variations_both_empty') === '1' && $request->get('total_stock_goods_both_empty') === '1') {

            $query->where(function ($q) {
                // Case 1: Products WITH variations: ALL variations must be out of stock.
                $q->where(function ($withVars) {
                    $withVars->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) {
                            // Find any variation that IS in stock
                            $varQ->where('stock_quantity', '>', 0)
                                ->orWhere(function ($remote) {
                                    $remote->whereNotNull('remote_stock_quantity')->whereNotIn('remote_stock_quantity', ['0', '']);
                                })
                                ->orWhere(function ($fastRemote) {
                                    $fastRemote->whereNotNull('fast_remote_stock_quantity')->whereNotIn('fast_remote_stock_quantity', ['0', '']);
                                });
                        });
                })
                // Case 2: Products WITHOUT variations: ALL three stock fields must be empty.
                    ->orWhere(function ($noVars) {
                        $noVars->whereDoesntHave('variations')
                            ->where(function ($stock) {
                                $stock->where('stock_quantity', '=', 0)->orWhereNull('stock_quantity');
                            })
                            ->where(function ($remote) {
                                $remote->whereIn('remote_stock_quantity', ['0', ''])->orWhereNull('remote_stock_quantity');
                            })
                            ->where(function ($fastRemote) {
                                $fastRemote->whereIn('fast_remote_stock_quantity', ['0', ''])->orWhereNull('fast_remote_stock_quantity');
                            });
                    });
            });
        }

        // РЎРѕСЂС‚РёСЂРѕРІРєР°
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');

        $allowedSortFields = [
            'id', 'name', 'sku', 'price', 'rating', 'stock_quantity', 'remote_stock_quantity',
            'fast_remote_stock_quantity', 'created_at', 'sort_order', 'supplier', 'slug',
            'categories', 'brands',
        ];

        // РЎРїРµС†РёР°Р»СЊРЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° РґР»СЏ РїРѕР»РµР№ РѕС‚РЅРѕС€РµРЅРёР№ - РёСЃРїРѕР»СЊР·СѓРµРј РїРѕРґР·Р°РїСЂРѕСЃС‹ РґР»СЏ СЃРѕСЂС‚РёСЂРѕРІРєРё
        if (in_array($sortBy, ['categories', 'brands', 'label', 'tags'])) {
            switch ($sortBy) {
                case 'categories':
                    // РЎРѕСЂС‚РёСЂРѕРІРєР° РїРѕ РїРµСЂРІРѕР№ РєР°С‚РµРіРѕСЂРёРё С‚РѕРІР°СЂР° (РїРѕ Р°Р»С„Р°РІРёС‚Сѓ)
                    $query->leftJoin('shop_good_categories', 'shop_goods.id', '=', 'shop_good_categories.good_id')
                        ->leftJoin('shop_categories', 'shop_good_categories.category_id', '=', 'shop_categories.id')
                        ->orderByRaw('(SELECT MIN(sc.name) FROM shop_good_categories sgc INNER JOIN shop_categories sc ON sgc.category_id = sc.id WHERE sgc.good_id = shop_goods.id) '.$sortDirection)
                        ->select('shop_goods.*')
                        ->groupBy('shop_goods.id');
                    break;
                case 'brands':
                    // РЎРѕСЂС‚РёСЂРѕРІРєР° РїРѕ РїРµСЂРІРѕР№ РјР°СЂРєРµ С‚РѕРІР°СЂР° (РїРѕ Р°Р»С„Р°РІРёС‚Сѓ)
                    $query->leftJoin('shop_good_brands', 'shop_goods.id', '=', 'shop_good_brands.good_id')
                        ->leftJoin('shop_brands', 'shop_good_brands.brand_id', '=', 'shop_brands.id')
                        ->orderByRaw('(SELECT MIN(sb.name) FROM shop_good_brands sgb INNER JOIN shop_brands sb ON sgb.brand_id = sb.id WHERE sgb.good_id = shop_goods.id) '.$sortDirection)
                        ->select('shop_goods.*')
                        ->groupBy('shop_goods.id');
                    break;
                case 'label':
                    $query->leftJoin('shop_labels', 'shop_goods.label_id', '=', 'shop_labels.id')
                        ->orderBy('shop_labels.name', $sortDirection)
                        ->select('shop_goods.*');
                    break;
                case 'tags':
                    // РЎРѕСЂС‚РёСЂРѕРІРєР° РїРѕ РєРѕР»РёС‡РµСЃС‚РІСѓ С‚РµРіРѕРІ
                    $query->withCount('tags')->orderBy('tags_count', $sortDirection);
                    break;
            }
        } elseif (in_array($sortBy, $allowedSortFields)) {
            // РћР±С‹С‡РЅР°СЏ СЃРѕСЂС‚РёСЂРѕРІРєР° РґР»СЏ РїСЂРѕСЃС‚С‹С… РїРѕР»РµР№
            $query->orderBy($sortBy, $sortDirection);
        }

        // ids_only: РІРµСЂРЅСѓС‚СЊ С‚РѕР»СЊРєРѕ СЃРїРёСЃРѕРє ID (РґР»СЏ РјР°СЃСЃРѕРІРѕРіРѕ РІС‹Р±РѕСЂР°)
        $idsOnly = $request->has('ids_only') && $request->get('ids_only') === '1';
        if ($idsOnly) {
            $limit = (int) $request->get('limit', 30000);
            if ($limit <= 0) {
                $limit = 30000;
            }
            $limit = min($limit, 30000);
            $ids = $query->select('shop_goods.id')->limit($limit)->pluck('shop_goods.id')->toArray();

            return response()->json([
                'success' => true,
                'ids' => $ids,
            ]);
        }

        // РџСЂРѕРІРµСЂСЏРµРј, Р·Р°РїСЂРѕС€РµРЅР° Р»Рё РЅРµ РїР°РіРёРЅРёСЂРѕРІР°РЅРЅР°СЏ РІС‹Р±РѕСЂРєР° РґР»СЏ СЌРєСЃРїРѕСЂС‚Р°
        $forExport = $request->has('for_export') && $request->get('for_export') === '1';
        $countOnly = $request->has('count_only') && $request->get('count_only') === '1';

        if ($forExport && $countOnly) {
            // РўРћР›Р¬РљРћ РџРћР”РЎР§Р•Рў РЎРўР РћРљ - Р±РµР· Р·Р°РіСЂСѓР·РєРё РґР°РЅРЅС‹С…
            $goodsIdsQuery = clone $query;
            $goodsIdsQuery->select('id');

            $totalRows = DB::table('shop_goods')
                ->leftJoin('shop_good_variations', 'shop_goods.id', '=', 'shop_good_variations.good_id')
                ->whereIn('shop_goods.id', $goodsIdsQuery)
                ->selectRaw('
                    SUM(CASE
                        WHEN shop_good_variations.id IS NULL THEN 1
                        ELSE 0
                    END) as goods_without_variations,
                    COUNT(shop_good_variations.id) as variations_count
                ')
                ->first();

            $totalRowsCount = ($totalRows->goods_without_variations ?? 0) + ($totalRows->variations_count ?? 0);

            return response()->json([
                'success' => true,
                'count' => $totalRowsCount,
            ]);
        }

        if ($forExport) {
            // РћРїС‚РёРјРёР·РёСЂРѕРІР°РЅРЅС‹Р№ РїРѕРґСЃС‡РµС‚ РєРѕР»РёС‡РµСЃС‚РІР° СЃС‚СЂРѕРє РґР»СЏ СЌРєСЃРїРѕСЂС‚Р°
            // РСЃРїРѕР»СЊР·СѓРµРј SQL РґР»СЏ РїРѕРґСЃС‡РµС‚Р° Р±РµР· Р·Р°РіСЂСѓР·РєРё РІСЃРµС… РґР°РЅРЅС‹С…
            $goodsIdsQuery = clone $query;
            $goodsIdsQuery->select('id');

            $totalRows = DB::table('shop_goods')
                ->leftJoin('shop_good_variations', 'shop_goods.id', '=', 'shop_good_variations.good_id')
                ->whereIn('shop_goods.id', $goodsIdsQuery)
                ->selectRaw('
                    SUM(CASE
                        WHEN shop_good_variations.id IS NULL THEN 1
                        ELSE 0
                    END) as goods_without_variations,
                    COUNT(shop_good_variations.id) as variations_count
                ')
                ->first();

            $totalRowsCount = ($totalRows->goods_without_variations ?? 0) + ($totalRows->variations_count ?? 0);

            // РўРµРїРµСЂСЊ Р·Р°РіСЂСѓР¶Р°РµРј С‚РѕРІР°СЂС‹ РґР»СЏ СЌРєСЃРїРѕСЂС‚Р°
            $goods = $query->get();

            // Р—Р°РіСЂСѓР¶Р°РµРј Р·РЅР°С‡РµРЅРёСЏ СЃРІРѕР№СЃС‚РІ РґР»СЏ РІСЃРµС… С‚РѕРІР°СЂРѕРІ (РєР°Рє РІ РѕР±С‹С‡РЅРѕРј СЂРµР¶РёРјРµ)
            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            foreach ($goods as $good) {
                foreach ($good->properties as $property) {
                    if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $property->property_value = $propertyValue;
                    } elseif ($hasValueCol && isset($property->pivot) && $property->pivot->value) {
                        // Р•СЃР»Рё СЃРІРѕР№СЃС‚РІРѕ РёСЃРїРѕР»СЊР·СѓРµС‚ value РЅР°РїСЂСЏРјСѓСЋ
                        $property->property_value = (object) [
                            'id' => null,
                            'value' => $property->pivot->value,
                            'property_id' => $property->id,
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $goods,
                'count' => $totalRowsCount,
            ]);
        }

        // РџР°РіРёРЅР°С†РёСЏ (РµСЃР»Рё РЅРµ Р·Р°РїСЂР°С€РёРІР°СЋС‚СЃСЏ РєРѕРЅРєСЂРµС‚РЅС‹Рµ ID, РёСЃРїРѕР»СЊР·СѓРµРј РїР°РіРёРЅР°С†РёСЋ)
        if (! $request->has('ids')) {
            // РћРїСЂРµРґРµР»СЏРµРј РїР°СЂР°РјРµС‚СЂС‹ РїР°РіРёРЅР°С†РёРё
            $perPage = $request->get('per_page', 20);
            $perPage = in_array($perPage, [10, 20, 50, 100, 5000]) ? $perPage : 20;

            // РЎС‡РµС‚С‡РёРє РіСЂСѓРїРї РґСѓР±Р»РµР№, РµСЃР»Рё Р°РєС‚РёРІРµРЅ duplicate_names
            $duplicateGroupsCount = null;
            if ($request->filled('duplicate_names')) {
                $idsSub = (clone $query)->select('id');
                $duplicateGroupsCount = DB::table('shop_goods')
                    ->whereIn('id', $idsSub)
                    ->whereNotNull('name')
                    ->whereRaw('LENGTH(TRIM(name)) > 0')
                    ->select(DB::raw('LOWER(TRIM(name)) as n'))
                    ->groupBy('n')
                    ->havingRaw('COUNT(*) > 1')
                    ->get()
                    ->count();
            }

            $goods = $query->paginate($perPage);

            // РџРѕР»СѓС‡Р°РµРј URL С„СЂРѕРЅС‚РµРЅРґР° РґР»СЏ РёР·РѕР±СЂР°Р¶РµРЅРёР№
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

            // РџСЂРѕРІРµСЂСЏРµРј, РЅСѓР¶РЅРѕ Р»Рё Р·Р°РіСЂСѓР¶Р°С‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёР№
            $withVariationImages = $request->boolean('with_variation_images', false);
            if ($withVariationImages) {
                $goods->load('variations.images');
            }

            // РџСЂРѕРІРµСЂСЏРµРј, РЅСѓР¶РЅРѕ Р»Рё Р·Р°РіСЂСѓР¶Р°С‚СЊ Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёР№
            $withVariationAttributes = $request->boolean('with_variation_attributes', false);
            if ($withVariationAttributes) {
                // Р—Р°РіСЂСѓР¶Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РІСЃРµС… РІР°СЂРёР°С†РёР№
                foreach ($goods->items() as $good) {
                    if ($good->variations) {
                        foreach ($good->variations as $variation) {
                            $variation->attributes = DB::table('shop_variation_attributes_values as vav')
                                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                ->where('vav.variation_id', $variation->id)
                                ->select('a.id as attribute_id', 'a.name as attribute_name', 'av.id as value_id', 'av.value as value_value')
                                ->get()
                                ->map(function ($row) {
                                    return [
                                        'attribute' => [
                                            'id' => $row->attribute_id,
                                            'name' => $row->attribute_name,
                                        ],
                                        'value' => [
                                            'id' => $row->value_id,
                                            'value' => $row->value_value,
                                        ],
                                        'attribute_id' => $row->attribute_id,
                                        'attribute_name' => $row->attribute_name,
                                        'value_id' => $row->value_id,
                                        'value_value' => $row->value_value,
                                        'value' => $row->value_value,
                                    ];
                                })
                                ->toArray();
                        }
                    }
                }
            }

            // Р—Р°РіСЂСѓР¶Р°РµРј Р·РЅР°С‡РµРЅРёСЏ СЃРІРѕР№СЃС‚РІ РґР»СЏ РІСЃРµС… С‚РѕРІР°СЂРѕРІ
            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            foreach ($goods->items() as $good) {
                foreach ($good->properties as $property) {
                    if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $property->property_value = $propertyValue;
                    } elseif ($hasValueCol && isset($property->pivot) && $property->pivot->value) {
                        // Р•СЃР»Рё СЃРІРѕР№СЃС‚РІРѕ РёСЃРїРѕР»СЊР·СѓРµС‚ value РЅР°РїСЂСЏРјСѓСЋ
                        $property->property_value = (object) [
                            'id' => null,
                            'value' => $property->pivot->value,
                            'property_id' => $property->id,
                        ];
                    }
                }

                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР° - РґРѕР±Р°РІР»СЏРµРј РїРѕР»РЅС‹Р№ URL С„СЂРѕРЅС‚РµРЅРґР°
                if ($good->images) {
                    foreach ($good->images as $image) {
                        if ($image->file_path) {
                            // Р•СЃР»Рё РїСѓС‚СЊ СѓР¶Рµ РїРѕР»РЅС‹Р№ URL, РѕСЃС‚Р°РІР»СЏРµРј РєР°Рє РµСЃС‚СЊ
                            if (str_starts_with($image->file_path, 'http')) {
                                $image->url = $image->file_path;
                            } else {
                                // РќРѕСЂРјР°Р»РёР·СѓРµРј РїСѓС‚СЊ (Р·Р°РјРµРЅСЏРµРј РѕР±СЂР°С‚РЅС‹Рµ СЃР»РµС€Рё РЅР° РїСЂСЏРјС‹Рµ)
                                $normalizedPath = str_replace('\\', '/', $image->file_path);
                                $cleanPath = ltrim($normalizedPath, '/');

                                // РЈР±РёСЂР°РµРј РїСЂРµС„РёРєСЃ cms/shop/ РµСЃР»Рё РѕРЅ РµСЃС‚СЊ РІ РїСѓС‚Рё
                                if (str_starts_with($cleanPath, 'cms/shop/')) {
                                    $cleanPath = substr($cleanPath, strlen('cms/shop/'));
                                }

                                // Р¤РѕСЂРјРёСЂСѓРµРј РїРѕР»РЅС‹Р№ URL - РїСЂРѕСЃС‚Рѕ РґРѕР±Р°РІР»СЏРµРј Р±Р°Р·РѕРІС‹Р№ URL С„СЂРѕРЅС‚РµРЅРґР° Рє file_path
                                $image->url = rtrim($frontendUrl, '/').'/'.$cleanPath;
                            }
                        }
                    }
                }

                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёР№ - РґРѕР±Р°РІР»СЏРµРј РїРѕР»РЅС‹Р№ URL С„СЂРѕРЅС‚РµРЅРґР°
                if ($good->variations) {
                    foreach ($good->variations as $variation) {
                        // Р—Р°РіСЂСѓР¶Р°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёРё, РµСЃР»Рё РѕРЅРё РЅРµ Р·Р°РіСЂСѓР¶РµРЅС‹
                        if (! $variation->relationLoaded('images')) {
                            $variation->load('images');
                        }

                        if ($variation->images) {
                            foreach ($variation->images as $image) {
                                if ($image->file_path) {
                                    // Р•СЃР»Рё РїСѓС‚СЊ СѓР¶Рµ РїРѕР»РЅС‹Р№ URL, РѕСЃС‚Р°РІР»СЏРµРј РєР°Рє РµСЃС‚СЊ
                                    if (str_starts_with($image->file_path, 'http')) {
                                        $image->url = $image->file_path;
                                    } else {
                                        // РќРѕСЂРјР°Р»РёР·СѓРµРј РїСѓС‚СЊ (Р·Р°РјРµРЅСЏРµРј РѕР±СЂР°С‚РЅС‹Рµ СЃР»РµС€Рё РЅР° РїСЂСЏРјС‹Рµ)
                                        $normalizedPath = str_replace('\\', '/', $image->file_path);
                                        $cleanPath = ltrim($normalizedPath, '/');

                                        // РЈР±РёСЂР°РµРј РїСЂРµС„РёРєСЃ cms/shop/ РµСЃР»Рё РѕРЅ РµСЃС‚СЊ РІ РїСѓС‚Рё
                                        if (str_starts_with($cleanPath, 'cms/shop/')) {
                                            $cleanPath = substr($cleanPath, strlen('cms/shop/'));
                                        }

                                        // Р¤РѕСЂРјРёСЂСѓРµРј РїРѕР»РЅС‹Р№ URL - РїСЂРѕСЃС‚Рѕ РґРѕР±Р°РІР»СЏРµРј Р±Р°Р·РѕРІС‹Р№ URL С„СЂРѕРЅС‚РµРЅРґР° Рє file_path
                                        $image->url = rtrim($frontendUrl, '/').'/'.$cleanPath;
                                    }
                                }
                            }
                        }
                    }
                }

                // Р”РѕР±Р°РІР»СЏРµРј РІС‹С‡РёСЃР»РµРЅРёСЏ РґР»СЏ РІР°СЂРёР°С†РёР№
                if ($good->variations_count > 0 && $good->variations) {
                    // РЎСѓРјРјР° РѕСЃС‚Р°С‚РєРѕРІ РІР°СЂРёР°С†РёР№
                    $good->variations_stock_sum = $good->variations->sum('stock_quantity');

                    // РџСЂРѕРІРµСЂРєР° РЅР°Р»РёС‡РёСЏ РЅРµРїСѓСЃС‚С‹С… remote_stock_quantity
                    $hasRemoteStock = $good->variations->filter(function ($variation) {
                        $remoteStock = $variation->remote_stock_quantity;

                        $hasRemote = $remoteStock !== null
                            && $remoteStock !== ''
                            && $remoteStock !== '0'
                            && trim($remoteStock) !== '';

                        return $hasRemote;
                    })->count() > 0;

                    $good->variations_has_remote_stock = $hasRemoteStock;

                    // РџСЂРѕРІРµСЂРєР° РЅР°Р»РёС‡РёСЏ РЅРµРїСѓСЃС‚С‹С… fast_remote_stock_quantity
                    $hasFastRemoteStock = $good->variations->filter(function ($variation) {
                        $fastRemoteStock = $variation->fast_remote_stock_quantity;

                        $hasFastRemote = $fastRemoteStock !== null
                            && $fastRemoteStock !== ''
                            && $fastRemoteStock !== '0'
                            && trim($fastRemoteStock) !== '';

                        return $hasFastRemote;
                    })->count() > 0;

                    $good->variations_has_fast_remote_stock = $hasFastRemoteStock;

                    // РџСЂРѕРІРµСЂРєР° РЅР°Р»РёС‡РёСЏ Р°РєС‚РёРІРЅРѕРіРѕ РґРµРјРїРёРЅРіР°
                    $hasDemping = $good->variations->filter(function ($variation) {
                        return $variation->show_demping === true || $variation->show_demping === 1;
                    })->count() > 0;

                    $good->variations_has_demping = $hasDemping;
                } else {
                    $good->variations_stock_sum = 0;
                    $good->variations_has_remote_stock = false;
                    $good->variations_has_demping = false;
                }
            }

            // РџРѕРґСЃС‡РёС‚С‹РІР°РµРј РІР°СЂРёР°С†РёРё СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё (РµСЃР»Рё С„РёР»СЊС‚СЂ РїРѕ РїРѕСЃС‚Р°РІС‰РёРєР°Рј Р°РєС‚РёРІРµРЅ)
            $variationsCount = 0;
            if ($request->has('suppliers')) {
                $supplierIds = $request->input('suppliers');
                if (is_array($supplierIds) && ! empty($supplierIds)) {
                    $variationsCount = ShopGoodVariation::whereIn('supplier', $supplierIds)->count();
                }
            }

            $response = [
                'success' => true,
                'data' => $goods->items(),
                'pagination' => [
                    'current_page' => $goods->currentPage(),
                    'last_page' => $goods->lastPage(),
                    'per_page' => $goods->perPage(),
                    'total' => $goods->total(),
                    'from' => $goods->firstItem(),
                    'to' => $goods->lastItem(),
                ],
            ];

            // Р”РѕР±Р°РІР»СЏРµРј РєРѕР»РёС‡РµСЃС‚РІРѕ РІР°СЂРёР°С†РёР№, РµСЃР»Рё С„РёР»СЊС‚СЂ РїРѕ РїРѕСЃС‚Р°РІС‰РёРєР°Рј Р°РєС‚РёРІРµРЅ
            if ($request->has('suppliers')) {
                $response['variations_count'] = $variationsCount;
            }
            // Р”РѕР±Р°РІР»СЏРµРј СЃС‡РµС‚С‡РёРє РіСЂСѓРїРї РґСѓР±Р»РµР№, РµСЃР»Рё Р°РєС‚РёРІРµРЅ С„РёР»СЊС‚СЂ duplicate_names
            if ($duplicateGroupsCount !== null) {
                $response['duplicate_groups_count'] = $duplicateGroupsCount;
            }

            return response()->json($response);
        } else {
            // Р•СЃР»Рё Р·Р°РїСЂР°С€РёРІР°СЋС‚СЃСЏ РєРѕРЅРєСЂРµС‚РЅС‹Рµ ID, РІРѕР·РІСЂР°С‰Р°РµРј РІСЃРµ Р±РµР· РїР°РіРёРЅР°С†РёРё
            $goods = $query->get();

            // РџРѕР»СѓС‡Р°РµРј URL С„СЂРѕРЅС‚РµРЅРґР° РґР»СЏ РёР·РѕР±СЂР°Р¶РµРЅРёР№
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

            // РџСЂРѕРІРµСЂСЏРµРј, РЅСѓР¶РЅРѕ Р»Рё Р·Р°РіСЂСѓР¶Р°С‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёР№
            $withVariationImages = $request->boolean('with_variation_images', false);
            if ($withVariationImages) {
                $goods->load('variations.images');
            }

            // РџСЂРѕРІРµСЂСЏРµРј, РЅСѓР¶РЅРѕ Р»Рё Р·Р°РіСЂСѓР¶Р°С‚СЊ Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёР№
            $withVariationAttributes = $request->boolean('with_variation_attributes', false);
            if ($withVariationAttributes) {
                // Р—Р°РіСЂСѓР¶Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РІСЃРµС… РІР°СЂРёР°С†РёР№
                foreach ($goods as $good) {
                    if ($good->variations) {
                        foreach ($good->variations as $variation) {
                            $variation->attributes = DB::table('shop_variation_attributes_values as vav')
                                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                ->where('vav.variation_id', $variation->id)
                                ->select('a.id as attribute_id', 'a.name as attribute_name', 'av.id as value_id', 'av.value as value_value')
                                ->get()
                                ->map(function ($row) {
                                    return [
                                        'attribute' => [
                                            'id' => $row->attribute_id,
                                            'name' => $row->attribute_name,
                                        ],
                                        'value' => [
                                            'id' => $row->value_id,
                                            'value' => $row->value_value,
                                        ],
                                        'attribute_id' => $row->attribute_id,
                                        'attribute_name' => $row->attribute_name,
                                        'value_id' => $row->value_id,
                                        'value_value' => $row->value_value,
                                        'value' => $row->value_value,
                                    ];
                                })
                                ->toArray();
                        }
                    }
                }
            }

            // Р—Р°РіСЂСѓР¶Р°РµРј Р·РЅР°С‡РµРЅРёСЏ СЃРІРѕР№СЃС‚РІ РґР»СЏ РІСЃРµС… С‚РѕРІР°СЂРѕРІ
            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            foreach ($goods as $good) {
                foreach ($good->properties as $property) {
                    if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $property->property_value = $propertyValue;
                    } elseif ($hasValueCol && isset($property->pivot) && $property->pivot->value) {
                        // Р•СЃР»Рё СЃРІРѕР№СЃС‚РІРѕ РёСЃРїРѕР»СЊР·СѓРµС‚ value РЅР°РїСЂСЏРјСѓСЋ
                        $property->property_value = (object) [
                            'id' => null,
                            'value' => $property->pivot->value,
                            'property_id' => $property->id,
                        ];
                    }
                }

                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР° - РґРѕР±Р°РІР»СЏРµРј РїРѕР»РЅС‹Р№ URL С„СЂРѕРЅС‚РµРЅРґР°
                if ($good->images) {
                    foreach ($good->images as $image) {
                        if ($image->file_path) {
                            // Р•СЃР»Рё РїСѓС‚СЊ СѓР¶Рµ РїРѕР»РЅС‹Р№ URL, РѕСЃС‚Р°РІР»СЏРµРј РєР°Рє РµСЃС‚СЊ
                            if (str_starts_with($image->file_path, 'http')) {
                                $image->url = $image->file_path;
                            } else {
                                // РќРѕСЂРјР°Р»РёР·СѓРµРј РїСѓС‚СЊ (Р·Р°РјРµРЅСЏРµРј РѕР±СЂР°С‚РЅС‹Рµ СЃР»РµС€Рё РЅР° РїСЂСЏРјС‹Рµ)
                                $normalizedPath = str_replace('\\', '/', $image->file_path);
                                $cleanPath = ltrim($normalizedPath, '/');

                                // Р¤РѕСЂРјРёСЂСѓРµРј РїРѕР»РЅС‹Р№ URL - РїСЂРѕСЃС‚Рѕ РґРѕР±Р°РІР»СЏРµРј Р±Р°Р·РѕРІС‹Р№ URL С„СЂРѕРЅС‚РµРЅРґР° Рє file_path
                                $image->url = rtrim($frontendUrl, '/').'/'.$cleanPath;
                            }
                        }
                    }
                }

                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёР№ - РґРѕР±Р°РІР»СЏРµРј РїРѕР»РЅС‹Р№ URL С„СЂРѕРЅС‚РµРЅРґР°
                if ($good->variations) {
                    foreach ($good->variations as $variation) {
                        // Р—Р°РіСЂСѓР¶Р°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёРё, РµСЃР»Рё РѕРЅРё РЅРµ Р·Р°РіСЂСѓР¶РµРЅС‹
                        if (! $variation->relationLoaded('images')) {
                            $variation->load('images');
                        }

                        if ($variation->images) {
                            foreach ($variation->images as $image) {
                                if ($image->file_path) {
                                    // Р•СЃР»Рё РїСѓС‚СЊ СѓР¶Рµ РїРѕР»РЅС‹Р№ URL, РѕСЃС‚Р°РІР»СЏРµРј РєР°Рє РµСЃС‚СЊ
                                    if (str_starts_with($image->file_path, 'http')) {
                                        $image->url = $image->file_path;
                                    } else {
                                        // РќРѕСЂРјР°Р»РёР·СѓРµРј РїСѓС‚СЊ (Р·Р°РјРµРЅСЏРµРј РѕР±СЂР°С‚РЅС‹Рµ СЃР»РµС€Рё РЅР° РїСЂСЏРјС‹Рµ)
                                        $normalizedPath = str_replace('\\', '/', $image->file_path);
                                        $cleanPath = ltrim($normalizedPath, '/');

                                        // Р¤РѕСЂРјРёСЂСѓРµРј РїРѕР»РЅС‹Р№ URL - РїСЂРѕСЃС‚Рѕ РґРѕР±Р°РІР»СЏРµРј Р±Р°Р·РѕРІС‹Р№ URL С„СЂРѕРЅС‚РµРЅРґР° Рє file_path
                                        $image->url = rtrim($frontendUrl, '/').'/'.$cleanPath;
                                    }
                                }
                            }
                        }
                    }
                }

                // Р”РѕР±Р°РІР»СЏРµРј РІС‹С‡РёСЃР»РµРЅРёСЏ РґР»СЏ РІР°СЂРёР°С†РёР№
                if ($good->variations_count > 0 && $good->variations) {
                    // РЎСѓРјРјР° РѕСЃС‚Р°С‚РєРѕРІ РІР°СЂРёР°С†РёР№
                    $good->variations_stock_sum = $good->variations->sum('stock_quantity');

                    // РџСЂРѕРІРµСЂРєР° РЅР°Р»РёС‡РёСЏ РЅРµРїСѓСЃС‚С‹С… remote_stock_quantity
                    $hasRemoteStock = $good->variations->filter(function ($variation) {
                        $remoteStock = $variation->remote_stock_quantity;

                        $hasRemote = $remoteStock !== null
                            && $remoteStock !== ''
                            && $remoteStock !== '0'
                            && trim($remoteStock) !== '';

                        return $hasRemote;
                    })->count() > 0;

                    $good->variations_has_remote_stock = $hasRemoteStock;

                    // РџСЂРѕРІРµСЂРєР° РЅР°Р»РёС‡РёСЏ РЅРµРїСѓСЃС‚С‹С… fast_remote_stock_quantity
                    $hasFastRemoteStock = $good->variations->filter(function ($variation) {
                        $fastRemoteStock = $variation->fast_remote_stock_quantity;

                        $hasFastRemote = $fastRemoteStock !== null
                            && $fastRemoteStock !== ''
                            && $fastRemoteStock !== '0'
                            && trim($fastRemoteStock) !== '';

                        return $hasFastRemote;
                    })->count() > 0;

                    $good->variations_has_fast_remote_stock = $hasFastRemoteStock;

                    // РџСЂРѕРІРµСЂРєР° РЅР°Р»РёС‡РёСЏ Р°РєС‚РёРІРЅРѕРіРѕ РґРµРјРїРёРЅРіР°
                    $hasDemping = $good->variations->filter(function ($variation) {
                        return $variation->show_demping === true || $variation->show_demping === 1;
                    })->count() > 0;

                    $good->variations_has_demping = $hasDemping;
                } else {
                    $good->variations_stock_sum = 0;
                    $good->variations_has_remote_stock = false;
                    $good->variations_has_fast_remote_stock = false;
                    $good->variations_has_demping = false;
                }
            }

            // Р¤РѕСЂРјРёСЂСѓРµРј РѕС‚РІРµС‚
            $response = [
                'success' => true,
                'data' => $goods->toArray(),
            ];

            // Р”РѕР±Р°РІР»СЏРµРј СЃС‡РµС‚С‡РёРє РіСЂСѓРїРї РґСѓР±Р»РµР№, РµСЃР»Рё Р°РєС‚РёРІРµРЅ С„РёР»СЊС‚СЂ duplicate_names
            if ($request->filled('duplicate_names')) {
                $names = collect($goods)->map(function ($g) {
                    return is_string($g->name ?? null) ? mb_strtolower(trim($g->name)) : '';
                })->filter(function ($n) {
                    return $n !== '';
                });
                $duplicateGroupsCount = $names->countBy()->filter(function ($c) {
                    return $c > 1;
                })->count();
                $response['duplicate_groups_count'] = $duplicateGroupsCount;
            }

            return response()->json($response);
        }
    }

    /**
     * РљР»РѕРЅРёСЂРѕРІР°С‚СЊ С‚РѕРІР°СЂ
     */
    public function clone(Request $request, $id): JsonResponse
    {
        $originalGood = ShopGood::with(['categories', 'brands', 'tags', 'properties', 'variations.attributeValues'])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:255|unique:shop_goods,sku',
            'clone_variations' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // РЎРѕР·РґР°РµРј РєР»РѕРЅ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
            $newGood = $originalGood->replicate(['slug']);
            $newGood->name = $request->input('name');
            $newGood->sku = $request->input('sku');
            $newGood->slug = Str::slug($newGood->name);
            
            // Р•СЃР»Рё С‚Р°РєРѕР№ slug СѓР¶Рµ РµСЃС‚СЊ, РґРѕР±Р°РІР»СЏРµРј СЃСѓС„С„РёРєСЃ
            $slugCount = ShopGood::where('slug', 'like', $newGood->slug . '%')->count();
            if ($slugCount > 0) {
                $newGood->slug .= '-' . ($slugCount + 1);
            }
            
            $newGood->save();

            // РљР»РѕРЅРёСЂСѓРµРј СЃРІСЏР·Рё
            $newGood->categories()->attach($originalGood->categories->pluck('id'));
            $newGood->brands()->attach($originalGood->brands->pluck('id'));
            $newGood->tags()->attach($originalGood->tags->pluck('id'));

            // РљР»РѕРЅРёСЂСѓРµРј СЃРІРѕР№СЃС‚РІР° С‡РµСЂРµР· pivot
            foreach ($originalGood->properties as $property) {
                $newGood->properties()->attach($property->id, [
                    'shop_property_value_id' => $property->pivot->shop_property_value_id,
                ]);
            }

            // РљР»РѕРЅРёСЂСѓРµРј РІР°СЂРёР°С†РёРё, РµСЃР»Рё Р·Р°РїСЂРѕС€РµРЅРѕ
            if ($request->boolean('clone_variations')) {
                foreach ($originalGood->variations as $variation) {
                    $newVariation = $variation->replicate(['good_id']);
                    $newVariation->good_id = $newGood->id;
                    $newVariation->sku = $variation->sku . '-copy-' . $newGood->id; // Р“РµРЅРµСЂРёСЂСѓРµРј РІСЂРµРјРµРЅРЅС‹Р№ SKU РґР»СЏ РІР°СЂРёР°С†РёРё
                    $newVariation->save();

                    // РљР»РѕРЅРёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё
                    if ($variation->attributeValues) {
                        $newVariation->attributeValues()->attach($variation->attributeValues->pluck('id'));
                    }
                }
            }

            // РђСѓРґРёС‚
            $this->logAudit($newGood, 'cloned', $originalGood->id, $newGood->toArray());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'РўРѕРІР°СЂ СѓСЃРїРµС€РЅРѕ СЃРєР»РѕРЅРёСЂРѕРІР°РЅ',
                'data' => [
                    'id' => $newGood->id,
                    'name' => $newGood->name,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РєР»РѕРЅРёСЂРѕРІР°РЅРёСЏ С‚РѕРІР°СЂР°: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ С‚РѕРІР°СЂ РїРѕ ID
     */
    public function show($id): JsonResponse
    {
        $good = ShopGood::with([
            'categories:id,name,slug',
            'brands:id,name,slug',
            'tags:id,name,color,slug',
            'properties:id,name,slug',
            'images:id,good_id,variation_id,file_path,alt_text,is_main,sort_order',
            'videos:id,good_id,variation_id,video_path,external_url,title,sort_order',
            'variations:id,good_id,supplier,name,description,price,sale_price,demping_price,show_demping,stock_quantity,remote_stock_quantity,fast_remote_stock_quantity,sku,is_active',
            'stock:id,good_id,warehouse_id,quantity,reserved_quantity,min_quantity',
            'stock.warehouse:id,name',
            'prices:id,good_id,price_type_id,price,sale_price',
            'prices.priceType:id,name,multiplier',
        ])->findOrFail($id);

        // Р—Р°РіСЂСѓР¶Р°РµРј РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅС‹Рµ РґР°РЅРЅС‹Рµ РґР»СЏ СЃРІРѕР№СЃС‚РІ С‚РѕРІР°СЂР°
        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
        $good->load(['properties' => function ($query) use ($hasValueCol) {
            if ($hasValueCol) {
                $query->withPivot('shop_property_value_id', 'value');
            } else {
                $query->withPivot('shop_property_value_id');
            }
        }]);

        // Р—Р°РіСЂСѓР¶Р°РµРј Р·РЅР°С‡РµРЅРёСЏ СЃРІРѕР№СЃС‚РІ РѕС‚РґРµР»СЊРЅРѕ, РµСЃР»Рё РёСЃРїРѕР»СЊР·СѓРµРј СЃРїСЂР°РІРѕС‡РЅРёРє
        foreach ($good->properties as $property) {
            if (isset($property->pivot) && $property->pivot->shop_property_value_id) {
                $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                $property->property_value = $propertyValue;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $good,
        ]);
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІС‹Р№ С‚РѕРІР°СЂ
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:shop_goods,slug',
            'sku' => 'nullable|string|max:255|unique:shop_goods,sku',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'integer|min:0',
            'remote_stock_quantity' => 'nullable|string|max:255',
            'fast_remote_stock_quantity' => 'nullable|string|max:255',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_sale' => 'boolean',
            'sort_order' => 'integer',
            'category_ids' => 'array',
            'category_ids.*' => 'exists:shop_categories,id',
            'brand_ids' => 'array',
            'brand_ids.*' => 'exists:shop_brands,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'exists:shop_tags,id',
            'properties' => 'array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            'properties.*.value' => 'nullable|string|max:255',
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $good = ShopGood::create($request->only([
                'name', 'slug', 'sku', 'description', 'short_description',
                'price', 'sale_price', 'demping_price', 'show_demping', 'label_id',
                'stock_quantity', 'remote_stock_quantity', 'width', 'height',
                'depth', 'weight', 'meta_title', 'meta_description',
                'is_active', 'is_featured', 'is_new', 'is_sale', 'sort_order',
            ]));

            // РџСЂРёРІСЏР·РєР° РєР°С‚РµРіРѕСЂРёР№
            if ($request->filled('category_ids')) {
                $good->categories()->attach($request->get('category_ids'));
            }

            // РџСЂРёРІСЏР·РєР° Р±СЂРµРЅРґРѕРІ
            if ($request->filled('brand_ids')) {
                $good->brands()->attach($request->get('brand_ids'));
            }

            // РџСЂРёРІСЏР·РєР° С‚РµРіРѕРІ
            if ($request->filled('tag_ids')) {
                $good->tags()->attach($request->get('tag_ids'));
            }

            // РџСЂРёРІСЏР·РєР° СЃРІРѕР№СЃС‚РІ
            if ($request->filled('properties')) {
                foreach ($request->get('properties') as $property) {
                    // РџСЂРѕРІРµСЂСЏРµРј РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Рµ РїРѕР»СЏ
                    if (empty($property['property_id'])) {
                        continue;
                    }

                    $propertyValueId = null;

                    // Р•СЃР»Рё РµСЃС‚СЊ shop_property_value_id, РёСЃРїРѕР»СЊР·СѓРµРј РµРіРѕ
                    if (! empty($property['shop_property_value_id'])) {
                        $propertyValueId = $property['shop_property_value_id'];
                    }
                    // Р•СЃР»Рё РµСЃС‚СЊ value, РёС‰РµРј РёР»Рё СЃРѕР·РґР°РµРј Р·Р°РїРёСЃСЊ РІ shop_property_values
                    elseif (! empty($property['value'])) {
                        $propertyValue = \App\Models\Shop\PropertyValue::firstOrCreate([
                            'property_id' => $property['property_id'],
                            'value' => trim($property['value']),
                        ], [
                            'is_active' => true,
                            'sort_order' => 0,
                        ]);
                        $propertyValueId = $propertyValue->id;
                    }

                    // РџСЂРёРІСЏР·С‹РІР°РµРј СЃРІРѕР№СЃС‚РІРѕ С‚РѕР»СЊРєРѕ РµСЃР»Рё РµСЃС‚СЊ propertyValueId
                    if ($propertyValueId) {
                        $good->properties()->attach($property['property_id'], [
                            'shop_property_value_id' => $propertyValueId,
                        ]);
                    }
                }
            }

            // РђСѓРґРёС‚
            $this->logAudit($good, 'created', null, $good->toArray());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'РўРѕРІР°СЂ СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ',
                'data' => $good->load(['categories', 'brands', 'tags', 'properties']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ С‚РѕРІР°СЂР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ С‚РѕРІР°СЂ
     */
    public function update(Request $request, $id): JsonResponse
    {
        $good = ShopGood::findOrFail($id);
        $oldValues = $good->toArray();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shop_goods', 'slug')->ignore($id)],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('shop_goods', 'sku')->ignore($id)],
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'demping_price' => 'nullable|numeric|min:0',
            'show_demping' => 'boolean',
            'label_id' => 'nullable|exists:shop_labels,id',
            'stock_quantity' => 'integer|min:0',
            'remote_stock_quantity' => 'nullable|string|max:255',
            'fast_remote_stock_quantity' => 'nullable|string|max:255',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_sale' => 'boolean',
            'is_preorder' => 'boolean',
            'is_show' => 'boolean',
            'sort_order' => 'integer',
            'category_ids' => 'array',
            'category_ids.*' => 'exists:shop_categories,id',
            'brand_ids' => 'array',
            'brand_ids.*' => 'exists:shop_brands,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'exists:shop_tags,id',
            'properties' => 'array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            'properties.*.value' => 'nullable|string|max:255',
            'properties.*.shop_property_value_id' => 'nullable|exists:shop_property_values,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // РџРѕРґРіРѕС‚Р°РІР»РёРІР°РµРј РґР°РЅРЅС‹Рµ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
            $updateData = $request->only([
                'name', 'slug', 'sku', 'description', 'short_description',
                'price', 'sale_price', 'demping_price', 'show_demping', 'label_id',
                'stock_quantity', 'width', 'height',
                'depth', 'weight', 'meta_title', 'meta_description',
                'is_active', 'is_featured', 'is_new', 'is_sale', 'is_preorder', 'is_show', 'sort_order',
            ]);

            // РЇРІРЅРѕ РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј remote_stock_quantity - РІСЃРµРіРґР° РѕР±РЅРѕРІР»СЏРµРј, РґР°Р¶Рµ РµСЃР»Рё null
            // РСЃРїРѕР»СЊР·СѓРµРј РїСЂСЏРјРѕР№ РґРѕСЃС‚СѓРї Рє РїРѕР»СЋ РёР· JSON С‚РµР»Р° Р·Р°РїСЂРѕСЃР°
            $allRequestData = $request->all();
            if (isset($allRequestData['remote_stock_quantity'])) {
                $remoteStockValue = $allRequestData['remote_stock_quantity'];
                $updateData['remote_stock_quantity'] = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string) $remoteStockValue;
            }

            // РЇРІРЅРѕ РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј fast_remote_stock_quantity - РІСЃРµРіРґР° РѕР±РЅРѕРІР»СЏРµРј, РґР°Р¶Рµ РµСЃР»Рё null
            if (isset($allRequestData['fast_remote_stock_quantity'])) {
                $fastRemoteStockValue = $allRequestData['fast_remote_stock_quantity'];
                $updateData['fast_remote_stock_quantity'] = ($fastRemoteStockValue === '' || $fastRemoteStockValue === null) ? null : (string) $fastRemoteStockValue;
            }

            // РћР±РЅРѕРІР»СЏРµРј С‚РѕРІР°СЂ
            $good->update($updateData);

            // РћР±РЅРѕРІР»РµРЅРёРµ РєР°С‚РµРіРѕСЂРёР№
            if ($request->has('category_ids')) {
                $good->categories()->sync($request->get('category_ids', []));
            }

            // РћР±РЅРѕРІР»РµРЅРёРµ Р±СЂРµРЅРґРѕРІ
            if ($request->has('brand_ids')) {
                $good->brands()->sync($request->get('brand_ids', []));
            }

            // РћР±РЅРѕРІР»РµРЅРёРµ С‚РµРіРѕРІ
            if ($request->has('tag_ids')) {
                $good->tags()->sync($request->get('tag_ids', []));
            }

            // РћР±РЅРѕРІР»РµРЅРёРµ СЃРІРѕР№СЃС‚РІ (РїРѕРґРґРµСЂР¶РєР° РґРІСѓС… СЃС…РµРј: РєРѕР»РѕРЅРєР° value Р»РёР±Рѕ shop_property_value_id)
            $lastSyncData = [];
            if ($request->has('properties')) {
                $incoming = $request->get('properties', []);

                $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
                $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
                $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

                // РћС‡РёСЃС‚РёРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ СЃРІРѕР№СЃС‚РІР° С‚РѕРІР°СЂР° (С‚РѕР»СЊРєРѕ Р±Р°Р·РѕРІС‹Рµ, РµСЃР»Рё РµСЃС‚СЊ РєРѕР»РѕРЅРєР° variation_id)
                $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                if ($hasVariationIdCol) {
                    $deleteQuery->whereNull('variation_id');
                }
                $deleted = $deleteQuery->delete();

                foreach ($incoming as $property) {
                    if (empty($property['property_id'])) {
                        continue;
                    }

                    $propertyId = (int) $property['property_id'];

                    // Р РµР¶РёРј С‡РµСЂРµР· СЃРїСЂР°РІРѕС‡РЅРёРє Р·РЅР°С‡РµРЅРёР№
                    if ($hasShopValueIdCol) {
                        $propertyValueId = null;
                        if (! empty($property['shop_property_value_id'])) {
                            // Р•СЃР»Рё РµСЃС‚СЊ shop_property_value_id Рё РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ, РѕР±РЅРѕРІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ Р·РЅР°С‡РµРЅРёРµ
                            $existingValueId = (int) $property['shop_property_value_id'];
                            $existingValue = \App\Models\Shop\PropertyValue::find($existingValueId);

                            if (! empty($property['value']) && $existingValue) {
                                $valueToSave = trim($property['value']);
                                // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РЅР°С‡Р°Р»Рµ Рё РєРѕРЅС†Рµ Р·РЅР°С‡РµРЅРёСЏ РїРµСЂРµРґ СЃРѕС…СЂР°РЅРµРЅРёРµРј
                                $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                                $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                                $valueToSave = trim($valueToSave);

                                // РћР±РЅРѕРІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ Р·РЅР°С‡РµРЅРёРµ
                                $existingValue->update(['value' => $valueToSave]);
                                $propertyValueId = $existingValueId;
                            } else {
                                // Р•СЃР»Рё Р·РЅР°С‡РµРЅРёСЏ РЅРµС‚, РёСЃРїРѕР»СЊР·СѓРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ ID
                                $propertyValueId = $existingValueId;
                            }
                        } elseif (! empty($property['value'])) {
                            $valueToSave = trim($property['value']);
                            // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РЅР°С‡Р°Р»Рµ Рё РєРѕРЅС†Рµ Р·РЅР°С‡РµРЅРёСЏ РїРµСЂРµРґ СЃРѕС…СЂР°РЅРµРЅРёРµРј
                            $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                            $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                            $valueToSave = trim($valueToSave);

                            $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                                'property_id' => $propertyId,
                                'value' => $valueToSave,
                            ], [
                                'is_active' => true,
                                'sort_order' => 0,
                            ]);
                            $propertyValueId = (int) $pv->id;
                        }

                        if ($propertyValueId) {
                            DB::table('shop_good_properties')->insert([
                                'good_id' => $good->id,
                                'property_id' => $propertyId,
                                'shop_property_value_id' => $propertyValueId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $lastSyncData[] = ['property_id' => $propertyId, 'shop_property_value_id' => $propertyValueId];
                        }
                    }
                    // Р РµР¶РёРј С…СЂР°РЅРµРЅРёСЏ РїСЂСЏРјРѕРіРѕ С‚РµРєСЃС‚Р° Р·РЅР°С‡РµРЅРёСЏ
                    elseif ($hasValueCol) {
                        $textValue = null;
                        if (! empty($property['value'])) {
                            $textValue = trim($property['value']);
                        } elseif (! empty($property['shop_property_value_id'])) {
                            $found = \App\Models\Shop\PropertyValue::find((int) $property['shop_property_value_id']);
                            $textValue = $found ? $found->value : null;
                        }

                        if ($textValue !== null && $textValue !== '') {
                            DB::table('shop_good_properties')->insert([
                                'good_id' => $good->id,
                                'property_id' => $propertyId,
                                'value' => $textValue,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $lastSyncData[] = ['property_id' => $propertyId, 'value' => $textValue];
                        }
                    }
                }
            }

            // РђСѓРґРёС‚
            $this->logAudit($good, 'updated', $oldValues, $good->fresh()->toArray());

            DB::commit();

            // РћР±РЅРѕРІР»СЏРµРј РјРѕРґРµР»СЊ РёР· Р‘Р” РґР»СЏ РїРѕР»СѓС‡РµРЅРёСЏ Р°РєС‚СѓР°Р»СЊРЅС‹С… РґР°РЅРЅС‹С…
            $good->refresh();

            // РџРѕРґС‚РІРµСЂР¶РґР°РµРј СЂРµР·СѓР»СЊС‚Р°С‚: РІРѕР·РІСЂР°С‰Р°РµРј СЃРІРѕР№СЃС‚РІР° СЃ pivot
            $good->load(['properties' => function ($q) {
                $q->withPivot('shop_property_value_id');
            }]);

            return response()->json([
                'success' => true,
                'message' => 'РўРѕРІР°СЂ СѓСЃРїРµС€РЅРѕ РѕР±РЅРѕРІР»РµРЅ',
                'data' => $good->load(['categories', 'brands', 'tags', 'properties']),
                'debug' => [
                    'attached_count' => isset($lastSyncData) ? count($lastSyncData) : 0,
                    'attached' => $lastSyncData,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ С‚РѕРІР°СЂР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё С‚РѕРІР°СЂР° РѕС‚РґРµР»СЊРЅС‹Рј СЌРЅРґРїРѕРёРЅС‚РѕРј
     */
    public function updateProperties(Request $request, $id): JsonResponse
    {
        $good = ShopGood::findOrFail($id);

        $properties = $request->get('properties', []);

        // Р’Р°Р»РёРґР°С†РёСЏ: РјР°СЃСЃРёРІ РѕР±СЏР·Р°С‚РµР»РµРЅ, РЅРѕ РјРѕР¶РµС‚ Р±С‹С‚СЊ РїСѓСЃС‚С‹Рј
        $rules = [
            'properties' => 'present|array',
        ];

        // Р•СЃР»Рё РјР°СЃСЃРёРІ РЅРµ РїСѓСЃС‚РѕР№, РґРѕР±Р°РІР»СЏРµРј РїСЂР°РІРёР»Р° РґР»СЏ СЌР»РµРјРµРЅС‚РѕРІ
        if (! empty($properties)) {
            $rules['properties.*.property_id'] = 'required|exists:shop_properties,id';
            $rules['properties.*.value'] = 'nullable|string|max:255';
            $rules['properties.*.shop_property_value_id'] = 'nullable|exists:shop_property_values,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $incoming = $request->get('properties', []);

            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
            $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

            // РћС‡РёСЃС‚РёРј С‚РѕР»СЊРєРѕ Р±Р°Р·РѕРІС‹Рµ СЃРІРѕР№СЃС‚РІР°
            $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
            if ($hasVariationIdCol) {
                $deleteQuery->whereNull('variation_id');
            }
            $deleted = $deleteQuery->delete();

            $lastSyncData = [];
            foreach ($incoming as $property) {
                if (empty($property['property_id'])) {
                    continue;
                }
                $propertyId = (int) $property['property_id'];

                if ($hasShopValueIdCol) {
                    $propertyValueId = null;
                    if (! empty($property['shop_property_value_id'])) {
                        // Р•СЃР»Рё РµСЃС‚СЊ shop_property_value_id Рё РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ, РѕР±РЅРѕРІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ Р·РЅР°С‡РµРЅРёРµ
                        $existingValueId = (int) $property['shop_property_value_id'];
                        $existingValue = \App\Models\Shop\PropertyValue::find($existingValueId);

                        if (! empty($property['value']) && $existingValue) {
                            $valueToSave = trim($property['value']);
                            // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РЅР°С‡Р°Р»Рµ Рё РєРѕРЅС†Рµ Р·РЅР°С‡РµРЅРёСЏ РїРµСЂРµРґ СЃРѕС…СЂР°РЅРµРЅРёРµРј
                            $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                            $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                            $valueToSave = trim($valueToSave);

                            // РћР±РЅРѕРІР»СЏРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ Р·РЅР°С‡РµРЅРёРµ
                            $existingValue->update(['value' => $valueToSave]);
                            $propertyValueId = $existingValueId;
                        } else {
                            // Р•СЃР»Рё Р·РЅР°С‡РµРЅРёСЏ РЅРµС‚, РёСЃРїРѕР»СЊР·СѓРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ ID
                            $propertyValueId = $existingValueId;
                        }
                    } elseif (! empty($property['value'])) {
                        $valueToSave = trim($property['value']);
                        // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РЅР°С‡Р°Р»Рµ Рё РєРѕРЅС†Рµ Р·РЅР°С‡РµРЅРёСЏ РїРµСЂРµРґ СЃРѕС…СЂР°РЅРµРЅРёРµРј
                        $valueToSave = preg_replace('/^:\s*/', '', $valueToSave);
                        $valueToSave = preg_replace('/\s*:\s*$/', '', $valueToSave);
                        $valueToSave = trim($valueToSave);

                        $pv = \App\Models\Shop\PropertyValue::firstOrCreate([
                            'property_id' => $propertyId,
                            'value' => $valueToSave,
                        ], [
                            'is_active' => true,
                            'sort_order' => 0,
                        ]);
                        $propertyValueId = (int) $pv->id;
                    }
                    if ($propertyValueId) {
                        DB::table('shop_good_properties')->insert([
                            'good_id' => $good->id,
                            'property_id' => $propertyId,
                            'shop_property_value_id' => $propertyValueId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $lastSyncData[] = ['property_id' => $propertyId, 'shop_property_value_id' => $propertyValueId];
                    }
                } elseif ($hasValueCol) {
                    $textValue = null;
                    if (! empty($property['value'])) {
                        $textValue = trim($property['value']);
                        // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РЅР°С‡Р°Р»Рµ Рё РєРѕРЅС†Рµ Р·РЅР°С‡РµРЅРёСЏ
                        $textValue = preg_replace('/^:\s*/', '', $textValue);
                        $textValue = preg_replace('/\s*:\s*$/', '', $textValue);
                        $textValue = trim($textValue);
                    } elseif (! empty($property['shop_property_value_id'])) {
                        $found = \App\Models\Shop\PropertyValue::find((int) $property['shop_property_value_id']);
                        $textValue = $found ? $found->value : null;
                        if ($textValue) {
                            // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РЅР°С‡Р°Р»Рµ Рё РєРѕРЅС†Рµ Р·РЅР°С‡РµРЅРёСЏ
                            $textValue = preg_replace('/^:\s*/', '', $textValue);
                            $textValue = preg_replace('/\s*:\s*$/', '', $textValue);
                            $textValue = trim($textValue);
                        }
                    }
                    if ($textValue !== null && $textValue !== '') {
                        DB::table('shop_good_properties')->insert([
                            'good_id' => $good->id,
                            'property_id' => $propertyId,
                            'value' => $textValue,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $lastSyncData[] = ['property_id' => $propertyId, 'value' => $textValue];
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'РЎРІРѕР№СЃС‚РІР° РѕР±РЅРѕРІР»РµРЅС‹',
                'data' => [
                    'attached' => $lastSyncData,
                    'count' => count($lastSyncData),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ СЃРІРѕР№СЃС‚РІ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЈРґР°Р»РёС‚СЊ С‚РѕРІР°СЂ
     */
    public function destroy($id): JsonResponse
    {
        $good = ShopGood::findOrFail($id);
        $oldValues = $good->toArray();

        try {
            DB::beginTransaction();

            // РђСѓРґРёС‚
            $this->logAudit($good, 'deleted', $oldValues, null);

            $good->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'РўРѕРІР°СЂ СѓСЃРїРµС€РЅРѕ СѓРґР°Р»РµРЅ',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СѓРґР°Р»РµРЅРёСЏ С‚РѕРІР°СЂР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РњР°СЃСЃРѕРІРѕРµ РѕР±РЅРѕРІР»РµРЅРёРµ С‚РѕРІР°СЂРѕРІ
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        // РџРѕР»СѓС‡Р°РµРј СЃС‹СЂС‹Рµ JSON РґР°РЅРЅС‹Рµ РґРѕ РѕР±СЂР°Р±РѕС‚РєРё middleware
        $rawJsonData = json_decode($request->getContent(), true);

        $action = $rawJsonData['action'] ?? $request->get('action');

        // Р”Р»СЏ РґРµР№СЃС‚РІРёР№ clear_by_tags Рё clear_by_suppliers ids РЅРµ РѕР±СЏР·Р°С‚РµР»РµРЅ, С‚Р°Рє РєР°Рє РёСЃРїРѕР»СЊР·СѓСЋС‚СЃСЏ С„РёР»СЊС‚СЂС‹ РІ data
        $idsRules = in_array($action, ['clear_by_tags', 'clear_by_suppliers'])
            ? 'nullable|array'
            : 'required|array';

        $validator = Validator::make($rawJsonData, [
            'ids' => $idsRules,
            'ids.*' => 'exists:shop_goods,id',
            'action' => 'required|in:activate,deactivate,delete,delete_without_supplier,update_categories,update_brands,update_tags,update_properties,update_stock,update_remote_stock,update_fast_remote_stock,update_price,update_sale_price,update_demping_price,toggle_show_demping,toggle_fields,update_label,remove_after_symbol,replace_text,update_dimensions,enable_preorder,disable_preorder,clear_by_tags,clear_by_suppliers,delete_images,delete_zero_stock_no_media',
            'data' => 'nullable|array',
            'data.field' => 'nullable|in:name,description,short_description',
            'data.mode' => 'nullable|in:exact,start_end',
            'data.delete_type' => 'nullable|in:goods,variations,goods_and_variations',
            'data.variation_ids' => 'nullable|array',
            'data.variation_ids.*' => 'exists:shop_good_variations,id',
            'data.show_demping' => 'nullable|boolean',
            'data.is_sale' => 'nullable|boolean',
            'data.is_new' => 'nullable|boolean',
            'data.is_featured' => 'nullable|boolean',
            'data.is_show' => 'nullable|boolean',
            'data.search' => 'nullable|string',
            'data.replace' => 'nullable|string',
            'data.start' => 'nullable|string',
            'data.end' => 'nullable|string',
            'data.normalize_spaces' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ids = $request->get('ids');
            $action = $request->get('action');
            // РџРѕР»СѓС‡Р°РµРј РґР°РЅРЅС‹Рµ РЅР°РїСЂСЏРјСѓСЋ РёР· JSON, Р±РµР· РѕР±СЂР°Р±РѕС‚РєРё middleware
            $jsonData = json_decode($request->getContent(), true);
            $data = $jsonData['data'] ?? [];

            // Р”Р»СЏ РјР°СЃСЃРѕРІРѕРіРѕ СѓРґР°Р»РµРЅРёСЏ РїРѕ РјРµС‚РєР°Рј/РїРѕСЃС‚Р°РІС‰РёРєР°Рј РґРµР»Р°РµРј РїСЂСЏРјС‹Рµ Р·Р°РїСЂРѕСЃС‹
            if (in_array($action, ['clear_by_tags', 'clear_by_suppliers'])) {
                $query = ShopGood::query();

                // Р•СЃР»Рё РїСЂРёС€Р»Рё С„РёР»СЊС‚СЂС‹ РІ data, РёСЃРїРѕР»СЊР·СѓРµРј РёС… РґР»СЏ РїРѕРёСЃРєР° С‚РѕРІР°СЂРѕРІ
                if (! empty($data)) {
                    // Р¤РёР»СЊС‚СЂ РїРѕ РєР°С‚РµРіРѕСЂРёСЏРј
                    if (isset($data['categories']) && is_array($data['categories']) && ! empty($data['categories'])) {
                        $query->whereHas('categories', function ($q) use ($data) {
                            $q->whereIn('shop_categories.id', $data['categories']);
                        });
                    }

                    // Р¤РёР»СЊС‚СЂ РїРѕ Р±СЂРµРЅРґР°Рј
                    if (isset($data['brands']) && is_array($data['brands']) && ! empty($data['brands'])) {
                        $query->whereHas('brands', function ($q) use ($data) {
                            $q->whereIn('shop_brands.id', $data['brands']);
                        });
                    }

                    // Р¤РёР»СЊС‚СЂ РїРѕ Р»РµР№Р±Р»Р°Рј
                    if (isset($data['labels']) && is_array($data['labels']) && ! empty($data['labels'])) {
                        $query->whereIn('label_id', $data['labels']);
                    }

                    // Р¤РёР»СЊС‚СЂ РїРѕ С‚РµРіР°Рј
                    if (isset($data['tags']) && is_array($data['tags']) && ! empty($data['tags'])) {
                        $query->whereHas('tags', function ($q) use ($data) {
                            $q->whereIn('shop_tags.id', $data['tags']);
                        });
                    }

                    // Р¤РёР»СЊС‚СЂ РїРѕ РїРѕСЃС‚Р°РІС‰РёРєР°Рј
                    if (isset($data['suppliers']) && is_array($data['suppliers']) && ! empty($data['suppliers'])) {
                        $includeVariations = isset($data['suppliers_include_variations']) && $data['suppliers_include_variations'];

                        if ($includeVariations) {
                            // Р’РєР»СЋС‡Р°РµРј С‚РѕРІР°СЂС‹ СЃ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё Р С‚РѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё СЃ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                            $query->where(function ($q) use ($data) {
                                // РўРѕРІР°СЂС‹ СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                                $q->whereIn('supplier', $data['suppliers'])
                                  // РР»Рё С‚РѕРІР°СЂС‹, Сѓ РєРѕС‚РѕСЂС‹С… РµСЃС‚СЊ РІР°СЂРёР°С†РёРё СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                                    ->orWhereHas('variations', function ($varQ) use ($data) {
                                        $varQ->whereIn('supplier', $data['suppliers']);
                                    });
                            });
                        } else {
                            // РўРѕР»СЊРєРѕ С‚РѕРІР°СЂС‹ СЃ РїРѕСЃС‚Р°РІС‰РёРєР°РјРё (Р±РµР· СѓС‡РµС‚Р° РІР°СЂРёР°С†РёР№)
                            $query->whereIn('supplier', $data['suppliers']);
                        }
                    }
                } else {
                    // Р•СЃР»Рё С„РёР»СЊС‚СЂРѕРІ РЅРµС‚, РёСЃРїРѕР»СЊР·СѓРµРј РїРµСЂРµРґР°РЅРЅС‹Рµ ID
                    if (! empty($ids)) {
                        $query->whereIn('id', $ids);
                    } else {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'РќРµ СѓРєР°Р·Р°РЅС‹ ID С‚РѕРІР°СЂРѕРІ РёР»Рё С„РёР»СЊС‚СЂС‹ РґР»СЏ СѓРґР°Р»РµРЅРёСЏ',
                        ], 400);
                    }
                }

                // РџРѕР»СѓС‡Р°РµРј ID С‚РѕРІР°СЂРѕРІ РґР»СЏ СѓРґР°Р»РµРЅРёСЏ
                $goodIds = $query->pluck('id')->toArray();

                // Р”Р»СЏ РїРѕСЃС‚Р°РІС‰РёРєРѕРІ С‚Р°РєР¶Рµ РїРѕР»СѓС‡Р°РµРј ID РІР°СЂРёР°С†РёР№ СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё
                $variationIdsToDelete = [];
                if ($action === 'clear_by_suppliers' && isset($data['suppliers']) && is_array($data['suppliers']) && ! empty($data['suppliers'])) {
                    // РџРѕР»СѓС‡Р°РµРј РІР°СЂРёР°С†РёРё СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё (РґР°Р¶Рµ РµСЃР»Рё РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ РЅРµ РёРјРµРµС‚ СЌС‚РѕРіРѕ РїРѕСЃС‚Р°РІС‰РёРєР°)
                    $variationIdsToDelete = ShopGoodVariation::whereIn('supplier', $data['suppliers'])
                        ->pluck('id')
                        ->toArray();
                }

                if (empty($goodIds) && empty($variationIdsToDelete)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'РќРµ РЅР°Р№РґРµРЅРѕ С‚РѕРІР°СЂРѕРІ РёР»Рё РІР°СЂРёР°С†РёР№ РґР»СЏ СѓРґР°Р»РµРЅРёСЏ',
                    ], 404);
                }

                // РЈРґР°Р»СЏРµРј РІР°СЂРёР°С†РёРё СЃ РІС‹Р±СЂР°РЅРЅС‹РјРё РїРѕСЃС‚Р°РІС‰РёРєР°РјРё (РµСЃР»Рё РµСЃС‚СЊ)
                $variationsDeletedBySupplier = 0;
                if (! empty($variationIdsToDelete)) {
                    // РЈРґР°Р»СЏРµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ СЌС‚РёС… РІР°СЂРёР°С†РёР№
                    ShopGoodImage::whereIn('variation_id', $variationIdsToDelete)->delete();
                    // РЈРґР°Р»СЏРµРј РІР°СЂРёР°С†РёРё
                    $variationsDeletedBySupplier = ShopGoodVariation::whereIn('id', $variationIdsToDelete)->delete();
                }

                // РЈРґР°Р»СЏРµРј РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂРѕРІ, РєРѕС‚РѕСЂС‹Рµ Р±СѓРґСѓС‚ СѓРґР°Р»РµРЅС‹
                $variationsDeletedByGood = 0;
                if (! empty($goodIds)) {
                    $variationsDeletedByGood = ShopGoodVariation::whereIn('good_id', $goodIds)->delete();
                }

                // РЈРґР°Р»СЏРµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂРѕРІ Рё РІР°СЂРёР°С†РёР№
                if (! empty($goodIds)) {
                    ShopGoodImage::whereIn('good_id', $goodIds)->delete();
                }

                // РЈРґР°Р»СЏРµРј С‚РѕРІР°СЂС‹
                $deletedCount = 0;
                if (! empty($goodIds)) {
                    $deletedCount = ShopGood::whereIn('id', $goodIds)->delete();
                }

                $totalVariationsDeleted = $variationsDeletedBySupplier + $variationsDeletedByGood;

                DB::commit();

                $message = '';
                if ($deletedCount > 0) {
                    $message .= "РЈРґР°Р»РµРЅРѕ {$deletedCount} С‚РѕРІР°СЂРѕРІ";
                }
                if ($totalVariationsDeleted > 0) {
                    if ($message) {
                        $message .= ' Рё ';
                    }
                    $message .= "{$totalVariationsDeleted} РІР°СЂРёР°С†РёР№";
                }
                if (! $message) {
                    $message = 'РќРµС‡РµРіРѕ СѓРґР°Р»СЏС‚СЊ';
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'deleted_count' => $deletedCount,
                    'variations_deleted' => $totalVariationsDeleted,
                    'variations_deleted_by_supplier' => $variationsDeletedBySupplier,
                    'variations_deleted_by_good' => $variationsDeletedByGood,
                ]);
            }

            // РЎРїРµС†РёР°Р»СЊРЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° РґР»СЏ СѓРґР°Р»РµРЅРёСЏ РІС‹Р±СЂР°РЅРЅС‹С… РІР°СЂРёР°С†РёР№ СЃ РЅСѓР»РµРІС‹Рј РѕСЃС‚Р°С‚РєРѕРј
            // СЃ РїСЂРµРґРІР°СЂРёС‚РµР»СЊРЅС‹Рј РїРµСЂРµРјРµС‰РµРЅРёРµРј РёР·РѕР±СЂР°Р¶РµРЅРёР№ РІ РґСЂСѓРіРёРµ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР°
            if ($action === 'delete_zero_stock_no_media') {
                $variationIds = $data['variation_ids'] ?? [];

                if (empty($variationIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'РќРµ СѓРєР°Р·Р°РЅС‹ ID РІР°СЂРёР°С†РёР№ РґР»СЏ СѓРґР°Р»РµРЅРёСЏ',
                    ], 400);
                }

                // РџРѕР»СѓС‡Р°РµРј РІР°СЂРёР°С†РёРё РґР»СЏ РѕР±СЂР°Р±РѕС‚РєРё
                $variationsToDelete = ShopGoodVariation::whereIn('id', $variationIds)
                    ->whereIn('good_id', $ids) // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР°, С‡С‚Рѕ РІР°СЂРёР°С†РёРё РїСЂРёРЅР°РґР»РµР¶Р°С‚ РІС‹Р±СЂР°РЅРЅС‹Рј С‚РѕРІР°СЂР°Рј
                    ->where('stock_quantity', 0)
                    ->where(function ($query) {
                        $query->whereNull('remote_stock_quantity')
                            ->orWhere('remote_stock_quantity', 0);
                    })
                    ->where(function ($query) {
                        $query->whereNull('fast_remote_stock_quantity')
                            ->orWhere('fast_remote_stock_quantity', 0);
                    })
                    ->with(['images', 'good']) // Р—Р°РіСЂСѓР¶Р°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ Рё С‚РѕРІР°СЂ
                    ->get();

                $deletedCount = 0;
                $movedImagesCount = 0;

                foreach ($variationsToDelete as $variation) {
                    // Р•СЃР»Рё Сѓ РІР°СЂРёР°С†РёРё РµСЃС‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ, РїС‹С‚Р°РµРјСЃСЏ РёС… РїРµСЂРµРЅРµСЃС‚Рё
                    if ($variation->images->count() > 0) {
                        // РќР°С…РѕРґРёРј РґСЂСѓРіРёРµ РІР°СЂРёР°С†РёРё С‚РѕРіРѕ Р¶Рµ С‚РѕРІР°СЂР° (Р»СЋР±С‹Рµ, РєСЂРѕРјРµ С‚РµС… С‡С‚Рѕ Р±СѓРґСѓС‚ СѓРґР°Р»РµРЅС‹)
                        // РџСЂРёРѕСЂРёС‚РµС‚: Р°РєС‚РёРІРЅС‹Рµ РІР°СЂРёР°С†РёРё СЃ РїРѕР»РѕР¶РёС‚РµР»СЊРЅС‹Рј РѕСЃС‚Р°С‚РєРѕРј, Р·Р°С‚РµРј Р°РєС‚РёРІРЅС‹Рµ РІР°СЂРёР°С†РёРё, Р·Р°С‚РµРј Р»СЋР±С‹Рµ РґСЂСѓРіРёРµ
                        $targetVariation = ShopGoodVariation::where('good_id', $variation->good_id)
                            ->where('id', '!=', $variation->id)
                            ->whereNotIn('id', $variationIds) // РСЃРєР»СЋС‡Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё, РєРѕС‚РѕСЂС‹Рµ Р±СѓРґСѓС‚ СѓРґР°Р»РµРЅС‹ РІ СЌС‚РѕРј Р·Р°РїСЂРѕСЃРµ
                            ->orderByRaw('
                                CASE
                                    WHEN is_active = 1 AND (stock_quantity > 0 OR remote_stock_quantity > 0 OR fast_remote_stock_quantity > 0) THEN 1
                                    WHEN is_active = 1 THEN 2
                                    ELSE 3
                                END,
                                sort_order ASC
                            ')
                            ->first();

                        // Р•СЃР»Рё РЅР°С€Р»Рё С†РµР»РµРІСѓСЋ РІР°СЂРёР°С†РёСЋ, РїРµСЂРµРјРµС‰Р°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
                        if ($targetVariation) {
                            foreach ($variation->images as $image) {
                                $image->variation_id = $targetVariation->id;
                                $image->save();
                                $movedImagesCount++;
                            }
                        } else {
                            // Р•СЃР»Рё РЅРµС‚ РґСЂСѓРіРёС… РІР°СЂРёР°С†РёР№, РѕС‚РІСЏР·С‹РІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РѕС‚ РІР°СЂРёР°С†РёРё (РїСЂРёРІСЏР·С‹РІР°РµРј Рє С‚РѕРІР°СЂСѓ)
                            foreach ($variation->images as $image) {
                                $image->variation_id = null;
                                $image->save();
                                $movedImagesCount++;
                            }
                        }
                    }
                    // Р’Р°СЂРёР°С†РёРё Р±РµР· РёР·РѕР±СЂР°Р¶РµРЅРёР№ РїСЂРѕСЃС‚Рѕ СѓРґР°Р»СЏСЋС‚СЃСЏ Р±РµР· РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅС‹С… РґРµР№СЃС‚РІРёР№

                    // РЈРґР°Р»СЏРµРј РІР°СЂРёР°С†РёСЋ
                    $variation->delete();
                    $deletedCount++;
                }

                DB::commit();

                $message = "РЈРґР°Р»РµРЅРѕ {$deletedCount} РІР°СЂРёР°С†РёР№ СЃ РЅСѓР»РµРІС‹Рј РѕСЃС‚Р°С‚РєРѕРј";
                if ($movedImagesCount > 0) {
                    $message .= ". РџРµСЂРµРјРµС‰РµРЅРѕ {$movedImagesCount} РёР·РѕР±СЂР°Р¶РµРЅРёР№";
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'deleted_count' => $deletedCount,
                    'moved_images_count' => $movedImagesCount,
                    'variation_ids' => $variationIds,
                ]);
            }

            $goods = ShopGood::whereIn('id', $ids)->get();
            $deletedCount = 0; // РЎС‡РµС‚С‡РёРє СѓРґР°Р»РµРЅРЅС‹С… С‚РѕРІР°СЂРѕРІ РґР»СЏ delete_without_supplier
            $deletedVariationsCount = 0; // РЎС‡РµС‚С‡РёРє СѓРґР°Р»РµРЅРЅС‹С… РІР°СЂРёР°С†РёР№ РґР»СЏ delete_without_supplier

            foreach ($goods as $good) {
                if (! $good) {
                    continue; // РџСЂРѕРїСѓСЃРєР°РµРј null С‚РѕРІР°СЂС‹
                }
                $oldValues = $good->toArray();

                // Р”Р»СЏ СѓРґР°Р»РµРЅРёСЏ СЃРЅР°С‡Р°Р»Р° СЃРѕР·РґР°РµРј Р·Р°РїРёСЃСЊ Р°СѓРґРёС‚Р°, РїРѕС‚РѕРј СѓРґР°Р»СЏРµРј С‚РѕРІР°СЂ
                if ($action === 'delete') {
                    $this->logAudit($good, 'bulk_deleted', $oldValues, null);
                    $good->delete();

                    continue;
                }

                // Р”Р»СЏ СѓРґР°Р»РµРЅРёСЏ РІР°СЂРёР°С†РёР№ Р±РµР· РїРѕСЃС‚Р°РІС‰РёРєР° (С‚РѕРІР°СЂС‹ РЅРµ СѓРґР°Р»СЏРµРј, РґР°Р¶Рµ РµСЃР»Рё Сѓ С‚РѕРІР°СЂР° РЅРµ РѕСЃС‚Р°РЅРµС‚СЃСЏ РІР°СЂРёР°С†РёР№)
                if ($action === 'delete_without_supplier') {
                    // РЈРґР°Р»СЏРµРј С‚РѕР»СЊРєРѕ РІР°СЂРёР°С†РёРё Р±РµР· РїРѕСЃС‚Р°РІС‰РёРєР°
                    // Р’РђР–РќРћ: РћСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ РќР• СѓРґР°Р»СЏРµС‚СЃСЏ, РґР°Р¶Рµ РµСЃР»Рё Сѓ РЅРµРіРѕ РЅРµ РѕСЃС‚Р°РЅРµС‚СЃСЏ РІР°СЂРёР°С†РёР№
                    $variations = $good->variations()->get();
                    foreach ($variations as $variation) {
                        $variationSupplier = $variation->supplier;
                        if (empty($variationSupplier) || $variationSupplier === null || trim($variationSupplier) === '') {
                            $variation->delete();
                            $deletedVariationsCount++;
                        }
                    }

                    // РЇРІРЅРѕ СѓР±РµР¶РґР°РµРјСЃСЏ, С‡С‚Рѕ С‚РѕРІР°СЂ РЅРµ СѓРґР°Р»СЏРµС‚СЃСЏ - РїСЂРѕСЃС‚Рѕ РїСЂРѕРґРѕР»Р¶Р°РµРј С†РёРєР»
                    // РўРѕРІР°СЂ РѕСЃС‚Р°РµС‚СЃСЏ РІ Р±Р°Р·Рµ РґР°РЅРЅС‹С…, РґР°Р¶Рµ РµСЃР»Рё Сѓ РЅРµРіРѕ РЅРµ РѕСЃС‚Р°Р»РѕСЃСЊ РІР°СЂРёР°С†РёР№
                    continue;
                }

                switch ($action) {
                    case 'activate':
                        $good->update(['is_active' => true]);
                        break;
                    case 'deactivate':
                        $good->update(['is_active' => false]);
                        break;
                    case 'enable_preorder':
                        $good->update(['is_preorder' => true]);
                        break;
                    case 'disable_preorder':
                        $good->update(['is_preorder' => false]);
                        break;
                    case 'update_categories':
                        $currentCategoryIds = $good->categories()->pluck('shop_categories.id')->toArray();

                        // Р•СЃР»Рё СѓСЃС‚Р°РЅРѕРІР»РµРЅ С„Р»Р°Рі РѕС‡РёСЃС‚РєРё РІСЃРµС… РєР°С‚РµРіРѕСЂРёР№
                        if (isset($data['clear_all']) && $data['clear_all']) {
                            $good->categories()->sync([]);
                        } else {
                            // РЈРґР°Р»СЏРµРј РєР°С‚РµРіРѕСЂРёРё РёР· СЃРїРёСЃРєР° РЅР° СѓРґР°Р»РµРЅРёРµ
                            if (isset($data['category_ids_to_remove']) && is_array($data['category_ids_to_remove'])) {
                                $currentCategoryIds = array_diff($currentCategoryIds, $data['category_ids_to_remove']);
                            }

                            // Р”РѕР±Р°РІР»СЏРµРј РЅРѕРІС‹Рµ РєР°С‚РµРіРѕСЂРёРё
                            if (isset($data['category_ids']) && is_array($data['category_ids'])) {
                                $newCategoryIds = $data['category_ids'];
                                // РћР±СЉРµРґРёРЅСЏРµРј Рё СѓР±РёСЂР°РµРј РґСѓР±Р»РёРєР°С‚С‹
                                $allCategoryIds = array_unique(array_merge($currentCategoryIds, $newCategoryIds));
                            } else {
                                $allCategoryIds = $currentCategoryIds;
                            }

                            $good->categories()->sync($allCategoryIds);
                        }
                        break;
                    case 'update_brands':
                        $currentBrandIds = $good->brands()->pluck('shop_brands.id')->toArray();

                        // Р•СЃР»Рё СѓСЃС‚Р°РЅРѕРІР»РµРЅ С„Р»Р°Рі РѕС‡РёСЃС‚РєРё РІСЃРµС… Р±СЂРµРЅРґРѕРІ
                        if (isset($data['clear_all']) && $data['clear_all']) {
                            $good->brands()->sync([]);
                        } else {
                            // РЈРґР°Р»СЏРµРј Р±СЂРµРЅРґС‹ РёР· СЃРїРёСЃРєР° РЅР° СѓРґР°Р»РµРЅРёРµ
                            if (isset($data['brand_ids_to_remove']) && is_array($data['brand_ids_to_remove'])) {
                                $currentBrandIds = array_diff($currentBrandIds, $data['brand_ids_to_remove']);
                            }

                            // Р”РѕР±Р°РІР»СЏРµРј РЅРѕРІС‹Рµ Р±СЂРµРЅРґС‹
                            if (isset($data['brand_ids']) && is_array($data['brand_ids'])) {
                                $newBrandIds = $data['brand_ids'];
                                // РћР±СЉРµРґРёРЅСЏРµРј Рё СѓР±РёСЂР°РµРј РґСѓР±Р»РёРєР°С‚С‹
                                $allBrandIds = array_unique(array_merge($currentBrandIds, $newBrandIds));
                            } else {
                                $allBrandIds = $currentBrandIds;
                            }

                            $good->brands()->sync($allBrandIds);
                        }
                        break;
                    case 'update_tags':
                        $currentTagIds = $good->tags()->pluck('shop_tags.id')->toArray();

                        // Р•СЃР»Рё СѓСЃС‚Р°РЅРѕРІР»РµРЅ С„Р»Р°Рі РѕС‡РёСЃС‚РєРё РІСЃРµС… С‚РµРіРѕРІ
                        if (isset($data['clear_all']) && $data['clear_all']) {
                            $good->tags()->sync([]);
                        } else {
                            // РЈРґР°Р»СЏРµРј С‚РµРіРё РёР· СЃРїРёСЃРєР° РЅР° СѓРґР°Р»РµРЅРёРµ
                            if (isset($data['tag_ids_to_remove']) && is_array($data['tag_ids_to_remove'])) {
                                $currentTagIds = array_diff($currentTagIds, $data['tag_ids_to_remove']);
                            }

                            // Р”РѕР±Р°РІР»СЏРµРј РЅРѕРІС‹Рµ С‚РµРіРё
                            if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
                                $newTagIds = $data['tag_ids'];
                                // РћР±СЉРµРґРёРЅСЏРµРј Рё СѓР±РёСЂР°РµРј РґСѓР±Р»РёРєР°С‚С‹
                                $allTagIds = array_unique(array_merge($currentTagIds, $newTagIds));
                            } else {
                                $allTagIds = $currentTagIds;
                            }

                            $good->tags()->sync($allTagIds);
                        }
                        break;
                    case 'update_properties':
                        $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
                        $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
                        $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

                        // РџРѕР»СѓС‡Р°РµРј С‚РµРєСѓС‰РёРµ СЃРІРѕР№СЃС‚РІР° С‚РѕРІР°СЂР°
                        $currentProperties = [];
                        $existingPropertiesQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                        if ($hasVariationIdCol) {
                            $existingPropertiesQuery->whereNull('variation_id');
                        }
                        $existingProperties = $existingPropertiesQuery->get();

                        foreach ($existingProperties as $existingProp) {
                            $currentProperties[] = [
                                'property_id' => $existingProp->property_id,
                                'shop_property_value_id' => $existingProp->shop_property_value_id ?? null,
                                'value' => $existingProp->value ?? null,
                            ];
                        }

                        // Р•СЃР»Рё СѓСЃС‚Р°РЅРѕРІР»РµРЅ С„Р»Р°Рі РѕС‡РёСЃС‚РєРё РІСЃРµС… СЃРІРѕР№СЃС‚РІ
                        if (isset($data['clear_all']) && $data['clear_all']) {
                            $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                            if ($hasVariationIdCol) {
                                $deleteQuery->whereNull('variation_id');
                            }
                            $deleteQuery->delete();
                            $currentProperties = [];
                        } else {
                            // РЈРґР°Р»СЏРµРј СЃРІРѕР№СЃС‚РІР° РёР· СЃРїРёСЃРєР° РЅР° СѓРґР°Р»РµРЅРёРµ
                            if (isset($data['properties_to_remove']) && is_array($data['properties_to_remove'])) {
                                foreach ($data['properties_to_remove'] as $propertyToRemove) {
                                    $removePropertyId = (int) $propertyToRemove['property_id'];
                                    $removeShopPropertyValueId = isset($propertyToRemove['shop_property_value_id']) ? (int) $propertyToRemove['shop_property_value_id'] : null;
                                    $removeValue = isset($propertyToRemove['value']) ? trim($propertyToRemove['value']) : null;

                                    $currentProperties = array_filter($currentProperties, function ($prop) use ($removePropertyId, $removeShopPropertyValueId, $removeValue, $hasShopValueIdCol, $hasValueCol) {
                                        if ($prop['property_id'] != $removePropertyId) {
                                            return true;
                                        }

                                        if ($hasShopValueIdCol && $removeShopPropertyValueId !== null) {
                                            $propShopPropertyValueId = isset($prop['shop_property_value_id']) ? (int) $prop['shop_property_value_id'] : null;

                                            return $propShopPropertyValueId != $removeShopPropertyValueId;
                                        }

                                        if ($hasValueCol && $removeValue !== null) {
                                            $propValue = trim($prop['value'] ?? '');

                                            return $propValue !== $removeValue;
                                        }

                                        return true;
                                    });
                                }
                            }

                            // РўРµРїРµСЂСЊ СѓРґР°Р»СЏРµРј СЃРІРѕР№СЃС‚РІР° РёР· Р±Р°Р·С‹ РґР°РЅРЅС‹С…, РєРѕС‚РѕСЂС‹С… РЅРµС‚ РІ РѕС‚С„РёР»СЊС‚СЂРѕРІР°РЅРЅРѕРј РјР°СЃСЃРёРІРµ
                            if (isset($data['properties_to_remove']) && is_array($data['properties_to_remove'])) {
                                foreach ($data['properties_to_remove'] as $propertyToRemove) {
                                    $removePropertyId = (int) $propertyToRemove['property_id'];
                                    $removeShopPropertyValueId = isset($propertyToRemove['shop_property_value_id']) ? (int) $propertyToRemove['shop_property_value_id'] : null;
                                    $removeValue = isset($propertyToRemove['value']) ? trim($propertyToRemove['value']) : null;

                                    // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё СЌС‚Рѕ СЃРІРѕР№СЃС‚РІРѕ РІ РѕС‚С„РёР»СЊС‚СЂРѕРІР°РЅРЅРѕРј РјР°СЃСЃРёРІРµ
                                    $stillExists = false;
                                    foreach ($currentProperties as $existing) {
                                        if ($existing['property_id'] == $removePropertyId) {
                                            if ($hasShopValueIdCol && $removeShopPropertyValueId !== null) {
                                                $existingShopPropertyValueId = isset($existing['shop_property_value_id']) ? (int) $existing['shop_property_value_id'] : null;
                                                if ($existingShopPropertyValueId == $removeShopPropertyValueId) {
                                                    $stillExists = true;
                                                    break;
                                                }
                                            } elseif ($hasValueCol && $removeValue !== null) {
                                                $existingValue = trim($existing['value'] ?? '');
                                                if ($existingValue === $removeValue) {
                                                    $stillExists = true;
                                                    break;
                                                }
                                            } else {
                                                // Р•СЃР»Рё РЅРµС‚ Р·РЅР°С‡РµРЅРёСЏ, РїСЂРѕРІРµСЂСЏРµРј С‚РѕР»СЊРєРѕ property_id
                                                $stillExists = true;
                                                break;
                                            }
                                        }
                                    }

                                    // Р•СЃР»Рё СЃРІРѕР№СЃС‚РІРѕ РЅРµ РЅР°Р№РґРµРЅРѕ РІ РѕС‚С„РёР»СЊС‚СЂРѕРІР°РЅРЅРѕРј РјР°СЃСЃРёРІРµ, СѓРґР°Р»СЏРµРј РµРіРѕ РёР· Р±Р°Р·С‹
                                    if (! $stillExists) {
                                        $deleteQuery = DB::table('shop_good_properties')
                                            ->where('good_id', $good->id)
                                            ->where('property_id', $removePropertyId);

                                        if ($hasVariationIdCol) {
                                            $deleteQuery->whereNull('variation_id');
                                        }

                                        if ($hasShopValueIdCol && $removeShopPropertyValueId !== null) {
                                            $deleteQuery->where('shop_property_value_id', $removeShopPropertyValueId);
                                        } elseif ($hasValueCol && $removeValue !== null) {
                                            $deleteQuery->where('value', $removeValue);
                                        }

                                        $deleteQuery->delete();
                                    }
                                }
                            }
                        }

                        // Р”РѕР±Р°РІР»СЏРµРј РЅРѕРІС‹Рµ СЃРІРѕР№СЃС‚РІР° (РµСЃР»Рё РЅРµ СѓСЃС‚Р°РЅРѕРІР»РµРЅ С„Р»Р°Рі РѕС‡РёСЃС‚РєРё РІСЃРµС…)
                        if (! isset($data['clear_all']) || ! $data['clear_all']) {
                            if (isset($data['properties']) && is_array($data['properties'])) {
                                $incoming = $data['properties'];

                                // Р”РѕР±Р°РІР»СЏРµРј С‚РѕР»СЊРєРѕ РЅРѕРІС‹Рµ СЃРІРѕР№СЃС‚РІР°, РєРѕС‚РѕСЂС‹Рµ РµС‰Рµ РЅРµ СЃСѓС‰РµСЃС‚РІСѓСЋС‚ Сѓ С‚РѕРІР°СЂР°
                                foreach ($incoming as $property) {
                                    if (empty($property['property_id'])) {
                                        continue;
                                    }

                                    $propertyId = (int) $property['property_id'];
                                    $newShopPropertyValueId = isset($property['shop_property_value_id']) ? (int) $property['shop_property_value_id'] : null;
                                    $newValue = $property['value'] ?? null;

                                    // РџСЂРѕРІРµСЂСЏРµРј, РЅРµС‚ Р»Рё СѓР¶Рµ С‚Р°РєРѕРіРѕ СЃРІРѕР№СЃС‚РІР° СЃ С‚Р°РєРёРј Р·РЅР°С‡РµРЅРёРµРј
                                    $exists = false;
                                    foreach ($currentProperties as $existing) {
                                        if ($existing['property_id'] == $propertyId) {
                                            // Р•СЃР»Рё РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ shop_property_value_id
                                            if ($hasShopValueIdCol) {
                                                $existingShopPropertyValueId = isset($existing['shop_property_value_id']) ? (int) $existing['shop_property_value_id'] : null;
                                                if ($newShopPropertyValueId !== null && $existingShopPropertyValueId == $newShopPropertyValueId) {
                                                    $exists = true;
                                                    break;
                                                }
                                            }
                                            // Р•СЃР»Рё РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ value
                                            if ($hasValueCol) {
                                                $existingValue = trim($existing['value'] ?? '');
                                                if ($newValue !== null && $existingValue !== '' && $existingValue === trim($newValue)) {
                                                    $exists = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }

                                    // Р•СЃР»Рё СЃРІРѕР№СЃС‚РІРѕ СЃ С‚Р°РєРёРј Р·РЅР°С‡РµРЅРёРµРј СѓР¶Рµ РµСЃС‚СЊ, РїСЂРѕРїСѓСЃРєР°РµРј РґРѕР±Р°РІР»РµРЅРёРµ
                                    if (! $exists) {
                                        // Р”РѕР±Р°РІР»СЏРµРј СЃРІРѕР№СЃС‚РІРѕ РЅР°РїСЂСЏРјСѓСЋ РІ Р±Р°Р·Сѓ РґР°РЅРЅС‹С…
                                        $insertData = [
                                            'good_id' => $good->id,
                                            'property_id' => $propertyId,
                                        ];

                                        if ($hasShopValueIdCol && $newShopPropertyValueId !== null) {
                                            $insertData['shop_property_value_id'] = $newShopPropertyValueId;
                                        }

                                        if ($hasValueCol && $newValue !== null) {
                                            $insertData['value'] = $newValue;
                                        }

                                        if ($hasVariationIdCol) {
                                            $insertData['variation_id'] = null;
                                        }

                                        DB::table('shop_good_properties')->insert($insertData);

                                        // Р”РѕР±Р°РІР»СЏРµРј РІ С‚РµРєСѓС‰РёРµ СЃРІРѕР№СЃС‚РІР° РґР»СЏ Р±СѓРґСѓС‰РёС… РїСЂРѕРІРµСЂРѕРє
                                        $currentProperties[] = $property;
                                    }
                                }
                            }
                        }
                        break;
                    case 'update_stock':
                        if (isset($data['stock_action']) && isset($data['stock_value'])) {
                            $stockAction = $data['stock_action'];
                            $stockValue = (int) $data['stock_value'];
                            $currentStock = (int) $good->stock_quantity;

                            // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РѕРє РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                            if ($stockAction === 'set') {
                                $good->update(['stock_quantity' => $stockValue]);
                            } elseif ($stockAction === 'add') {
                                $good->update(['stock_quantity' => max(0, $currentStock + $stockValue)]);
                            } elseif ($stockAction === 'subtract') {
                                $good->update(['stock_quantity' => max(0, $currentStock - $stockValue)]);
                            }

                            // РўР°РєР¶Рµ РѕР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё РІСЃРµС… РІР°СЂРёР°С†РёР№ С‚РѕРІР°СЂР°
                            $variations = $good->variations()->get();
                            foreach ($variations as $variation) {
                                $variationCurrentStock = (int) $variation->stock_quantity;

                                if ($stockAction === 'set') {
                                    $variation->update(['stock_quantity' => $stockValue]);
                                } elseif ($stockAction === 'add') {
                                    $variation->update(['stock_quantity' => max(0, $variationCurrentStock + $stockValue)]);
                                } elseif ($stockAction === 'subtract') {
                                    $variation->update(['stock_quantity' => max(0, $variationCurrentStock - $stockValue)]);
                                }
                            }
                        }
                        break;
                    case 'update_remote_stock':
                        if (isset($data['remote_stock_quantity'])) {
                            $remoteStockValue = $data['remote_stock_quantity'];
                            $remoteStockValueFormatted = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string) $remoteStockValue;

                            // РћР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РѕРє Сѓ/СЃ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                            $good->update([
                                'remote_stock_quantity' => $remoteStockValueFormatted,
                            ]);

                            // РўР°РєР¶Рµ РѕР±РЅРѕРІР»СЏРµРј РѕСЃС‚Р°С‚РєРё Сѓ/СЃ РІСЃРµС… РІР°СЂРёР°С†РёР№ С‚РѕРІР°СЂР°
                            $good->variations()->update([
                                'remote_stock_quantity' => $remoteStockValueFormatted,
                            ]);
                        }
                        break;
                    case 'update_fast_remote_stock':
                        if (isset($data['fast_remote_stock_quantity'])) {
                            $fastRemoteStockValue = $data['fast_remote_stock_quantity'];
                            $fastRemoteStockValueFormatted = ($fastRemoteStockValue === '' || $fastRemoteStockValue === null) ? null : (string) $fastRemoteStockValue;

                            // РћР±РЅРѕРІР»СЏРµРј Р±С‹СЃС‚СЂС‹Р№ РѕСЃС‚Р°С‚РѕРє Сѓ/СЃ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                            $good->update([
                                'fast_remote_stock_quantity' => $fastRemoteStockValueFormatted,
                            ]);

                            // РўР°РєР¶Рµ РѕР±РЅРѕРІР»СЏРµРј Р±С‹СЃС‚СЂС‹Рµ РѕСЃС‚Р°С‚РєРё Сѓ/СЃ РІСЃРµС… РІР°СЂРёР°С†РёР№ С‚РѕРІР°СЂР°
                            $good->variations()->update([
                                'fast_remote_stock_quantity' => $fastRemoteStockValueFormatted,
                            ]);
                        }
                        break;
                    case 'update_price':
                        if (isset($data['price_action']) && isset($data['price_value'])) {
                            $priceAction = $data['price_action'];
                            $priceValue = (float) $data['price_value'];
                            $isPercent = isset($data['price_is_percent']) && $data['price_is_percent'];
                            $currentPrice = (float) $good->price;

                            if ($priceAction === 'set') {
                                $good->update(['price' => max(0, $priceValue)]);
                            } elseif ($priceAction === 'add') {
                                if ($isPercent) {
                                    $good->update(['price' => max(0, $currentPrice * (1 + $priceValue / 100))]);
                                } else {
                                    $good->update(['price' => max(0, $currentPrice + $priceValue)]);
                                }
                            } elseif ($priceAction === 'subtract') {
                                if ($isPercent) {
                                    $good->update(['price' => max(0, $currentPrice * (1 - $priceValue / 100))]);
                                } else {
                                    $good->update(['price' => max(0, $currentPrice - $priceValue)]);
                                }
                            }
                        }
                        break;
                    case 'update_sale_price':
                        if (isset($data['sale_price_action'])) {
                            $salePriceAction = $data['sale_price_action'];

                            // РћС‡РёСЃС‚РєР° Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅС‹
                            if ($salePriceAction === 'clear') {
                                $good->update(['sale_price' => null]);
                            } elseif (isset($data['sale_price_value'])) {
                                $salePriceValue = (float) $data['sale_price_value'];
                                $isPercent = isset($data['sale_price_is_percent']) && $data['sale_price_is_percent'];
                                $currentPrice = (float) $good->price;
                                $currentSalePrice = $good->sale_price ? (float) $good->sale_price : 0;

                                if ($salePriceAction === 'set') {
                                    $newSalePrice = max(0, $salePriceValue);
                                    // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚РѕР±С‹ Р°РєС†РёРѕРЅРЅР°СЏ С†РµРЅР° Р±С‹Р»Р° РјРµРЅСЊС€Рµ Р±Р°Р·РѕРІРѕР№
                                    if ($newSalePrice >= $currentPrice) {
                                        $newSalePrice = null;
                                    }
                                    $good->update(['sale_price' => $newSalePrice]);
                                } elseif ($salePriceAction === 'subtract') {
                                    if ($isPercent) {
                                        $newSalePrice = $currentPrice - ($currentPrice * $salePriceValue / 100);
                                    } else {
                                        $newSalePrice = $currentPrice - $salePriceValue;
                                    }
                                    $newSalePrice = max(0, $newSalePrice);
                                    // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚РѕР±С‹ Р°РєС†РёРѕРЅРЅР°СЏ С†РµРЅР° Р±С‹Р»Р° РјРµРЅСЊС€Рµ Р±Р°Р·РѕРІРѕР№
                                    if ($newSalePrice >= $currentPrice) {
                                        $newSalePrice = null;
                                    }
                                    $good->update(['sale_price' => $newSalePrice]);
                                } elseif ($salePriceAction === 'subtract_from_sale') {
                                    if ($currentSalePrice > 0) {
                                        if ($isPercent) {
                                            $newSalePrice = $currentSalePrice - ($currentSalePrice * $salePriceValue / 100);
                                        } else {
                                            $newSalePrice = $currentSalePrice - $salePriceValue;
                                        }
                                        $newSalePrice = max(0, $newSalePrice);
                                        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚РѕР±С‹ Р°РєС†РёРѕРЅРЅР°СЏ С†РµРЅР° Р±С‹Р»Р° РјРµРЅСЊС€Рµ Р±Р°Р·РѕРІРѕР№
                                        if ($newSalePrice >= $currentPrice) {
                                            $newSalePrice = null;
                                        }
                                        $good->update(['sale_price' => $newSalePrice]);
                                    }
                                } elseif ($salePriceAction === 'add_to_sale') {
                                    if ($currentSalePrice > 0) {
                                        if ($isPercent) {
                                            $newSalePrice = $currentSalePrice + ($currentSalePrice * $salePriceValue / 100);
                                        } else {
                                            $newSalePrice = $currentSalePrice + $salePriceValue;
                                        }
                                        $newSalePrice = max(0, $newSalePrice);
                                        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚РѕР±С‹ Р°РєС†РёРѕРЅРЅР°СЏ С†РµРЅР° Р±С‹Р»Р° РјРµРЅСЊС€Рµ Р±Р°Р·РѕРІРѕР№
                                        if ($newSalePrice >= $currentPrice) {
                                            $newSalePrice = null;
                                        }
                                        $good->update(['sale_price' => $newSalePrice]);
                                    }
                                }
                            }
                        }
                        break;
                    case 'update_demping_price':
                        if (isset($data['demping_price_action'])) {
                            $dempingPriceAction = $data['demping_price_action'];

                            // РћС‡РёСЃС‚РєР° РґРµРјРїРёРЅРіРѕРІРѕР№ С†РµРЅС‹
                            if ($dempingPriceAction === 'clear') {
                                $good->update(['demping_price' => null]);
                            } elseif (isset($data['demping_price_value'])) {
                                $dempingPriceValue = (float) $data['demping_price_value'];
                                $isPercent = isset($data['demping_price_is_percent']) && $data['demping_price_is_percent'];
                                $currentPrice = (float) $good->price;
                                $currentDempingPrice = $good->demping_price ? (float) $good->demping_price : 0;

                                if ($dempingPriceAction === 'set') {
                                    $newDempingPrice = max(0, $dempingPriceValue);
                                    $good->update(['demping_price' => $newDempingPrice]);
                                } elseif ($dempingPriceAction === 'subtract') {
                                    if ($isPercent) {
                                        $newDempingPrice = $currentPrice - ($currentPrice * $dempingPriceValue / 100);
                                    } else {
                                        $newDempingPrice = $currentPrice - $dempingPriceValue;
                                    }
                                    $newDempingPrice = max(0, $newDempingPrice);
                                    $good->update(['demping_price' => $newDempingPrice]);
                                } elseif ($dempingPriceAction === 'subtract_from_sale') {
                                    $currentSalePrice = $good->sale_price ? (float) $good->sale_price : null;
                                    if ($currentSalePrice !== null) {
                                        if ($isPercent) {
                                            $newDempingPrice = $currentSalePrice - ($currentSalePrice * $dempingPriceValue / 100);
                                        } else {
                                            $newDempingPrice = $currentSalePrice - $dempingPriceValue;
                                        }
                                        $newDempingPrice = max(0, $newDempingPrice);
                                        $good->update(['demping_price' => $newDempingPrice]);
                                    }
                                } elseif ($dempingPriceAction === 'add_to_sale') {
                                    $currentSalePrice = $good->sale_price ? (float) $good->sale_price : null;
                                    if ($currentSalePrice !== null) {
                                        if ($isPercent) {
                                            $newDempingPrice = $currentSalePrice + ($currentSalePrice * $dempingPriceValue / 100);
                                        } else {
                                            $newDempingPrice = $currentSalePrice + $dempingPriceValue;
                                        }
                                        $newDempingPrice = max(0, $newDempingPrice);
                                        $good->update(['demping_price' => $newDempingPrice]);
                                    }
                                } elseif ($dempingPriceAction === 'subtract_from_demping') {
                                    if ($currentDempingPrice > 0) {
                                        if ($isPercent) {
                                            $newDempingPrice = $currentDempingPrice - ($currentDempingPrice * $dempingPriceValue / 100);
                                        } else {
                                            $newDempingPrice = $currentDempingPrice - $dempingPriceValue;
                                        }
                                        $newDempingPrice = max(0, $newDempingPrice);
                                        $good->update(['demping_price' => $newDempingPrice]);
                                    }
                                }
                            }
                        }

                        // РћР±СЂР°Р±РѕС‚РєР° РїРѕР»СЏ show_demping
                        if (isset($data['show_demping'])) {
                            $good->update(['show_demping' => (bool) $data['show_demping']]);
                        }
                        break;
                    case 'toggle_show_demping':
                        if (isset($data['show_demping'])) {
                            $good->update(['show_demping' => (bool) $data['show_demping']]);
                        }
                        break;
                    case 'toggle_fields':
                        $updateData = [];
                        if (isset($data['show_demping'])) {
                            $updateData['show_demping'] = (bool) $data['show_demping'];
                        }
                        if (isset($data['is_sale'])) {
                            $updateData['is_sale'] = (bool) $data['is_sale'];
                        }
                        if (isset($data['is_new'])) {
                            $updateData['is_new'] = (bool) $data['is_new'];
                        }
                        if (isset($data['is_featured'])) {
                            $updateData['is_featured'] = (bool) $data['is_featured'];
                        }
                        if (isset($data['is_show'])) {
                            $updateData['is_show'] = (bool) $data['is_show'];
                        }
                        if (! empty($updateData)) {
                            $good->update($updateData);
                        }
                        break;
                    case 'update_label':
                        if (isset($data['label_id'])) {
                            $good->update(['label_id' => $data['label_id'] ?: null]);
                        }
                        break;
                    case 'remove_after_symbol':
                        if (isset($data['symbol']) && ! empty($data['symbol'])) {
                            $symbol = $data['symbol'];
                            $name = $good->name;

                            // РќР°С…РѕРґРёРј РїРµСЂРІРѕРµ РІС…РѕР¶РґРµРЅРёРµ СЃРёРјРІРѕР»Р°/СЃРѕС‡РµС‚Р°РЅРёСЏ
                            $position = mb_strpos($name, $symbol);

                            if ($position !== false) {
                                // РЈРґР°Р»СЏРµРј СЃРёРјРІРѕР» Рё РІСЃС‘ РїРѕСЃР»Рµ РЅРµРіРѕ
                                $newName = mb_substr($name, 0, $position);
                                $good->update(['name' => $newName]);
                            }
                        }
                        break;
                    case 'replace_text':
                        $search = $data['search'] ?? '';
                        $replace = $data['replace'] ?? '';
                        $normalizeSpaces = $data['normalize_spaces'] ?? false;
                        $field = $data['field'] ?? 'name';
                        $mode = $data['mode'] ?? 'exact';
                        $start = $data['start'] ?? '';
                        $end = $data['end'] ?? '';

                        if (! in_array($field, ['name', 'description', 'short_description'])) {
                            $field = 'name';
                        }

                        if ($field === 'name') {
                            $text = $good->name ?? '';
                            $newText = $text;
                            if ($mode === 'start_end' && $start !== '' && $end !== '') {
                                $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/si';
                                $newText = preg_replace($pattern, $replace, $text);
                            } else {
                                if ($search !== '' || $normalizeSpaces) {
                                    if ($normalizeSpaces) {
                                        $newText = preg_replace('/\s+/', ' ', trim($text));
                                    } else {
                                        $isSearchOnlySpaces = trim($search) === '';
                                        if ($isSearchOnlySpaces && $search !== '') {
                                            if ($replace === '') {
                                                $newText = str_replace($search, '', $text);
                                            } else {
                                                $replaceSpaces = str_repeat(' ', strlen($replace));
                                                $newText = str_replace($search, $replaceSpaces, $text);
                                            }
                                        } else {
                                            $newText = str_ireplace($search, $replace, $text);
                                        }
                                    }
                                }
                            }
                            if ($newText !== $text) {
                                $good->update(['name' => $newText]);
                            }
                        } else {
                            $text = $good->{$field} ?? '';
                            $newText = $text;
                            if ($mode === 'start_end' && $start !== '' && $end !== '') {
                                $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/si';
                                $newText = preg_replace($pattern, $replace, $text);
                            } elseif ($search !== '') {
                                $newText = str_ireplace($search, $replace, $text);
                            }
                            if ($newText !== $text) {
                                $good->update([$field => $newText]);
                            }
                        }
                        break;
                    case 'update_dimensions':
                        $updateData = [];
                        // РћР±РЅРѕРІР»СЏРµРј С‚РѕР»СЊРєРѕ Р·Р°РїРѕР»РЅРµРЅРЅС‹Рµ РїРѕР»СЏ (СЂР°Р·РјРµСЂС‹ РѕРєСЂСѓРіР»СЏРµРј РґРѕ С†РµР»С‹С…)
                        if (isset($data['width']) && $data['width'] !== null && $data['width'] !== '') {
                            $updateData['width'] = (int) round((float) $data['width']);
                        }
                        if (isset($data['height']) && $data['height'] !== null && $data['height'] !== '') {
                            $updateData['height'] = (int) round((float) $data['height']);
                        }
                        if (isset($data['depth']) && $data['depth'] !== null && $data['depth'] !== '') {
                            $updateData['depth'] = (int) round((float) $data['depth']);
                        }
                        if (isset($data['weight']) && $data['weight'] !== null && $data['weight'] !== '') {
                            $updateData['weight'] = (float) $data['weight']; // Р’РµСЃ РјРѕР¶РµС‚ Р±С‹С‚СЊ РґСЂРѕР±РЅС‹Рј
                        }
                        if (! empty($updateData)) {
                            $good->update($updateData);
                        }
                        break;
                    case 'delete_images':
                        if (isset($data['delete_type'])) {
                            $deleteType = $data['delete_type'];

                            if ($deleteType === 'goods') {
                                // РЈРґР°Р»СЏРµРј С‚РѕР»СЊРєРѕ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂРѕРІ (РЅРµ РІР°СЂРёР°С†РёР№)
                                ShopGoodImage::where('good_id', $good->id)
                                    ->whereNull('variation_id')
                                    ->delete();
                            } elseif ($deleteType === 'variations') {
                                // РЈРґР°Р»СЏРµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёР№ СЌС‚РѕРіРѕ С‚РѕРІР°СЂР°
                                $variationIds = $good->variations()->pluck('id')->toArray();
                                if (! empty($variationIds)) {
                                    ShopGoodImage::whereIn('variation_id', $variationIds)->delete();
                                }
                            } elseif ($deleteType === 'goods_and_variations') {
                                // РЈРґР°Р»СЏРµРј РІСЃРµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР° Рё РµРіРѕ РІР°СЂРёР°С†РёР№
                                ShopGoodImage::where('good_id', $good->id)->delete();

                                $variationIds = $good->variations()->pluck('id')->toArray();
                                if (! empty($variationIds)) {
                                    ShopGoodImage::whereIn('variation_id', $variationIds)->delete();
                                }
                            }
                        }
                        break;
                }

                // РђСѓРґРёС‚ РґР»СЏ РІСЃРµС… РґРµР№СЃС‚РІРёР№ РєСЂРѕРјРµ delete (РґР»СЏ delete СѓР¶Рµ СЃРѕР·РґР°РЅ РІС‹С€Рµ)
                $this->logAudit($good, 'bulk_'.$action, $oldValues, $good->fresh()->toArray());
            }

            DB::commit();

            // Р¤РѕСЂРјРёСЂСѓРµРј СЃРѕРѕР±С‰РµРЅРёРµ РІ Р·Р°РІРёСЃРёРјРѕСЃС‚Рё РѕС‚ РґРµР№СЃС‚РІРёСЏ
            $message = 'РњР°СЃСЃРѕРІРѕРµ РѕР±РЅРѕРІР»РµРЅРёРµ РІС‹РїРѕР»РЅРµРЅРѕ СѓСЃРїРµС€РЅРѕ';
            $responseData = ['success' => true, 'message' => $message];

            if ($action === 'delete_without_supplier') {
                $message = "РЈРґР°Р»РµРЅРѕ РІР°СЂРёР°С†РёР№ Р±РµР· РїРѕСЃС‚Р°РІС‰РёРєР°: {$deletedVariationsCount}";
                $responseData['message'] = $message;
                $responseData['deleted_count'] = 0; // РўРѕРІР°СЂС‹ РЅРµ СѓРґР°Р»СЏСЋС‚СЃСЏ
                $responseData['deleted_variations_count'] = $deletedVariationsCount;
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РјР°СЃСЃРѕРІРѕРіРѕ РѕР±РЅРѕРІР»РµРЅРёСЏ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџСЂРёРјРµРЅРёС‚СЊ С„РёР»СЊС‚СЂС‹ Рє Р·Р°РїСЂРѕСЃСѓ С‚РѕРІР°СЂРѕРІ
     */
    private function applyGoodsFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->get('search');
            // Р•СЃР»Рё РїРµСЂРµРґР°РЅ РїР°СЂР°РјРµС‚СЂ search_only_name_sku, РёС‰РµРј С‚РѕР»СЊРєРѕ РїРѕ РЅР°Р·РІР°РЅРёСЋ Рё Р°СЂС‚РёРєСѓР»Сѓ
            $searchOnlyNameSku = $request->input('search_only_name_sku');
            if ($searchOnlyNameSku && ($searchOnlyNameSku === '1' || $searchOnlyNameSku === 1 || $searchOnlyNameSku === true || $searchOnlyNameSku === 'true')) {
                $query->searchNameSku($search);
            } else {
                $query->search($search);
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р·РІР°РЅРёСЋ (С‚РѕС‡РЅРѕРµ РІС…РѕР¶РґРµРЅРёРµ С‚РµРєСЃС‚Р°)
        if ($request->filled('name_search')) {
            $nameSearch = $request->get('name_search');
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($nameSearch).'%']);
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ (С‚РѕС‡РЅРѕРµ РІС…РѕР¶РґРµРЅРёРµ С‚РµРєСЃС‚Р°)
        if ($request->filled('sku_search')) {
            $skuSearch = $request->get('sku_search');
            $query->whereRaw('LOWER(sku) LIKE ?', ['%'.mb_strtolower($skuSearch).'%']);
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РєР°С‚РµРіРѕСЂРёРё
        if ($request->filled('category_id')) {
            $query->byCategory($request->get('category_id'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рј РєР°С‚РµРіРѕСЂРёСЏРј
        if ($request->has('categories')) {
            $categoryIds = $request->input('categories');
            if (is_array($categoryIds) && ! empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('shop_categories.id', $categoryIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р±СЂРµРЅРґСѓ
        if ($request->filled('brand_id')) {
            $query->byBrand($request->get('brand_id'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рј Р±СЂРµРЅРґР°Рј
        if ($request->has('brands')) {
            $brandIds = $request->input('brands');
            if (is_array($brandIds) && ! empty($brandIds)) {
                $query->whereHas('brands', function ($q) use ($brandIds) {
                    $q->whereIn('shop_brands.id', $brandIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ С‚РµРіР°Рј
        if ($request->has('tags')) {
            $tagIds = $request->input('tags');
            if (is_array($tagIds) && ! empty($tagIds)) {
                $query->whereHas('tags', function ($q) use ($tagIds) {
                    $q->whereIn('shop_tags.id', $tagIds);
                });
            }
        }

        // РСЃРєР»СЋС‡РµРЅРёРµ С‚РµРіРѕРІ
        if ($request->has('exclude_tags')) {
            $excludeTagIds = $request->input('exclude_tags');
            if (is_array($excludeTagIds) && ! empty($excludeTagIds)) {
                $query->whereDoesntHave('tags', function ($q) use ($excludeTagIds) {
                    $q->whereIn('shop_tags.id', $excludeTagIds);
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р»РµР№Р±Р»Р°Рј
        if ($request->has('labels')) {
            $labelIds = $request->input('labels');
            if (is_array($labelIds) && ! empty($labelIds)) {
                $query->whereIn('label_id', $labelIds);
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЃРІРѕР№СЃС‚РІР°Рј
        if ($request->has('properties')) {
            $properties = $request->input('properties');
            if (is_array($properties)) {
                foreach ($properties as $propertyId => $valueIds) {
                    if (is_array($valueIds) && ! empty($valueIds)) {
                        $query->whereHas('properties', function ($q) use ($propertyId, $valueIds) {
                            $q->where('shop_properties.id', $propertyId)
                                ->whereIn('shop_property_value_id', $valueIds);
                        });
                    }
                }
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РґРµРјРїРёРЅРіСѓ
        if ($request->filled('has_demping')) {
            $hasDemping = $request->boolean('has_demping');
            if ($hasDemping) {
                $query->where('show_demping', true);
            } else {
                $query->where('show_demping', false);
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РґРµРјРїРёРЅРіРѕРІРѕР№ С†РµРЅРµ
        if ($request->filled('has_demping_price')) {
            $hasDempingPrice = $request->boolean('has_demping_price');
            if ($hasDempingPrice) {
                $query->whereNotNull('demping_price');
            } else {
                $query->whereNull('demping_price');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р°РєС†РёРѕРЅРЅРѕР№ С†РµРЅРµ
        if ($request->filled('has_sale_price')) {
            $hasSalePrice = $request->boolean('has_sale_price');
            if ($hasSalePrice) {
                $query->whereNotNull('sale_price');
            } else {
                $query->whereNull('sale_price');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р»РµР№Р±Р»Сѓ
        if ($request->filled('has_label')) {
            $hasLabel = $request->boolean('has_label');
            if ($hasLabel) {
                $query->whereNotNull('label_id');
            } else {
                $query->whereNull('label_id');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ С‚РµРіР°Рј
        if ($request->filled('has_tags')) {
            $hasTags = $request->boolean('has_tags');
            if ($hasTags) {
                $query->whereHas('tags');
            } else {
                $query->whereDoesntHave('tags');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РєРѕР»РёС‡РµСЃС‚РІСѓ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє
        if ($request->filled('properties_count_type')) {
            $countType = $request->get('properties_count_type');
            if ($countType === 'exact' && $request->filled('properties_count')) {
                $count = (int) $request->get('properties_count');
                $query->whereHas('properties', function ($q) use ($count) {
                    $q->havingRaw('COUNT(*) = ?', [$count]);
                }, '=', $count);
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р°СЂС‚РёРєСѓР»Сѓ
        if ($request->filled('sku_filter_type')) {
            $skuFilterType = $request->get('sku_filter_type');
            if ($skuFilterType === 'empty') {
                $query->where(function ($q) {
                    $q->whereNull('sku')
                        ->orWhere('sku', '=', '');
                });
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ С†РµРЅРµ
        if ($request->filled('min_price') || $request->filled('max_price')) {
            if ($request->filled('min_price')) {
                $query->where('price', '>=', $request->get('min_price'));
            }
            if ($request->filled('max_price')) {
                $query->where('price', '<=', $request->get('max_price'));
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РѕСЃС‚Р°С‚РєСѓ
        if ($request->filled('stock_quantity_min') || $request->filled('stock_quantity_max')) {
            if ($request->filled('stock_quantity_min')) {
                $query->where('stock_quantity', '>=', $request->get('stock_quantity_min'));
            }
            if ($request->filled('stock_quantity_max')) {
                $query->where('stock_quantity', '<=', $request->get('stock_quantity_max'));
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СѓРґР°Р»РµРЅРЅРѕРјСѓ РѕСЃС‚Р°С‚РєСѓ
        if ($request->filled('remote_stock_quantity')) {
            $query->where('remote_stock_quantity', $request->get('remote_stock_quantity'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р±С‹СЃС‚СЂРѕРјСѓ СѓРґР°Р»РµРЅРЅРѕРјСѓ РѕСЃС‚Р°С‚РєСѓ
        if ($request->filled('fast_remote_stock_quantity')) {
            $query->where('fast_remote_stock_quantity', $request->get('fast_remote_stock_quantity'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЃС‚Р°С‚СѓСЃСѓ Р°РєС‚РёРІРЅРѕСЃС‚Рё
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЃС‚Р°С‚СѓСЃСѓ "Р РµРєРѕРјРµРЅРґСѓРµРјС‹Р№"
        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЃС‚Р°С‚СѓСЃСѓ "РќРѕРІС‹Р№"
        if ($request->has('is_new')) {
            $query->where('is_new', $request->boolean('is_new'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЃС‚Р°С‚СѓСЃСѓ "РЎРѕ СЃРєРёРґРєРѕР№"
        if ($request->has('is_sale')) {
            $query->where('is_sale', $request->boolean('is_sale'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЃС‚Р°С‚СѓСЃСѓ "Р”РѕСЃС‚СѓРїРµРЅ Рє Р·Р°РєР°Р·Сѓ"
        if ($request->has('is_preorder')) {
            $query->where('is_preorder', $request->boolean('is_preorder'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ СЃС‚Р°С‚СѓСЃСѓ "РџРѕРєР°Р·С‹РІР°С‚СЊ"
        if ($request->has('is_show')) {
            $query->where('is_show', $request->boolean('is_show'));
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р»РёС‡РёСЋ РІР°СЂРёР°С†РёР№
        if ($request->filled('has_variations')) {
            $hasVariations = $request->boolean('has_variations');
            if ($hasVariations) {
                $query->has('variations');
            } else {
                $query->doesntHave('variations');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р»РёС‡РёСЋ РєР°С‚РµРіРѕСЂРёР№
        if ($request->filled('has_categories')) {
            $hasCategories = $request->boolean('has_categories');
            if ($hasCategories) {
                $query->has('categories');
            } else {
                $query->doesntHave('categories');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р»РёС‡РёСЋ Р±СЂРµРЅРґРѕРІ
        if ($request->filled('has_brands')) {
            $hasBrands = $request->boolean('has_brands');
            if ($hasBrands) {
                $query->has('brands');
            } else {
                $query->doesntHave('brands');
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ РЅР°Р»РёС‡РёСЋ РЅР° СЃРєР»Р°РґРµ
        if ($request->filled('in_stock')) {
            $inStock = $request->boolean('in_stock');
            if ($inStock) {
                $query->where('stock_quantity', '>', 0);
            } else {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        // Р¤РёР»СЊС‚СЂ РїРѕ Р°С‚СЂРёР±СѓС‚Р°Рј РІР°СЂРёР°С†РёР№ (С‚РѕРІР°СЂС‹ РЎ СЌС‚РёРјРё Р°С‚СЂРёР±СѓС‚Р°РјРё)
        if ($request->has('variation_attribute_names')) {
            $attributeNames = $request->input('variation_attribute_names');
            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Рє РјР°СЃСЃРёРІ, С‚Р°Рє Рё СЃС‚СЂРѕРєСѓ
            if (! is_array($attributeNames)) {
                $attributeNames = [$attributeNames];
            }
            if (! empty($attributeNames)) {
                // Р¤РёР»СЊС‚СЂСѓРµРј С‚РѕРІР°СЂС‹, Сѓ РєРѕС‚РѕСЂС‹С… РµСЃС‚СЊ РІР°СЂРёР°С†РёРё СЃ СѓРєР°Р·Р°РЅРЅС‹РјРё Р°С‚СЂРёР±СѓС‚Р°РјРё
                $query->whereExists(function ($subQuery) use ($attributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->join('shop_variation_attributes_values as vav', 'v.id', '=', 'vav.variation_id')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereRaw('v.good_id = shop_goods.id')
                        ->whereIn('a.name', $attributeNames)
                        ->limit(1);
                });
            }
        }

        // РСЃРєР»СЋС‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёР№ (С‚РѕРІР°СЂС‹ Р‘Р•Р— СЌС‚РёС… Р°С‚СЂРёР±СѓС‚РѕРІ)
        if ($request->has('exclude_variation_attribute_names')) {
            $excludeAttributeNames = $request->input('exclude_variation_attribute_names');
            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Рє РјР°СЃСЃРёРІ, С‚Р°Рє Рё СЃС‚СЂРѕРєСѓ
            if (! is_array($excludeAttributeNames)) {
                $excludeAttributeNames = [$excludeAttributeNames];
            }
            if (! empty($excludeAttributeNames)) {
                // РљРѕРіРґР° РїСЂРёРјРµРЅСЏРµС‚СЃСЏ С„РёР»СЊС‚СЂ "Р‘Р•Р— Р°С‚СЂРёР±СѓС‚РѕРІ", РїРѕРєР°Р·С‹РІР°РµРј РўРћР›Р¬РљРћ С‚РѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё,
                // РєРѕС‚РѕСЂС‹Рµ РќР• РёРјРµСЋС‚ СѓРєР°Р·Р°РЅРЅС‹Рµ Р°С‚СЂРёР±СѓС‚С‹
                // РСЃРїРѕР»СЊР·СѓРµРј РїРѕРґР·Р°РїСЂРѕСЃ РґР»СЏ С‚РѕС‡РЅРѕРіРѕ РєРѕРЅС‚СЂРѕР»СЏ
                $query->whereExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('good_id', 'shop_goods.id')
                        ->limit(1);
                })->whereNotExists(function ($subQuery) use ($excludeAttributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->join('shop_variation_attributes_values as vav', 'v.id', '=', 'vav.variation_id')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->whereIn('a.name', $excludeAttributeNames)
                        ->limit(1);
                });
            }
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РґР°РЅРЅС‹Рµ РґР»СЏ С„РёР»СЊС‚СЂРѕРІ
     */
    public function filters(): JsonResponse
    {
        $categories = ShopCategory::ordered()->with(['children' => function ($query) {
            $query->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->select('id', 'name', 'parent_id');
        }])->get(['id', 'name', 'parent_id']);
        $brands = ShopBrand::active()->ordered()->get(['id', 'name']);
        $tags = ShopTag::active()->ordered()->get(['id', 'name', 'color']);
        $labels = ShopLabel::ordered()->get(['id', 'name', 'color']);
        $properties = ShopProperty::active()->ordered()->get(['id', 'name', 'slug']);

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'brands' => $brands,
                'tags' => $tags,
                'labels' => $labels,
                'properties' => $properties,
            ],
        ]);
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІСѓСЋ РєР°С‚РµРіРѕСЂРёСЋ
     */
    public function createCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $category = ShopCategory::create([
                'name' => $request->get('name'),
                'is_active' => true,
                'sort_order' => ShopCategory::max('sort_order') + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'РљР°С‚РµРіРѕСЂРёСЏ СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅР°',
                'data' => $category,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ РєР°С‚РµРіРѕСЂРёРё: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІС‹Р№ Р±СЂРµРЅРґ
     */
    public function createBrand(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $brand = ShopBrand::create([
                'name' => $request->get('name'),
                'is_active' => true,
                'sort_order' => ShopBrand::max('sort_order') + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Р‘СЂРµРЅРґ СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ',
                'data' => $brand,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ Р±СЂРµРЅРґР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІС‹Р№ Р»РµР№Р±Р»
     */
    public function createLabel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $label = ShopLabel::create([
                'name' => $request->get('name'),
                'color' => $request->get('color', '#3B82F6'),
                'sort_order' => ShopLabel::max('sort_order') + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Р›РµР№Р±Р» СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ',
                'data' => $label,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ Р»РµР№Р±Р»Р°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЎРєР°С‡Р°С‚СЊ Рё СЃРѕС…СЂР°РЅРёС‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёРµ РїРѕ URL
     */
    public function downloadImage(Request $request): JsonResponse
    {
        \Log::error('BulkImport: ShopGoodsController API hit', ['params' => $request->all()]);
        $validator = Validator::make($request->all(), [
            'imageUrl' => 'required|url',
            'storagePath' => 'required|string',
            'optimize' => 'boolean',
            'naming' => 'string|in:original,hash',
            'resize' => 'string|in:no_change,crop_proportional,fit_with_white,fit_system,custom',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $imageUrl = $request->input('imageUrl');
            $storagePath = $request->input('storagePath', '/images/shop/goods'); // РСЃРїСЂР°РІР»СЏРµРј РїСѓС‚СЊ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');

            \Log::info('API: downloadImage request received', [
                'imageUrl' => $imageUrl,
                'naming' => $naming,
                'request_all' => $request->all()
            ]);

            // Р’Р°Р»РёРґР°С†РёСЏ URL
            if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµРІРµСЂРЅС‹Р№ С„РѕСЂРјР°С‚ URL',
                ], 400);
            }

            // РџСЂРѕРІРµСЂРєР° С„РѕСЂРјР°С‚Р° РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

            if (! in_array($extension, $imageExtensions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµРїРѕРґРґРµСЂР¶РёРІР°РµРјС‹Р№ С„РѕСЂРјР°С‚ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ',
                ], 400);
            }

            // Р“РµРЅРµСЂР°С†РёСЏ РёРјРµРЅРё С„Р°Р№Р»Р°
            if ($naming === 'original') {
                // РСЃРїРѕР»СЊР·СѓРµРј РѕСЂРёРіРёРЅР°Р»СЊРЅРѕРµ РёРјСЏ С„Р°Р№Р»Р°
                $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
                $fileName = $originalName.'.'.$extension;

                // РћС‡РёС‰Р°РµРј РёРјСЏ С„Р°Р№Р»Р° РѕС‚ РЅРµРґРѕРїСѓСЃС‚РёРјС‹С… СЃРёРјРІРѕР»РѕРІ
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            } else {
                // РСЃРїРѕР»СЊР·СѓРµРј С…РµС€
                $hash = hash('sha256', $imageUrl);
                $fileName = $hash.'.'.$extension;
            }

            // РџРѕР»РЅС‹Р№ РїСѓС‚СЊ РґР»СЏ СЃРѕС…СЂР°РЅРµРЅРёСЏ
            $fullPath = $storagePath.'/'.$fileName;
            // РџРѕР»СѓС‡Р°РµРј РїСѓС‚СЊ Рє С„СЂРѕРЅС‚РµРЅРґСѓ РёР· FRONTEND_PATH РІ .env
            $frontendPublicPath = frontend_public_path();
            $storageFullPath = $frontendPublicPath.'/'.ltrim($fullPath, '/');

            // РџСЂРѕРІРµСЂСЏРµРј, СЃСѓС‰РµСЃС‚РІСѓРµС‚ Р»Рё С„Р°Р№Р» СѓР¶Рµ
            if (file_exists($storageFullPath)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'path' => $fullPath,
                        'originalUrl' => $imageUrl,
                        'skipped' => true,
                    ],
                ]);
            }

            // РЎРѕР·РґР°РµРј РґРёСЂРµРєС‚РѕСЂРёСЋ РµСЃР»Рё РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
            $directory = dirname($storageFullPath);
            if (! \App\Helpers\StorageHelper::createDirectory($directory)) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРѕР·РґР°С‚СЊ РґРёСЂРµРєС‚РѕСЂРёСЋ РґР»СЏ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ',
                ], 500);
            }

            // РЎРєР°С‡РёРІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёРµ СЃ РїРѕРјРѕС‰СЊСЋ cURL РґР»СЏ РѕР±С…РѕРґР° SSL РїСЂРѕР±Р»РµРј

            $downloadResult = $this->downloadImageWithCurl($imageUrl);

            if (! $downloadResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРєР°С‡Р°С‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёРµ: '.($downloadResult['error'] ?: "HTTP {$downloadResult['http_code']}"),
                ], 400);
            }

            $imageData = $downloadResult['data'];

            // РџСЂРѕРІРµСЂРєР° СЂР°Р·РјРµСЂР° С„Р°Р№Р»Р° (РјР°РєСЃРёРјСѓРј 30MB)
            if (strlen($imageData) > 30 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Р¤Р°Р№Р» СЃР»РёС€РєРѕРј Р±РѕР»СЊС€РѕР№ (РјР°РєСЃРёРјСѓРј 30MB)',
                ], 400);
            }

            // РЎРѕС…СЂР°РЅСЏРµРј С„Р°Р№Р»
            $saveResult = file_put_contents($storageFullPath, $imageData);
            if ($saveResult === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРѕС…СЂР°РЅРёС‚СЊ С„Р°Р№Р»',
                ], 500);
            }

            // РћР±СЂР°Р±РѕС‚РєР° РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
            if ($optimize || $resize !== 'no_change') {
                $this->processImage($storageFullPath, $resize, $width, $height);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'path' => $fullPath,
                    'originalUrl' => $imageUrl,
                    'size' => strlen($imageData),
                    'optimized' => $optimize,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРєР°С‡РёРІР°РЅРёСЏ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџР°РєРµС‚РЅР°СЏ Р·Р°РіСЂСѓР·РєР° РёР·РѕР±СЂР°Р¶РµРЅРёР№
     */
    public function downloadImagesBatch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'imageUrls' => 'required|array|min:1|max:500', // РњР°РєСЃРёРјСѓРј 500 РёР·РѕР±СЂР°Р¶РµРЅРёР№ Р·Р° СЂР°Р·
            'imageUrls.*' => 'required|url',
            'storagePath' => 'required|string',
            'optimize' => 'boolean',
            'naming' => 'string|in:original,hash',
            'resize' => 'string|in:no_change,crop_proportional,fit_with_white,fit_system,custom',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
            'concurrency' => 'nullable|integer|min:1|max:20',
            'timeout' => 'nullable|integer|min:5|max:120',
            'connect_timeout' => 'nullable|integer|min:1|max:60',
            'queue_post_process' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $imageUrls = $request->input('imageUrls');
            // РћС‡РёС‰Р°РµРј РІСЃРµ URL РѕС‚ РЅРµРІР°Р»РёРґРЅС‹С… UTF-8 СЃРёРјРІРѕР»РѕРІ
            if (is_array($imageUrls)) {
                $imageUrls = array_map(function ($url) {
                    $url = mb_convert_encoding($url, 'UTF-8', 'UTF-8');

                    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $url);
                }, $imageUrls);
            }

            $storagePath = $request->input('storagePath', '/images/shop/goods'); // РСЃРїСЂР°РІР»СЏРµРј РїСѓС‚СЊ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
            $optimize = $request->input('optimize', true);
            $naming = $request->input('naming', 'hash');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');
            $concurrency = (int) ($request->input('concurrency', 8));
            if ($concurrency < 1) {
                $concurrency = 1;
            }
            if ($concurrency > 20) {
                $concurrency = 20;
            }
            $timeout = (int) ($request->input('timeout', 30));
            $connectTimeout = (int) ($request->input('connect_timeout', 10));

            $results = [];
            $errors = [];
            $skipped = [];

            // РџСЂРµРґРІР°СЂРёС‚РµР»СЊРЅР°СЏ РїРѕРґРіРѕС‚РѕРІРєР°: РІС‹С‡РёСЃР»СЏРµРј РёРјРµРЅР° С„Р°Р№Р»РѕРІ Рё РїСЂРѕРїСѓСЃРєР°РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ, Р·Р°С‚РµРј СЃРєР°С‡РёРІР°РµРј РїР°СЂР°Р»Р»РµР»СЊРЅРѕ
            $frontendPublicPath = frontend_public_path();
            $cacheKey = 'imgdl_cache:'.md5($storagePath);
            $queue = [];
            foreach ($imageUrls as $index => $imageUrlRaw) {
                $imageUrl = mb_convert_encoding($imageUrlRaw, 'UTF-8', 'UTF-8');
                $imageUrl = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $imageUrl);
                if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $errors[] = ['url' => $imageUrl, 'error' => 'РќРµРІРµСЂРЅС‹Р№ С„РѕСЂРјР°С‚ URL'];

                    continue;
                }

                $cachedRelativePath = null;
                try {
                    $cached = Redis::hGet($cacheKey, $imageUrl);
                    if (is_string($cached) && $cached !== '') {
                        $cachedRelativePath = $cached;
                        $cachedAbsolutePath = $frontendPublicPath.'/'.ltrim($cachedRelativePath, '/');
                        if (file_exists($cachedAbsolutePath)) {
                            $results[$imageUrl] = $cachedRelativePath;
                            $skipped[] = $imageUrl;

                            continue;
                        }
                    }
                } catch (\Throwable $e) {
                    // РРіРЅРѕСЂРёСЂСѓРµРј РѕС€РёР±РєРё Redis, РїСЂРѕРґРѕР»Р¶Р°РµРј РѕР±С‹С‡РЅС‹Р№ РїРѕС‚РѕРє
                }
                $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                if (! $extension) {
                    $extension = 'jpg';
                }
                if ($naming === 'original') {
                    $originalName = pathinfo($urlPath, PATHINFO_FILENAME);
                    $originalName = mb_convert_encoding($originalName, 'UTF-8', 'UTF-8');
                    $originalName = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $originalName);
                    $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName).'.'.$extension;
                } else {
                    $hash = hash('sha256', $imageUrl.$index);
                    $fileName = $hash.'.'.$extension;
                }
                $relativePath = rtrim($storagePath, '/').'/'.$fileName;
                $absolutePath = $frontendPublicPath.'/'.ltrim($relativePath, '/');
                $normalizedAbsolutePath = realpath($absolutePath) ?: $absolutePath;
                if (file_exists($normalizedAbsolutePath)) {
                    $results[$imageUrl] = $relativePath;
                    $skipped[] = $imageUrl;

                    continue;
                }
                $dir = dirname($normalizedAbsolutePath);
                if (! \App\Helpers\StorageHelper::createDirectory($dir)) {
                    $errors[] = ['url' => $imageUrl, 'error' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРѕР·РґР°С‚СЊ РґРёСЂРµРєС‚РѕСЂРёСЋ РґР»СЏ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ'];

                    continue;
                }
                $queue[] = [
                    'url' => $imageUrl,
                    'relativePath' => $relativePath,
                    'absolutePath' => $normalizedAbsolutePath,
                    'index' => $index,
                    'extension' => $extension,
                ];
            }

            // Р•СЃР»Рё РЅРµС‡РµРіРѕ СЃРєР°С‡РёРІР°С‚СЊ
            if (empty($queue)) {
                $cleanResults = [];
                foreach ($results as $url => $path) {
                    $cleanResults[mb_convert_encoding($url, 'UTF-8', 'UTF-8')] = mb_convert_encoding($path, 'UTF-8', 'UTF-8');
                }
                $cleanSkipped = array_map(fn ($u) => mb_convert_encoding($u, 'UTF-8', 'UTF-8'), $skipped);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'paths' => $cleanResults,
                        'skipped' => $cleanSkipped,
                        'errors' => $errors,
                        'total' => count($imageUrls),
                        'successful' => count($cleanResults),
                        'failed' => count($errors),
                        'skipped_count' => count($cleanSkipped),
                    ],
                ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // РџРµСЂРµРјРµС€РёРІР°РµРј РѕС‡РµСЂРµРґСЊ, С‡С‚РѕР±С‹ СЂР°СЃРїСЂРµРґРµР»РёС‚СЊ Р·Р°РїСЂРѕСЃС‹ РїРѕ СЂР°Р·Р»РёС‡РЅС‹Рј С…РѕСЃС‚Р°Рј Р±РѕР»РµРµ СЂР°РІРЅРѕРјРµСЂРЅРѕ
            if (count($queue) > 1) {
                shuffle($queue);
            }

            // РџР°СЂР°Р»Р»РµР»СЊРЅР°СЏ Р·Р°РіСЂСѓР·РєР° СЃ РїРѕРјРѕС‰СЊСЋ curl_multi
            $mh = curl_multi_init();
            // РћР±С‰РёР№ share-РѕР±СЉРµРєС‚ РґР»СЏ DNS/SSL/СЃРѕРµРґРёРЅРµРЅРёР№
            $sh = function_exists('curl_share_init') ? curl_share_init() : null;
            if ($sh && function_exists('curl_share_setopt')) {
                if (defined('CURLSHOPT_SHARE') && defined('CURL_LOCK_DATA_DNS')) {
                    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
                }
                if (defined('CURL_LOCK_DATA_SSL_SESSION')) {
                    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
                }
                if (defined('CURL_LOCK_DATA_CONNECT')) {
                    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
                }
            }
            $handles = [];
            $active = null;
            $startedAt = microtime(true);

            $createHandle = function ($item) use ($timeout, $connectTimeout, $sh) {
                $ch = curl_init();
                $normalizedUrl = $this->normalizeImageUrl($item['url']);
                curl_setopt($ch, CURLOPT_URL, $normalizedUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                if (defined('CURL_SSLVERSION_TLSv1_2')) {
                    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
                }
                curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
                if (defined('CURLSSLOPT_ALLOW_BEAST') && defined('CURLSSLOPT_NO_REVOKE')) {
                    curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_ALLOW_BEAST | CURLSSLOPT_NO_REVOKE);
                }
                if (defined('CURL_HTTP_VERSION_2TLS')) {
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
                } else {
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                }
                curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
                curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 10);
                curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 1);
                if (defined('CURLOPT_LOW_SPEED_LIMIT') && defined('CURLOPT_LOW_SPEED_TIME')) {
                    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
                    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 15);
                }
                if (defined('CURLOPT_NOSIGNAL')) {
                    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
                }
                if (defined('CURLOPT_DNS_CACHE_TIMEOUT')) {
                    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 300);
                }
                if (defined('CURL_IPRESOLVE_V4')) {
                    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: image/*,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.5',
                    'Accept-Encoding: gzip, deflate',
                    'Connection: keep-alive',
                    'Cache-Control: no-cache',
                ]);
                if ($sh && function_exists('curl_setopt')) {
                    if (defined('CURLOPT_SHARE')) {
                        curl_setopt($ch, CURLOPT_SHARE, $sh);
                    }
                }
                // РЎРѕС…СЂР°РЅСЏРµРј РјРµС‚Р°РґР°РЅРЅС‹Рµ РґР»СЏ РїРѕСЃР»РµРґСѓСЋС‰РµР№ РѕР±СЂР°Р±РѕС‚РєРё
                curl_setopt($ch, CURLOPT_PRIVATE, json_encode($item));

                return $ch;
            };

            $nextIndex = 0;
            $totalToDownload = count($queue);

            // РРЅРёС†РёР°Р»РёР·РёСЂСѓРµРј РїРµСЂРІС‹Рµ РїРѕС‚РѕРєРё
            for (; $nextIndex < $totalToDownload && count($handles) < $concurrency; $nextIndex++) {
                $item = $queue[$nextIndex];
                $ch = $createHandle($item);
                $handles[(int) $ch] = $ch;
                curl_multi_add_handle($mh, $ch);
            }

            if (function_exists('curl_multi_setopt')) {
                if (defined('CURLMOPT_MAXCONNECTS')) {
                    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, max($concurrency * 2, 10));
                }
                if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
                    // РћРіСЂР°РЅРёС‡РёРІР°РµРј СЃРѕРµРґРёРЅРµРЅРёСЏ РЅР° С…РѕСЃС‚, С‡С‚РѕР±С‹ РЅРµ В«РґСѓС€РёС‚СЊВ» РѕРґРёРЅ РёСЃС‚РѕС‡РЅРёРє
                    curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, min($concurrency, 6));
                }
                if (defined('CURLMOPT_PIPELINING')) {
                    // Р’РєР»СЋС‡Р°РµРј HTTP/2 РјСѓР»СЊС‚РёРїР»РµРєСЃРёСЂРѕРІР°РЅРёРµ, РµСЃР»Рё РїРѕРґРґРµСЂР¶РёРІР°РµС‚СЃСЏ
                    $pipemode = defined('CURLPIPE_MULTIPLEX') ? CURLPIPE_MULTIPLEX : 1;
                    curl_multi_setopt($mh, CURLMOPT_PIPELINING, $pipemode);
                }
            }

            do {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);

                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј Р·Р°РІРµСЂС€РµРЅРЅС‹Рµ
                while ($info = curl_multi_info_read($mh)) {
                    $ch = $info['handle'];
                    $content = curl_multi_getcontent($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    $private = curl_getinfo($ch, CURLINFO_PRIVATE);
                    $item = json_decode($private, true) ?: [];

                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($handles[(int) $ch]);

                    $originalUrl = mb_convert_encoding($item['url'] ?? '', 'UTF-8', 'UTF-8');

                    if ($content === false || $httpCode !== 200) {
                        $errors[] = [
                            'url' => $originalUrl,
                            'error' => $error ? mb_convert_encoding($error, 'UTF-8', 'UTF-8') : "HTTP {$httpCode}",
                        ];
                    } else {
                        if (strlen($content) > 30 * 1024 * 1024) {
                            $errors[] = [
                                'url' => $originalUrl,
                                'error' => 'Р¤Р°Р№Р» СЃР»РёС€РєРѕРј Р±РѕР»СЊС€РѕР№ (РјР°РєСЃРёРјСѓРј 30MB)',
                            ];
                        } else {
                            $mimeType = $this->getMimeTypeFromData($content);
                            $allowedMimeTypes = [
                                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml', 'image/tiff', 'image/x-icon',
                            ];
                            if (! in_array($mimeType, $allowedMimeTypes)) {
                                $errors[] = [
                                    'url' => $originalUrl,
                                    'error' => 'РЎРєР°С‡Р°РЅРЅС‹Р№ РєРѕРЅС‚РµРЅС‚ РЅРµ СЏРІР»СЏРµС‚СЃСЏ РёР·РѕР±СЂР°Р¶РµРЅРёРµРј (MIME: '.$mimeType.')',
                                ];
                            } else {
                                file_put_contents($item['absolutePath'], $content);
                                if ($optimize || $resize !== 'no_change') {
                                    if ($request->boolean('queue_post_process', false)) {
                                        PostProcessImage::dispatch($item['absolutePath'], $resize, $width, $height);
                                    } else {
                                        $this->processImage($item['absolutePath'], $resize, $width, $height);
                                    }
                                }
                                $results[$originalUrl] = mb_convert_encoding($item['relativePath'], 'UTF-8', 'UTF-8');
                                try {
                                    Redis::hSet($cacheKey, $originalUrl, $item['relativePath']);
                                } catch (\Throwable $e) {
                                    // РћС€РёР±РєРё Redis РЅРµ РєСЂРёС‚РёС‡РЅС‹ РґР»СЏ РѕСЃРЅРѕРІРЅРѕРіРѕ РїРѕС‚РѕРєР°
                                }
                            }
                        }
                    }

                    // Р”РѕР±Р°РІР»СЏРµРј СЃР»РµРґСѓСЋС‰РёР№ СЌР»РµРјРµРЅС‚ РІ РѕС‡РµСЂРµРґСЊ
                    if ($nextIndex < $totalToDownload) {
                        $nextItem = $queue[$nextIndex++];
                        $chNext = $createHandle($nextItem);
                        $handles[(int) $chNext] = $chNext;
                        curl_multi_add_handle($mh, $chNext);
                    }
                }

                if ($active) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($active || ! empty($handles));

            curl_multi_close($mh);
            if ($sh && function_exists('curl_share_close')) {
                curl_share_close($sh);
            }

            // РћС‡РёС‰Р°РµРј РІСЃРµ РґР°РЅРЅС‹Рµ РїРµСЂРµРґ JSON-РєРѕРґРёСЂРѕРІР°РЅРёРµРј РґР»СЏ РїСЂРµРґРѕС‚РІСЂР°С‰РµРЅРёСЏ РѕС€РёР±РѕРє UTF-8
            $cleanResults = [];
            foreach ($results as $url => $path) {
                $cleanUrl = mb_convert_encoding($url, 'UTF-8', 'UTF-8');
                $cleanPath = mb_convert_encoding($path, 'UTF-8', 'UTF-8');
                $cleanResults[$cleanUrl] = $cleanPath;
            }

            $cleanSkipped = array_map(function ($url) {
                return mb_convert_encoding($url, 'UTF-8', 'UTF-8');
            }, $skipped);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            return response()->json([
                'success' => true,
                'data' => [
                    'paths' => $cleanResults,
                    'skipped' => $cleanSkipped,
                    'errors' => $errors,
                    'total' => count($imageUrls),
                    'successful' => count($cleanResults),
                    'failed' => count($errors),
                    'skipped_count' => count($cleanSkipped),
                    'metrics' => [
                        'duration_ms' => $durationMs,
                        'concurrency_used' => $concurrency,
                        'timeout_s' => $timeout,
                        'connect_timeout_s' => $connectTimeout,
                        'to_download' => $totalToDownload,
                    ],
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (\Exception $e) {
            // РћС‡РёС‰Р°РµРј СЃРѕРѕР±С‰РµРЅРёРµ РѕР± РѕС€РёР±РєРµ РѕС‚ РЅРµРІР°Р»РёРґРЅС‹С… UTF-8 СЃРёРјРІРѕР»РѕРІ
            $errorMessage = $e->getMessage();
            $errorMessage = mb_convert_encoding($errorMessage, 'UTF-8', 'UTF-8');
            $errorMessage = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errorMessage); // РЈРґР°Р»СЏРµРј СѓРїСЂР°РІР»СЏСЋС‰РёРµ СЃРёРјРІРѕР»С‹

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїР°РєРµС‚РЅРѕР№ Р·Р°РіСЂСѓР·РєРё РёР·РѕР±СЂР°Р¶РµРЅРёР№: '.$errorMessage,
            ], 500);
        }
    }

    public function startImageDownloadsTask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'imageUrls' => 'required|array|min:1|max:5000',
            'imageUrls.*' => 'required|url',
            'storagePath' => 'required|string',
            'optimize' => 'boolean',
            'naming' => 'string|in:original,hash',
            'resize' => 'string|in:no_change,crop_proportional,fit_with_white,fit_system,custom',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
            'concurrency' => 'nullable|integer|min:1|max:20',
            'timeout' => 'nullable|integer|min:5|max:120',
            'connect_timeout' => 'nullable|integer|min:1|max:60',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }
        $taskId = (string) Str::uuid();
        $imageUrls = $request->input('imageUrls');
        $storagePath = $request->input('storagePath');
        $options = [
            'optimize' => $request->boolean('optimize', true),
            'naming' => $request->input('naming', 'hash'),
            'resize' => $request->input('resize', 'no_change'),
            'width' => $request->input('width'),
            'height' => $request->input('height'),
            'concurrency' => (int) $request->input('concurrency', 12),
            'timeout' => (int) $request->input('timeout', 60),
            'connect_timeout' => (int) $request->input('connect_timeout', 10),
        ];
        $metaKey = "imgdl:$taskId:meta";
        Redis::hMSet($metaKey, [
            'status' => 'pending',
            'total' => count($imageUrls),
            'queued' => count($imageUrls),
            'running' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'started_at' => time(),
            'updated_at' => time(),
        ]);
        Redis::expire($metaKey, 172800);
        ImageDownloadTaskJob::dispatch($taskId, $imageUrls, $storagePath, $options);

        return response()->json([
            'success' => true,
            'data' => ['task_id' => $taskId],
        ]);
    }

    public function imageDownloadsTaskStatus(string $taskId): JsonResponse
    {
        $metaKey = "imgdl:$taskId:meta";
        $errorsKey = "imgdl:$taskId:errors";
        $recentKey = "imgdl:$taskId:recent";
        $pathsKey = "imgdl:$taskId:paths";
        $meta = Redis::hGetAll($metaKey);
        if (! $meta) {
            return response()->json([
                'success' => false,
                'message' => 'task_not_found',
            ], 404);
        }
        $errors = Redis::lrange($errorsKey, -50, -1) ?: [];
        $recent = Redis::lrange($recentKey, -50, -1) ?: [];
        $progressPct = 0;
        $total = (int) ($meta['total'] ?? 0);
        $processed = (int) ($meta['success'] ?? 0) + (int) ($meta['failed'] ?? 0) + (int) ($meta['skipped'] ?? 0);
        if ($total > 0) {
            $progressPct = (int) round($processed * 100 / $total);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'meta' => $meta,
                'progress_pct' => $progressPct,
                'recent' => array_map(function ($v) {
                    return json_decode($v, true);
                }, $recent),
                'errors' => array_map(function ($v) {
                    return json_decode($v, true);
                }, $errors),
            ],
        ]);
    }

    public function imageDownloadsTaskResult(string $taskId): JsonResponse
    {
        $pathsKey = "imgdl:$taskId:paths";
        $metaKey = "imgdl:$taskId:meta";
        $exists = Redis::hGetAll($metaKey);
        if (! $exists) {
            return response()->json(['success' => false, 'message' => 'task_not_found'], 404);
        }
        $paths = Redis::hGetAll($pathsKey) ?: [];

        return response()->json([
            'success' => true,
            'data' => [
                'paths' => $paths,
            ],
        ]);
    }

    /**
     * РЎРѕС…СЂР°РЅРµРЅРёРµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РЅР° С„СЂРѕРЅС‚РµРЅРґ
     */
    public function saveImageToFrontend(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|file|image|max:30720', // РњР°РєСЃРёРјСѓРј 30MB
            'path' => 'required|string',
            'resize' => 'string|in:no_change,crop_proportional,fit_with_white,fit_system,custom',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $image = $request->file('image');
            $path = $request->input('path');
            $resize = $request->input('resize', 'no_change');
            $width = $request->input('width');
            $height = $request->input('height');

            // РџСѓС‚СЊ РґР»СЏ СЃРѕС…СЂР°РЅРµРЅРёСЏ РЅР° С„СЂРѕРЅС‚РµРЅРґ (РёР· FRONTEND_PATH РІ .env)
            $frontendPublicPath = frontend_public_path();
            $fullPath = $frontendPublicPath.'/'.ltrim($path, '/');
            $dir = dirname($fullPath);

            // РЎРѕР·РґР°РµРј РґРёСЂРµРєС‚РѕСЂРёСЋ РµСЃР»Рё РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // РЎРѕС…СЂР°РЅСЏРµРј С„Р°Р№Р»
            $image->move($dir, basename($path));

            // РћР±СЂР°Р±РѕС‚РєР° РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РµСЃР»Рё РЅСѓР¶РЅРѕ
            if ($resize !== 'no_change' && $width && $height) {
                $this->resizeImageFile($fullPath, $width, $height, $resize);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'path' => $path,
                    'size' => filesize($fullPath),
                ],
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРѕС…СЂР°РЅРµРЅРёСЏ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Р—Р°РіСЂСѓР·РєР° РѕРґРЅРѕРіРѕ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ (РІСЃРїРѕРјРѕРіР°С‚РµР»СЊРЅС‹Р№ РјРµС‚РѕРґ РґР»СЏ РїР°РєРµС‚РЅРѕР№ Р·Р°РіСЂСѓР·РєРё)
     */
    private function downloadSingleImage($imageUrl, $storagePath, $optimize, $naming, $resize, $width, $height, $index)
    {
        try {
            // РћС‡РёС‰Р°РµРј URL РѕС‚ РЅРµРІР°Р»РёРґРЅС‹С… UTF-8 СЃРёРјРІРѕР»РѕРІ РїРµСЂРµРґ РѕР±СЂР°Р±РѕС‚РєРѕР№
            $imageUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');
            $imageUrl = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $imageUrl); // РЈРґР°Р»СЏРµРј СѓРїСЂР°РІР»СЏСЋС‰РёРµ СЃРёРјРІРѕР»С‹

            // Р’Р°Р»РёРґР°С†РёСЏ URL
            if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'РќРµРІРµСЂРЅС‹Р№ С„РѕСЂРјР°С‚ URL',
                ];
            }

            // РџСЂРѕРІРµСЂРєР° С„РѕСЂРјР°С‚Р° РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

            if (! in_array($extension, $imageExtensions)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'РќРµРїРѕРґРґРµСЂР¶РёРІР°РµРјС‹Р№ С„РѕСЂРјР°С‚ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ',
                ];
            }

            // Р“РµРЅРµСЂР°С†РёСЏ РёРјРµРЅРё С„Р°Р№Р»Р°
            if ($naming === 'original') {
                $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                $originalName = pathinfo($urlPath, PATHINFO_FILENAME);
                // РћС‡РёС‰Р°РµРј РѕС‚ РЅРµРІР°Р»РёРґРЅС‹С… UTF-8 СЃРёРјРІРѕР»РѕРІ
                $originalName = mb_convert_encoding($originalName, 'UTF-8', 'UTF-8');
                $originalName = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $originalName);
                $fileName = $originalName.'.'.$extension;
                // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РѕС‡РёСЃС‚РєР° РґР»СЏ Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            } else {
                $hash = hash('sha256', $imageUrl.$index); // Р”РѕР±Р°РІР»СЏРµРј РёРЅРґРµРєСЃ РґР»СЏ СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚Рё
                $fileName = $hash.'.'.$extension;
            }

            // РџРѕР»РЅС‹Р№ РїСѓС‚СЊ РґР»СЏ СЃРѕС…СЂР°РЅРµРЅРёСЏ РЅР° С„СЂРѕРЅС‚РµРЅРґ
            $fullPath = $storagePath.'/'.$fileName;
            // РџРѕР»СѓС‡Р°РµРј РїСѓС‚СЊ Рє С„СЂРѕРЅС‚РµРЅРґСѓ РёР· FRONTEND_PATH РІ .env
            $frontendPublicPath = frontend_public_path();
            $storageFullPath = $frontendPublicPath.'/'.ltrim($fullPath, '/');
            $normalizedStorageFullPath = realpath($storageFullPath) ?: $storageFullPath;

            // РџСЂРѕРІРµСЂСЏРµРј, СЃСѓС‰РµСЃС‚РІСѓРµС‚ Р»Рё С„Р°Р№Р» СѓР¶Рµ (РґРѕ СЃРєР°С‡РёРІР°РЅРёСЏ, С‡С‚РѕР±С‹ РЅРµ С‚СЂР°С‚РёС‚СЊ РІСЂРµРјСЏ)
            if (file_exists($normalizedStorageFullPath)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');
                $cleanPath = mb_convert_encoding($fullPath, 'UTF-8', 'UTF-8');

                return [
                    'success' => true,
                    'originalUrl' => $cleanUrl,
                    'path' => $cleanPath,
                    'skipped' => true, // Р¤Р»Р°Рі, С‡С‚Рѕ С„Р°Р№Р» Р±С‹Р» РїСЂРѕРїСѓС‰РµРЅ (СѓР¶Рµ СЃСѓС‰РµСЃС‚РІРѕРІР°Р»)
                ];
            }

            // РЎРѕР·РґР°РµРј РґРёСЂРµРєС‚РѕСЂРёСЋ РµСЃР»Рё РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
            $directory = dirname($normalizedStorageFullPath);
            if (! \App\Helpers\StorageHelper::createDirectory($directory)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРѕР·РґР°С‚СЊ РґРёСЂРµРєС‚РѕСЂРёСЋ РґР»СЏ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ',
                ];
            }

            // РќРѕСЂРјР°Р»РёР·СѓРµРј URL РїРµСЂРµРґ СЃРєР°С‡РёРІР°РЅРёРµРј
            $normalizedImageUrl = $this->normalizeImageUrl($imageUrl);

            // РЎРєР°С‡РёРІР°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёРµ СЃ РїРѕРјРѕС‰СЊСЋ cURL РґР»СЏ РѕР±С…РѕРґР° SSL РїСЂРѕР±Р»РµРј
            $downloadResult = $this->downloadImageWithCurl($normalizedImageUrl);

            if (! $downloadResult['success']) {
                // РћС‡РёС‰Р°РµРј СЃРѕРѕР±С‰РµРЅРёРµ РѕР± РѕС€РёР±РєРµ РѕС‚ РЅРµРІР°Р»РёРґРЅС‹С… UTF-8 СЃРёРјРІРѕР»РѕРІ
                $errorMsg = $downloadResult['error'] ?: "HTTP {$downloadResult['http_code']}";
                $errorMsg = mb_convert_encoding($errorMsg, 'UTF-8', 'UTF-8');
                $errorMsg = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errorMsg);

                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРєР°С‡Р°С‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёРµ: '.$errorMsg,
                ];
            }

            $imageData = $downloadResult['data'];

            // РџСЂРѕРІРµСЂРєР° СЂР°Р·РјРµСЂР° С„Р°Р№Р»Р° (РјР°РєСЃРёРјСѓРј 30MB)
            if (strlen($imageData) > 30 * 1024 * 1024) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'Р¤Р°Р№Р» СЃР»РёС€РєРѕРј Р±РѕР»СЊС€РѕР№ (РјР°РєСЃРёРјСѓРј 30MB)',
                ];
            }

            // РџСЂРѕРІРµСЂРєР° MIME С‚РёРїР° - СѓР±РµР¶РґР°РµРјСЃСЏ, С‡С‚Рѕ СЌС‚Рѕ РґРµР№СЃС‚РІРёС‚РµР»СЊРЅРѕ РёР·РѕР±СЂР°Р¶РµРЅРёРµ
            $mimeType = $this->getMimeTypeFromData($imageData);
            $allowedMimeTypes = [
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/bmp',
                'image/svg+xml',
                'image/tiff',
                'image/x-icon',
            ];

            if (! in_array($mimeType, $allowedMimeTypes)) {
                $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

                return [
                    'success' => false,
                    'originalUrl' => $cleanUrl,
                    'error' => 'РЎРєР°С‡Р°РЅРЅС‹Р№ РєРѕРЅС‚РµРЅС‚ РЅРµ СЏРІР»СЏРµС‚СЃСЏ РёР·РѕР±СЂР°Р¶РµРЅРёРµРј (MIME: '.$mimeType.')',
                ];
            }

            // РЎРѕС…СЂР°РЅСЏРµРј С„Р°Р№Р» (РїСЂРѕРІРµСЂРєР° РЅР° СЃСѓС‰РµСЃС‚РІРѕРІР°РЅРёРµ СѓР¶Рµ Р±С‹Р»Р° РІС‹С€Рµ)
            file_put_contents($normalizedStorageFullPath, $imageData);

            // РћР±СЂР°Р±РѕС‚РєР° РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
            if ($optimize || $resize !== 'no_change') {
                $this->processImage($normalizedStorageFullPath, $resize, $width, $height);
            }

            // РћС‡РёС‰Р°РµРј URL Рё РїСѓС‚СЊ РѕС‚ РЅРµРІР°Р»РёРґРЅС‹С… UTF-8 СЃРёРјРІРѕР»РѕРІ РїРµСЂРµРґ РІРѕР·РІСЂР°С‚РѕРј
            $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');
            $cleanPath = mb_convert_encoding($fullPath, 'UTF-8', 'UTF-8');

            return [
                'success' => true,
                'originalUrl' => $cleanUrl,
                'path' => $cleanPath,
                'skipped' => false, // Р¤Р°Р№Р» Р±С‹Р» Р·Р°РіСЂСѓР¶РµРЅ (РЅРµ СЃСѓС‰РµСЃС‚РІРѕРІР°Р» СЂР°РЅРµРµ)
            ];

        } catch (\Exception $e) {
            // РћС‡РёС‰Р°РµРј СЃРѕРѕР±С‰РµРЅРёРµ РѕР± РѕС€РёР±РєРµ Рё URL РѕС‚ РЅРµРІР°Р»РёРґРЅС‹С… UTF-8 СЃРёРјРІРѕР»РѕРІ
            $errorMessage = $e->getMessage();
            $errorMessage = mb_convert_encoding($errorMessage, 'UTF-8', 'UTF-8');
            $errorMessage = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errorMessage); // РЈРґР°Р»СЏРµРј СѓРїСЂР°РІР»СЏСЋС‰РёРµ СЃРёРјРІРѕР»С‹

            $cleanUrl = mb_convert_encoding($imageUrl, 'UTF-8', 'UTF-8');

            \Log::error("downloadSingleImage error: {$cleanUrl} - {$errorMessage}");

            return [
                'success' => false,
                'originalUrl' => $cleanUrl,
                'error' => 'РћС€РёР±РєР°: '.$errorMessage,
            ];
        }
    }

    /**
     * РћРїС‚РёРјРёР·Р°С†РёСЏ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
     */
    private function optimizeImage($filePath)
    {
        try {
            $imageInfo = getimagesize($filePath);
            if (! $imageInfo) {
                return;
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $mimeType = $imageInfo['mime'];

            // Р•СЃР»Рё РёР·РѕР±СЂР°Р¶РµРЅРёРµ СЃР»РёС€РєРѕРј Р±РѕР»СЊС€РѕРµ, СѓРјРµРЅСЊС€Р°РµРј РµРіРѕ
            if ($width > 2000 || $height > 2000) {
                $newWidth = $width > $height ? 2000 : intval(2000 * $width / $height);
                $newHeight = $height > $width ? 2000 : intval(2000 * $height / $width);

                // РЎРѕР·РґР°РµРј РЅРѕРІРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ
                $sourceImage = null;
                switch ($mimeType) {
                    case 'image/jpeg':
                        $sourceImage = imagecreatefromjpeg($filePath);
                        break;
                    case 'image/png':
                        $sourceImage = imagecreatefrompng($filePath);
                        break;
                    case 'image/gif':
                        $sourceImage = imagecreatefromgif($filePath);
                        break;
                    case 'image/webp':
                        $sourceImage = imagecreatefromwebp($filePath);
                        break;
                }

                if ($sourceImage) {
                    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

                    // РЎРѕС…СЂР°РЅСЏРµРј РїСЂРѕР·СЂР°С‡РЅРѕСЃС‚СЊ РґР»СЏ PNG
                    if ($mimeType === 'image/png') {
                        imagealphablending($resizedImage, false);
                        imagesavealpha($resizedImage, true);
                        $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                        imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
                    }

                    imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

                    // РЎРѕС…СЂР°РЅСЏРµРј РѕРїС‚РёРјРёР·РёСЂРѕРІР°РЅРЅРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ
                    switch ($mimeType) {
                        case 'image/jpeg':
                            imagejpeg($resizedImage, $filePath, 85); // 85% РєР°С‡РµСЃС‚РІРѕ
                            break;
                        case 'image/png':
                            imagepng($resizedImage, $filePath, 8); // 8 СѓСЂРѕРІРµРЅСЊ СЃР¶Р°С‚РёСЏ
                            break;
                        case 'image/gif':
                            imagegif($resizedImage, $filePath);
                            break;
                        case 'image/webp':
                            imagewebp($resizedImage, $filePath, 85); // 85% РєР°С‡РµСЃС‚РІРѕ
                            break;
                    }

                    imagedestroy($sourceImage);
                    imagedestroy($resizedImage);
                }
            }
        } catch (\Exception $e) {
            // РћС€РёР±РєР° РѕРїС‚РёРјРёР·Р°С†РёРё РЅРµ РєСЂРёС‚РёС‡РЅР°, РїСЂРѕРґРѕР»Р¶Р°РµРј РІС‹РїРѕР»РЅРµРЅРёРµ
        }
    }

    /**
     * РЎРєР°С‡РёРІР°РЅРёРµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ СЃ РїРѕРјРѕС‰СЊСЋ cURL (РѕР±С…РѕРґ SSL РїСЂРѕР±Р»РµРј)
     */
    private function downloadImageWithCurl($imageUrl)
    {
        // РќРѕСЂРјР°Р»РёР·СѓРµРј URL РїРµСЂРµРґ РёСЃРїРѕР»СЊР·РѕРІР°РЅРёРµРј РІ cURL
        $normalizedUrl = $this->normalizeImageUrl($imageUrl);

        // РЎРЅР°С‡Р°Р»Р° РїСЂРѕР±СѓРµРј cURL СЃ Р°РіСЂРµСЃСЃРёРІРЅС‹РјРё РЅР°СЃС‚СЂРѕР№РєР°РјРё SSL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $normalizedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

        // РђРіСЂРµСЃСЃРёРІРЅС‹Рµ РЅР°СЃС‚СЂРѕР№РєРё SSL РґР»СЏ РѕР±С…РѕРґР° РїСЂРѕР±Р»РµРј СЃ DH РєР»СЋС‡РѕРј
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_ALLOW_BEAST | CURLSSLOPT_NO_REVOKE);

        // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅС‹Рµ РЅР°СЃС‚СЂРѕР№РєРё РґР»СЏ РѕР±С…РѕРґР° РїСЂРѕР±Р»РµРј СЃ SSL
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 10);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 1);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Cache-Control: no-cache',
        ]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Р•СЃР»Рё cURL РЅРµ СЃСЂР°Р±РѕС‚Р°Р» РёР·-Р·Р° SSL РїСЂРѕР±Р»РµРј, РїСЂРѕР±СѓРµРј wget
        if ($imageData === false || $httpCode !== 200) {

            return $this->downloadImageWithWget($imageUrl);
        }

        return [
            'data' => $imageData,
            'http_code' => $httpCode,
            'error' => $error,
            'success' => $imageData !== false && $httpCode === 200,
        ];
    }

    /**
     * Fallback РјРµС‚РѕРґ РґР»СЏ СЃРєР°С‡РёРІР°РЅРёСЏ РёР·РѕР±СЂР°Р¶РµРЅРёР№ С‡РµСЂРµР· wget
     */
    private function downloadImageWithWget($imageUrl)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'image_download_');

        try {
            // РќРѕСЂРјР°Р»РёР·СѓРµРј URL РїРµСЂРµРґ РїРµСЂРµРґР°С‡РµР№ РІ wget
            // РСЃРїРѕР»СЊР·СѓРµРј С„СѓРЅРєС†РёСЋ normalizeImageUrl, РєРѕС‚РѕСЂР°СЏ РґРµР»Р°РµС‚ РјРЅРѕРіРѕРєСЂР°С‚РЅРѕРµ РґРµРєРѕРґРёСЂРѕРІР°РЅРёРµ
            $normalizedUrl = $this->normalizeImageUrl($imageUrl);

            // РСЃРїРѕР»СЊР·СѓРµРј wget СЃ РѕС‚РєР»СЋС‡РµРЅРЅРѕР№ РїСЂРѕРІРµСЂРєРѕР№ SSL
            $wgetCommand = "wget --no-check-certificate --timeout=30 --tries=1 -O '{$tempFile}' ".escapeshellarg($normalizedUrl).' 2>&1';
            $output = shell_exec($wgetCommand);

            if (file_exists($tempFile) && filesize($tempFile) > 0) {
                $imageData = file_get_contents($tempFile);
                unlink($tempFile);

                return [
                    'data' => $imageData,
                    'http_code' => 200,
                    'error' => null,
                    'success' => true,
                ];
            } else {
                unlink($tempFile);

                return [
                    'data' => false,
                    'http_code' => 0,
                    'error' => 'wget failed: '.$output,
                    'success' => false,
                ];
            }
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'data' => false,
                'http_code' => 0,
                'error' => 'wget exception: '.$e->getMessage(),
                'success' => false,
            ];
        }
    }

    /**
     * РџРѕР»СѓС‡РµРЅРёРµ MIME С‚РёРїР° РёР· Р±РёРЅР°СЂРЅС‹С… РґР°РЅРЅС‹С… РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
     */
    private function getMimeTypeFromData($data)
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $data);
        finfo_close($finfo);

        return $mimeType;
    }

    /**
     * РќРѕСЂРјР°Р»РёР·Р°С†РёСЏ URL РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РґР»СЏ РїСЂР°РІРёР»СЊРЅРѕР№ РѕР±СЂР°Р±РѕС‚РєРё Unicode СЃРёРјРІРѕР»РѕРІ
     */
    private function normalizeImageUrl($url)
    {
        if (empty($url)) {
            return $url;
        }

        try {
            // РџР°СЂСЃРёРј URL
            $parsed = parse_url($url);

            if (! $parsed || ! isset($parsed['scheme']) || ! isset($parsed['host'])) {
                return $url; // Р•СЃР»Рё РЅРµ СѓРґР°Р»РѕСЃСЊ СЂР°СЃРїР°СЂСЃРёС‚СЊ, РІРѕР·РІСЂР°С‰Р°РµРј РєР°Рє РµСЃС‚СЊ
            }

            // РњРЅРѕРіРѕРєСЂР°С‚РЅРѕРµ РґРµРєРѕРґРёСЂРѕРІР°РЅРёРµ РїСѓС‚Рё РґР»СЏ РёСЃРїСЂР°РІР»РµРЅРёСЏ РґРІРѕР№РЅРѕРіРѕ/С‚СЂРѕР№РЅРѕРіРѕ РєРѕРґРёСЂРѕРІР°РЅРёСЏ
            $path = isset($parsed['path']) ? $parsed['path'] : '';
            $previousPath = '';
            $decodeAttempts = 0;
            $maxDecodeAttempts = 5;

            while ($decodeAttempts < $maxDecodeAttempts && $path !== $previousPath) {
                $previousPath = $path;
                $path = urldecode($path);
                $decodeAttempts++;
            }

            // РќРѕСЂРјР°Р»РёР·СѓРµРј Unicode СЃРёРјРІРѕР»С‹ (NFD -> NFC)
            // Р­С‚Рѕ РёСЃРїСЂР°РІР»СЏРµС‚ РїСЂРѕР±Р»РµРјС‹ СЃ РєРѕРјР±РёРЅРёСЂСѓСЋС‰РёРјРё РґРёР°РєСЂРёС‚РёС‡РµСЃРєРёРјРё Р·РЅР°РєР°РјРё (РЅР°РїСЂРёРјРµСЂ, iМ†)
            if (class_exists('Normalizer') && method_exists('Normalizer', 'normalize')) {
                $path = \Normalizer::normalize($path, \Normalizer::FORM_C);
            } elseif (function_exists('normalizer_normalize')) {
                $path = normalizer_normalize($path, \Normalizer::FORM_C);
            }

            // РџСЂР°РІРёР»СЊРЅРѕ РєРѕРґРёСЂСѓРµРј РїСѓС‚СЊ Р·Р°РЅРѕРІРѕ
            $pathParts = explode('/', $path);
            $encodedParts = array_map(function ($part) {
                if (empty($part)) {
                    return $part;
                }

                // РљРѕРґРёСЂСѓРµРј РєР°Р¶РґС‹Р№ РєРѕРјРїРѕРЅРµРЅС‚ РїСѓС‚Рё РѕС‚РґРµР»СЊРЅРѕ
                return rawurlencode($part);
            }, $pathParts);

            $encodedPath = implode('/', $encodedParts);

            // Р’РѕСЃСЃС‚Р°РЅР°РІР»РёРІР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РїСѓС‚Рё (РѕРЅРѕ РґРѕР»Р¶РЅРѕ Р±С‹С‚СЊ Р·Р°РєРѕРґРёСЂРѕРІР°РЅРѕ РєР°Рє %3A)
            $encodedPath = str_replace(':', '%3A', $encodedPath);

            // РЎРѕР±РёСЂР°РµРј URL РѕР±СЂР°С‚РЅРѕ
            $normalized = $parsed['scheme'].'://'.$parsed['host'];
            if (isset($parsed['port'])) {
                $normalized .= ':'.$parsed['port'];
            }
            $normalized .= $encodedPath;
            if (isset($parsed['query'])) {
                $normalized .= '?'.$parsed['query'];
            }
            if (isset($parsed['fragment'])) {
                $normalized .= '#'.$parsed['fragment'];
            }

            return $normalized;
        } catch (\Exception $e) {
            // Р•СЃР»Рё РЅРѕСЂРјР°Р»РёР·Р°С†РёСЏ РЅРµ СѓРґР°Р»Р°СЃСЊ, РІРѕР·РІСЂР°С‰Р°РµРј РёСЃС…РѕРґРЅС‹Р№ URL
            return $url;
        }
    }

    /**
     * РћР±СЂР°Р±РѕС‚РєР° РёР·РѕР±СЂР°Р¶РµРЅРёСЏ СЃ СЂР°Р·Р»РёС‡РЅС‹РјРё С‚РёРїР°РјРё РёР·РјРµРЅРµРЅРёСЏ СЂР°Р·РјРµСЂР°
     */
    private function processImage($filePath, $resize, $width, $height)
    {
        try {
            $imageInfo = getimagesize($filePath);
            if (! $imageInfo) {
                return;
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];

            // Р•СЃР»Рё СЂР°Р·РјРµСЂС‹ РЅРµ Р·Р°РґР°РЅС‹, РёСЃРїРѕР»СЊР·СѓРµРј РѕСЂРёРіРёРЅР°Р»СЊРЅС‹Рµ
            if (! $width || ! $height) {
                $width = $originalWidth;
                $height = $originalHeight;
            }

            // Р•СЃР»Рё РЅРµ РЅСѓР¶РЅРѕ РёР·РјРµРЅСЏС‚СЊ СЂР°Р·РјРµСЂ
            if ($resize === 'no_change') {
                $this->optimizeImage($filePath);

                return;
            }

            // РЎРѕР·РґР°РµРј РёСЃС…РѕРґРЅРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ
            $sourceImage = null;
            switch ($mimeType) {
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($filePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($filePath);
                    break;
                case 'image/gif':
                    $sourceImage = imagecreatefromgif($filePath);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($filePath);
                    break;
            }

            if (! $sourceImage) {
                return;
            }

            $newImage = null;

            if ($resize === 'crop_proportional') {
                // РћР±СЂРµР·РєР° СЃ СЃРѕС…СЂР°РЅРµРЅРёРµРј РїСЂРѕРїРѕСЂС†РёР№ (РёСЃРїРѕР»СЊР·СѓРµС‚ СЃРёСЃС‚РµРјРЅС‹Рµ СЂР°Р·РјРµСЂС‹)
                $systemWidth = $width ?: $this->getSystemImageWidth();
                $systemHeight = $height ?: $this->getSystemImageHeight();
                $newImage = $this->cropProportional($sourceImage, $originalWidth, $originalHeight, $systemWidth, $systemHeight);
            } elseif ($resize === 'fit_with_white') {
                // РџРѕРґРіРѕРЅРєР° РїРѕРґ СЂР°Р·РјРµСЂС‹ СЃ Р±РµР»С‹Рј С„РѕРЅРѕРј (РёСЃРїРѕР»СЊР·СѓРµС‚ СЃРёСЃС‚РµРјРЅС‹Рµ СЂР°Р·РјРµСЂС‹)
                $systemWidth = $width ?: $this->getSystemImageWidth();
                $systemHeight = $height ?: $this->getSystemImageHeight();
                $newImage = $this->fitWithWhiteBackground($sourceImage, $originalWidth, $originalHeight, $systemWidth, $systemHeight);
            } elseif ($resize === 'fit_system') {
                // РџРѕРґРіРѕРЅРєР° РїРѕРґ СЂР°Р·РјРµСЂС‹ СЃРёСЃС‚РµРјС‹ (СѓРјРµРЅСЊС€РµРЅРёРµ РµСЃР»Рё РїСЂРµРІС‹С€Р°РµС‚ Р»РёРјРёС‚С‹)
                $systemWidth = $width ?: $this->getSystemImageWidth();
                $systemHeight = $height ?: $this->getSystemImageHeight();
                $newImage = $this->fitSystemSize($sourceImage, $originalWidth, $originalHeight, $systemWidth, $systemHeight);
            } elseif ($resize === 'custom') {
                // РџРѕР»СЊР·РѕРІР°С‚РµР»СЊСЃРєРёРµ СЂР°Р·РјРµСЂС‹ (РёСЃРїРѕР»СЊР·СѓРµС‚ РїРµСЂРµРґР°РЅРЅС‹Рµ СЂР°Р·РјРµСЂС‹ РёР»Рё СЃРёСЃС‚РµРјРЅС‹Рµ)
                $customWidth = $width ?: $this->getSystemImageWidth();
                $customHeight = $height ?: $this->getSystemImageHeight();
                $newImage = $this->cropProportional($sourceImage, $originalWidth, $originalHeight, $customWidth, $customHeight);
            }

            if ($newImage) {
                // РЎРѕС…СЂР°РЅСЏРµРј РѕР±СЂР°Р±РѕС‚Р°РЅРЅРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ
                switch ($mimeType) {
                    case 'image/jpeg':
                        imagejpeg($newImage, $filePath, 85);
                        break;
                    case 'image/png':
                        imagepng($newImage, $filePath, 8);
                        break;
                    case 'image/gif':
                        imagegif($newImage, $filePath);
                        break;
                    case 'image/webp':
                        imagewebp($newImage, $filePath, 85);
                        break;
                }

                imagedestroy($newImage);
            }

            imagedestroy($sourceImage);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('РћС€РёР±РєР° РѕР±СЂР°Р±РѕС‚РєРё РёР·РѕР±СЂР°Р¶РµРЅРёСЏ: '.$e->getMessage());
        }
    }

    /**
     * РћР±СЂРµР·РєР° СЃ СЃРѕС…СЂР°РЅРµРЅРёРµРј РїСЂРѕРїРѕСЂС†РёР№
     */
    private function cropProportional($sourceImage, $originalWidth, $originalHeight, $targetWidth, $targetHeight)
    {
        // Р’С‹С‡РёСЃР»СЏРµРј РєРѕСЌС„С„РёС†РёРµРЅС‚С‹ РјР°СЃС€С‚Р°Р±РёСЂРѕРІР°РЅРёСЏ
        $scaleX = $targetWidth / $originalWidth;
        $scaleY = $targetHeight / $originalHeight;
        $scale = max($scaleX, $scaleY); // Р‘РµСЂРµРј Р±РѕР»СЊС€РёР№ РєРѕСЌС„С„РёС†РёРµРЅС‚

        // Р’С‹С‡РёСЃР»СЏРµРј РЅРѕРІС‹Рµ СЂР°Р·РјРµСЂС‹
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);

        // РЎРѕР·РґР°РµРј РЅРѕРІРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ
        $newImage = imagecreatetruecolor($targetWidth, $targetHeight);

        // РЎРѕС…СЂР°РЅСЏРµРј РїСЂРѕР·СЂР°С‡РЅРѕСЃС‚СЊ РґР»СЏ PNG
        if (imageistruecolor($sourceImage)) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        // Р’С‹С‡РёСЃР»СЏРµРј РєРѕРѕСЂРґРёРЅР°С‚С‹ РґР»СЏ РѕР±СЂРµР·РєРё (С†РµРЅС‚СЂРёСЂСѓРµРј)
        $cropX = intval(($newWidth - $targetWidth) / 2);
        $cropY = intval(($newHeight - $targetHeight) / 2);

        // РЎРЅР°С‡Р°Р»Р° РјР°СЃС€С‚Р°Р±РёСЂСѓРµРј
        $scaledImage = imagecreatetruecolor($newWidth, $newHeight);
        if (imageistruecolor($sourceImage)) {
            imagealphablending($scaledImage, false);
            imagesavealpha($scaledImage, true);
        }
        imagecopyresampled($scaledImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        // Р—Р°С‚РµРј РѕР±СЂРµР·Р°РµРј
        imagecopy($newImage, $scaledImage, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight);

        imagedestroy($scaledImage);

        return $newImage;
    }

    /**
     * РџРѕРґРіРѕРЅРєР° РїРѕРґ СЂР°Р·РјРµСЂС‹ СЃ Р±РµР»С‹Рј С„РѕРЅРѕРј
     */
    private function fitWithWhiteBackground($sourceImage, $originalWidth, $originalHeight, $targetWidth, $targetHeight)
    {
        // Р’С‹С‡РёСЃР»СЏРµРј РєРѕСЌС„С„РёС†РёРµРЅС‚С‹ РјР°СЃС€С‚Р°Р±РёСЂРѕРІР°РЅРёСЏ
        $scaleX = $targetWidth / $originalWidth;
        $scaleY = $targetHeight / $originalHeight;
        $scale = min($scaleX, $scaleY); // Р‘РµСЂРµРј РјРµРЅСЊС€РёР№ РєРѕСЌС„С„РёС†РёРµРЅС‚ РґР»СЏ РІРїРёСЃС‹РІР°РЅРёСЏ

        // Р’С‹С‡РёСЃР»СЏРµРј РЅРѕРІС‹Рµ СЂР°Р·РјРµСЂС‹
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);

        // РЎРѕР·РґР°РµРј РЅРѕРІРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ СЃ Р±РµР»С‹Рј С„РѕРЅРѕРј
        $newImage = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($newImage, 255, 255, 255);
        imagefill($newImage, 0, 0, $white);

        // Р’С‹С‡РёСЃР»СЏРµРј РєРѕРѕСЂРґРёРЅР°С‚С‹ РґР»СЏ С†РµРЅС‚СЂРёСЂРѕРІР°РЅРёСЏ
        $x = intval(($targetWidth - $newWidth) / 2);
        $y = intval(($targetHeight - $newHeight) / 2);

        // РЎРЅР°С‡Р°Р»Р° РјР°СЃС€С‚Р°Р±РёСЂСѓРµРј
        $scaledImage = imagecreatetruecolor($newWidth, $newHeight);
        if (imageistruecolor($sourceImage)) {
            imagealphablending($scaledImage, false);
            imagesavealpha($scaledImage, true);
        }
        imagecopyresampled($scaledImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        // Р—Р°С‚РµРј РІСЃС‚Р°РІР»СЏРµРј РІ С†РµРЅС‚СЂ
        imagecopy($newImage, $scaledImage, $x, $y, 0, 0, $newWidth, $newHeight);

        imagedestroy($scaledImage);

        return $newImage;
    }

    /**
     * РџРѕРґРіРѕРЅРєР° РїРѕРґ СЂР°Р·РјРµСЂС‹ СЃРёСЃС‚РµРјС‹ (СѓРјРµРЅСЊС€РµРЅРёРµ РµСЃР»Рё РїСЂРµРІС‹С€Р°РµС‚ Р»РёРјРёС‚С‹)
     */
    private function fitSystemSize($sourceImage, $originalWidth, $originalHeight, $maxWidth, $maxHeight)
    {
        // Р•СЃР»Рё РёР·РѕР±СЂР°Р¶РµРЅРёРµ СѓР¶Рµ РјРµРЅСЊС€Рµ РёР»Рё СЂР°РІРЅРѕ РјР°РєСЃРёРјР°Р»СЊРЅС‹Рј СЂР°Р·РјРµСЂР°Рј, РІРѕР·РІСЂР°С‰Р°РµРј РєР°Рє РµСЃС‚СЊ
        if ($originalWidth <= $maxWidth && $originalHeight <= $maxHeight) {
            return $sourceImage;
        }

        // Р’С‹С‡РёСЃР»СЏРµРј РєРѕСЌС„С„РёС†РёРµРЅС‚С‹ РјР°СЃС€С‚Р°Р±РёСЂРѕРІР°РЅРёСЏ
        $scaleX = $maxWidth / $originalWidth;
        $scaleY = $maxHeight / $originalHeight;
        $scale = min($scaleX, $scaleY); // Р‘РµСЂРµРј РјРµРЅСЊС€РёР№ РєРѕСЌС„С„РёС†РёРµРЅС‚ РґР»СЏ РІРїРёСЃС‹РІР°РЅРёСЏ

        // Р’С‹С‡РёСЃР»СЏРµРј РЅРѕРІС‹Рµ СЂР°Р·РјРµСЂС‹
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);

        // РЎРѕР·РґР°РµРј РЅРѕРІРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ
        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        // РЎРѕС…СЂР°РЅСЏРµРј РїСЂРѕР·СЂР°С‡РЅРѕСЃС‚СЊ РґР»СЏ PNG
        if (imageistruecolor($sourceImage)) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // РњР°СЃС€С‚Р°Р±РёСЂСѓРµРј РёР·РѕР±СЂР°Р¶РµРЅРёРµ
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        return $newImage;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃРёСЃС‚РµРјРЅСѓСЋ С€РёСЂРёРЅСѓ РёР·РѕР±СЂР°Р¶РµРЅРёР№ С‚РѕРІР°СЂРѕРІ
     */
    private function getSystemImageWidth()
    {
        $setting = \App\Models\Setting::where('key', 'shop_good_width')->first();

        return $setting ? (int) $setting->value : 500;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃРёСЃС‚РµРјРЅСѓСЋ РІС‹СЃРѕС‚Сѓ РёР·РѕР±СЂР°Р¶РµРЅРёР№ С‚РѕРІР°СЂРѕРІ
     */
    private function getSystemImageHeight()
    {
        $setting = \App\Models\Setting::where('key', 'shop_good_height')->first();

        return $setting ? (int) $setting->value : 500;
    }

    /**
     * Check for duplicates by specified fields
     */
    public function checkDuplicates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fields' => 'required|array',
            'fields.*' => 'required|string',
            'data' => 'required|array',
            'data.*' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $fields = $request->input('fields');
        $data = $request->input('data');
        $results = [];

        foreach ($data as $index => $item) {
            $query = ShopGood::query();

            // Build query for each field
            foreach ($fields as $field) {
                if (isset($item[$field]) && $item[$field] !== '') {
                    $query->where($field, $item[$field]);
                }
            }

            $existing = $query->first();

            $results[] = [
                'index' => $index,
                'exists' => $existing !== null,
                'id' => $existing ? $existing->id : null,
                'item' => $item,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    /**
     * Р›РѕРіРёСЂРѕРІР°РЅРёРµ Р°СѓРґРёС‚Р°
     */
    private function logAudit($good, $action, $oldValues, $newValues)
    {
        $good->audit()->create([
            'user_id' => request()->user()->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * РР·РјРµРЅРµРЅРёРµ СЂР°Р·РјРµСЂР° РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
     */
    private function resizeImageFile($imagePath, $width, $height, $resizeType)
    {
        try {
            if (! file_exists($imagePath)) {
                return false;
            }

            $imageInfo = getimagesize($imagePath);
            if (! $imageInfo) {
                return false;
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];

            // РЎРѕР·РґР°РµРј СЂРµСЃСѓСЂСЃ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІ Р·Р°РІРёСЃРёРјРѕСЃС‚Рё РѕС‚ С‚РёРїР°
            switch ($mimeType) {
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $sourceImage = imagecreatefromgif($imagePath);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($imagePath);
                    break;
                default:
                    return false;
            }

            if (! $sourceImage) {
                return false;
            }

            $newImage = null;

            // РџСЂРёРјРµРЅСЏРµРј РЅСѓР¶РЅС‹Р№ С‚РёРї РёР·РјРµРЅРµРЅРёСЏ СЂР°Р·РјРµСЂР°
            switch ($resizeType) {
                case 'crop_proportional':
                    $newImage = $this->cropProportional($sourceImage, $originalWidth, $originalHeight, $width, $height);
                    break;
                case 'fit_with_white':
                    $newImage = $this->fitWithWhiteBackground($sourceImage, $originalWidth, $originalHeight, $width, $height);
                    break;
                case 'fit_system':
                    $newImage = $this->fitSystemSize($sourceImage, $originalWidth, $originalHeight, $width, $height);
                    break;
                default:
                    $newImage = $sourceImage;
            }

            if (! $newImage) {
                imagedestroy($sourceImage);

                return false;
            }

            // РЎРѕС…СЂР°РЅСЏРµРј РёР·РѕР±СЂР°Р¶РµРЅРёРµ
            $result = false;
            switch ($mimeType) {
                case 'image/jpeg':
                    $result = imagejpeg($newImage, $imagePath, 90);
                    break;
                case 'image/png':
                    $result = imagepng($newImage, $imagePath, 9);
                    break;
                case 'image/gif':
                    $result = imagegif($newImage, $imagePath);
                    break;
                case 'image/webp':
                    $result = imagewebp($newImage, $imagePath, 90);
                    break;
            }

            // РћСЃРІРѕР±РѕР¶РґР°РµРј РїР°РјСЏС‚СЊ
            imagedestroy($sourceImage);
            if ($newImage !== $sourceImage) {
                imagedestroy($newImage);
            }

            return $result;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * РњР°СЃСЃРѕРІС‹Р№ РїР°СЂСЃРёРЅРі С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє РёР· РѕРїРёСЃР°РЅРёР№ С‚РѕРІР°СЂРѕРІ
     */
    public function massParseProperties(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'batch_size' => 'nullable|integer|min:1|max:1000',
            'offset' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $batchSize = $request->get('batch_size', 1000);
            $offset = $request->get('offset', 0);

            // РџРѕР»СѓС‡Р°РµРј С‚РѕРІР°СЂС‹ Р±Р°С‚С‡Р°РјРё
            $goods = ShopGood::select('id', 'description')
                ->whereNotNull('description')
                ->where('description', '!=', '')
                ->skip($offset)
                ->take($batchSize)
                ->get();

            $stats = [
                'processed' => 0,
                'success' => 0,
                'error' => 0,
                'skipped' => 0,
                'errors' => [], // Р”РµС‚Р°Р»СЊРЅР°СЏ РёРЅС„РѕСЂРјР°С†РёСЏ РѕР± РѕС€РёР±РєР°С…
            ];

            $hasValueCol = Schema::hasColumn('shop_good_properties', 'value');
            $hasShopValueIdCol = Schema::hasColumn('shop_good_properties', 'shop_property_value_id');
            $hasVariationIdCol = Schema::hasColumn('shop_good_properties', 'variation_id');

            // РљСЌС€ СЃРІРѕР№СЃС‚РІ РґР»СЏ РёР·Р±РµР¶Р°РЅРёСЏ РїРѕРІС‚РѕСЂРЅС‹С… Р·Р°РїСЂРѕСЃРѕРІ
            $propertiesCache = [];
            $propertyValuesCache = [];

            foreach ($goods as $good) {
                try {
                    if (empty($good->description)) {
                        $stats['skipped']++;
                        $stats['processed']++;

                        continue;
                    }

                    // РџР°СЂСЃРёРј РѕРїРёСЃР°РЅРёРµ
                    $parsedProperties = $this->parseDescription($good->description);

                    if (empty($parsedProperties)) {
                        $stats['skipped']++;
                        $stats['processed']++;

                        continue;
                    }

                    // РџРѕРґРіРѕС‚Р°РІР»РёРІР°РµРј СЃРІРѕР№СЃС‚РІР° РґР»СЏ СЃРѕС…СЂР°РЅРµРЅРёСЏ
                    $propertiesToSave = [];

                    foreach ($parsedProperties as $parsed) {
                        $propertyName = trim($parsed['name']);
                        $propertyValue = trim($parsed['value']);

                        if (empty($propertyName) || empty($propertyValue)) {
                            continue;
                        }

                        // РџСЂРѕРІРµСЂСЏРµРј РґР»РёРЅСѓ РЅР°Р·РІР°РЅРёСЏ СЃРІРѕР№СЃС‚РІР° (РѕР±С‹С‡РЅРѕ VARCHAR(255))
                        $maxNameLength = 255;
                        if (mb_strlen($propertyName) > $maxNameLength) {
                            \Log::warning('РџСЂРѕРїСѓС‰РµРЅР° С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєР° СЃ СЃР»РёС€РєРѕРј РґР»РёРЅРЅС‹Рј РЅР°Р·РІР°РЅРёРµРј', [
                                'good_id' => $good->id,
                                'property_name_length' => mb_strlen($propertyName),
                                'property_name_preview' => mb_substr($propertyName, 0, 100).'...',
                            ]);

                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'name_too_long',
                                'message' => "РќР°Р·РІР°РЅРёРµ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё СЃР»РёС€РєРѕРј РґР»РёРЅРЅРѕРµ ({$maxNameLength} СЃРёРјРІРѕР»РѕРІ РјР°РєСЃРёРјСѓРј)",
                                'details' => 'РќР°Р·РІР°РЅРёРµ РґР»РёРЅРѕР№ '.mb_strlen($propertyName).' СЃРёРјРІРѕР»РѕРІ (РїРѕРєР°Р·Р°РЅРѕ РїРµСЂРІС‹Рµ 100: '.mb_substr($propertyName, 0, 100).'...)',
                            ];

                            continue;
                        }

                        // РџСЂРѕРІРµСЂСЏРµРј РґР»РёРЅСѓ Р·РЅР°С‡РµРЅРёСЏ (VARCHAR(255) = РјР°РєСЃРёРјСѓРј 255 СЃРёРјРІРѕР»РѕРІ)
                        $maxValueLength = 255;
                        if (mb_strlen($propertyValue) > $maxValueLength) {
                            \Log::warning('РџСЂРѕРїСѓС‰РµРЅР° С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєР° СЃ СЃР»РёС€РєРѕРј РґР»РёРЅРЅС‹Рј Р·РЅР°С‡РµРЅРёРµРј', [
                                'good_id' => $good->id,
                                'property_name' => $propertyName,
                                'value_length' => mb_strlen($propertyValue),
                                'value_preview' => mb_substr($propertyValue, 0, 100).'...',
                            ]);

                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'value_too_long',
                                'message' => "Р—РЅР°С‡РµРЅРёРµ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё СЃР»РёС€РєРѕРј РґР»РёРЅРЅРѕРµ ({$maxValueLength} СЃРёРјРІРѕР»РѕРІ РјР°РєСЃРёРјСѓРј)",
                                'details' => "РҐР°СЂР°РєС‚РµСЂРёСЃС‚РёРєР° '{$propertyName}': Р·РЅР°С‡РµРЅРёРµ РґР»РёРЅРѕР№ ".mb_strlen($propertyValue).' СЃРёРјРІРѕР»РѕРІ (РїРѕРєР°Р·Р°РЅРѕ РїРµСЂРІС‹Рµ 100: '.mb_substr($propertyValue, 0, 100).'...)',
                            ];

                            continue;
                        }

                        // РС‰РµРј РёР»Рё СЃРѕР·РґР°РµРј СЃРІРѕР№СЃС‚РІРѕ
                        $property = null;
                        $cacheKey = strtolower($propertyName);

                        if (isset($propertiesCache[$cacheKey])) {
                            $property = $propertiesCache[$cacheKey];
                        } else {
                            try {
                                // РќРѕСЂРјР°Р»РёР·СѓРµРј РЅР°Р·РІР°РЅРёРµ: С‚РѕР»СЊРєРѕ РїРµСЂРІРѕРµ СЃР»РѕРІРѕ СЃ Р±РѕР»СЊС€РѕР№ Р±СѓРєРІС‹
                                $normalizedName = mb_strtolower($propertyName);
                                $normalizedName = mb_strtoupper(mb_substr($normalizedName, 0, 1)).mb_substr($normalizedName, 1);

                                $property = ShopProperty::whereRaw('LOWER(name) = ?', [strtolower($propertyName)])->first();

                                if (! $property) {
                                    $property = ShopProperty::create([
                                        'name' => $normalizedName,
                                        'property_type' => 'string',
                                        'slug' => \Illuminate\Support\Str::slug($normalizedName),
                                    ]);
                                } else {
                                    // Р•СЃР»Рё СЃРІРѕР№СЃС‚РІРѕ СЃСѓС‰РµСЃС‚РІСѓРµС‚, РѕР±РЅРѕРІР»СЏРµРј РµРіРѕ РЅР°Р·РІР°РЅРёРµ РЅР° РЅРѕСЂРјР°Р»РёР·РѕРІР°РЅРЅРѕРµ (РµСЃР»Рё РѕРЅРѕ РѕС‚Р»РёС‡Р°РµС‚СЃСЏ)
                                    if ($property->name !== $normalizedName) {
                                        $property->update([
                                            'name' => $normalizedName,
                                        ]);
                                    }
                                }

                                $propertiesCache[$cacheKey] = $property;
                            } catch (\Exception $e) {
                                \Log::error('РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ/РїРѕРёСЃРєР° СЃРІРѕР№СЃС‚РІР°', [
                                    'good_id' => $good->id,
                                    'property_name' => $propertyName,
                                    'exception' => get_class($e),
                                    'message' => $e->getMessage(),
                                ]);

                                continue;
                            }
                        }

                        if (! $property) {
                            continue;
                        }

                        // РС‰РµРј РёР»Рё СЃРѕР·РґР°РµРј Р·РЅР°С‡РµРЅРёРµ СЃРІРѕР№СЃС‚РІР°
                        $propertyValueModel = null;
                        $valueCacheKey = $property->id.'_'.strtolower($propertyValue);

                        if (isset($propertyValuesCache[$valueCacheKey])) {
                            $propertyValueModel = $propertyValuesCache[$valueCacheKey];
                        } else {
                            try {
                                $propertyValueModel = ShopPropertyValue::where('property_id', $property->id)
                                    ->whereRaw('LOWER(value) = ?', [strtolower($propertyValue)])
                                    ->first();

                                if (! $propertyValueModel) {
                                    $propertyValueModel = ShopPropertyValue::create([
                                        'property_id' => $property->id,
                                        'value' => $propertyValue,
                                        'is_active' => true,
                                    ]);
                                }

                                $propertyValuesCache[$valueCacheKey] = $propertyValueModel;
                            } catch (\Exception $e) {
                                \Log::error('РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ/РїРѕРёСЃРєР° Р·РЅР°С‡РµРЅРёСЏ СЃРІРѕР№СЃС‚РІР°', [
                                    'good_id' => $good->id,
                                    'property_id' => $property->id,
                                    'property_name' => $propertyName,
                                    'property_value' => $propertyValue,
                                    'exception' => get_class($e),
                                    'message' => $e->getMessage(),
                                ]);

                                $stats['errors'][] = [
                                    'good_id' => $good->id,
                                    'type' => 'property_value_error',
                                    'message' => $e->getMessage(),
                                    'details' => "РћС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё Р·РЅР°С‡РµРЅРёСЏ СЃРІРѕР№СЃС‚РІР° '{$propertyName}': '{$propertyValue}'",
                                ];

                                continue;
                            }
                        }

                        if ($propertyValueModel) {
                            $propertiesToSave[] = [
                                'property_id' => $property->id,
                                'shop_property_value_id' => $propertyValueModel->id,
                                'value' => $propertyValue,
                            ];
                        }
                    }

                    // РЎРѕС…СЂР°РЅСЏРµРј СЃРІРѕР№СЃС‚РІР° С‚РѕРІР°СЂР°
                    if (! empty($propertiesToSave)) {
                        DB::beginTransaction();

                        try {
                            // РЈРґР°Р»СЏРµРј СЃС‚Р°СЂС‹Рµ СЃРІРѕР№СЃС‚РІР° С‚РѕРІР°СЂР° (С‚РѕР»СЊРєРѕ Р±Р°Р·РѕРІС‹Рµ, РЅРµ РІР°СЂРёР°С†РёРё)
                            $deleteQuery = DB::table('shop_good_properties')->where('good_id', $good->id);
                            if ($hasVariationIdCol) {
                                $deleteQuery->whereNull('variation_id');
                            }
                            $deleteQuery->delete();

                            // РЈР±РёСЂР°РµРј РґСѓР±Р»РёРєР°С‚С‹ РїРѕ property_id (РѕСЃС‚Р°РІР»СЏРµРј РїРѕСЃР»РµРґРЅРµРµ РІС…РѕР¶РґРµРЅРёРµ)
                            $uniqueProperties = [];
                            foreach ($propertiesToSave as $prop) {
                                $uniqueProperties[$prop['property_id']] = $prop;
                            }
                            $propertiesToSave = array_values($uniqueProperties);

                            // Р’СЃС‚Р°РІР»СЏРµРј РЅРѕРІС‹Рµ СЃРІРѕР№СЃС‚РІР°
                            foreach ($propertiesToSave as $prop) {
                                $insertData = [
                                    'good_id' => $good->id,
                                    'property_id' => $prop['property_id'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];

                                // РЈРєР°Р·С‹РІР°РµРј variation_id РєР°Рє NULL РґР»СЏ Р±Р°Р·РѕРІС‹С… СЃРІРѕР№СЃС‚РІ
                                if ($hasVariationIdCol) {
                                    $insertData['variation_id'] = null;
                                }

                                if ($hasShopValueIdCol) {
                                    $insertData['shop_property_value_id'] = $prop['shop_property_value_id'];
                                }

                                if ($hasValueCol) {
                                    $insertData['value'] = $prop['value'];
                                }

                                // РСЃРїРѕР»СЊР·СѓРµРј updateOrInsert РґР»СЏ РёР·Р±РµР¶Р°РЅРёСЏ РґСѓР±Р»РёРєР°С‚РѕРІ
                                $whereConditions = [
                                    'good_id' => $good->id,
                                    'property_id' => $prop['property_id'],
                                ];

                                if ($hasVariationIdCol) {
                                    $whereConditions['variation_id'] = null;
                                }

                                DB::table('shop_good_properties')->updateOrInsert($whereConditions, $insertData);
                            }

                            DB::commit();
                            $stats['success']++;
                        } catch (\Exception $e) {
                            DB::rollBack();
                            $errorMessage = 'РћС€РёР±РєР° СЃРѕС…СЂР°РЅРµРЅРёСЏ СЃРІРѕР№СЃС‚РІ РґР»СЏ С‚РѕРІР°СЂР° '.$good->id.': '.$e->getMessage();
                            \Log::error($errorMessage, [
                                'good_id' => $good->id,
                                'exception' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                                'properties_count' => count($propertiesToSave),
                            ]);

                            $stats['error']++;
                            $stats['errors'][] = [
                                'good_id' => $good->id,
                                'type' => 'save_error',
                                'message' => $e->getMessage(),
                                'details' => 'РћС€РёР±РєР° РїСЂРё СЃРѕС…СЂР°РЅРµРЅРёРё СЃРІРѕР№СЃС‚РІ РІ Р‘Р”',
                            ];
                        }
                    } else {
                        $stats['skipped']++;
                    }

                    $stats['processed']++;
                } catch (\Exception $e) {
                    $errorMessage = 'РћС€РёР±РєР° РѕР±СЂР°Р±РѕС‚РєРё С‚РѕРІР°СЂР° '.$good->id.': '.$e->getMessage();
                    \Log::error($errorMessage, [
                        'good_id' => $good->id,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);

                    $stats['error']++;
                    $stats['errors'][] = [
                        'good_id' => $good->id,
                        'type' => 'processing_error',
                        'message' => $e->getMessage(),
                        'details' => 'РћС€РёР±РєР° РїСЂРё РїР°СЂСЃРёРЅРіРµ РёР»Рё РѕР±СЂР°Р±РѕС‚РєРµ С‚РѕРІР°СЂР°',
                    ];
                    $stats['processed']++;
                }
            }

            // РћРіСЂР°РЅРёС‡РёРІР°РµРј РєРѕР»РёС‡РµСЃС‚РІРѕ РѕС€РёР±РѕРє РІ РѕС‚РІРµС‚Рµ (РїРµСЂРІС‹Рµ 50)
            $totalErrorsCount = count($stats['errors']);
            $errorsToReturn = array_slice($stats['errors'], 0, 50);
            $stats['errors'] = $errorsToReturn;
            $stats['total_errors'] = $totalErrorsCount;
            if ($totalErrorsCount > 50) {
                $stats['errors_truncated'] = true;
            }

            return response()->json([
                'success' => true,
                'message' => 'РџР°СЂСЃРёРЅРі Р·Р°РІРµСЂС€РµРЅ',
                'data' => [
                    'stats' => $stats,
                    'has_more' => $goods->count() === $batchSize,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('РћС€РёР±РєР° РјР°СЃСЃРѕРІРѕРіРѕ РїР°СЂСЃРёРЅРіР°: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РјР°СЃСЃРѕРІРѕРіРѕ РїР°СЂСЃРёРЅРіР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџР°СЂСЃРёРЅРі HTML РѕРїРёСЃР°РЅРёСЏ РґР»СЏ РёР·РІР»РµС‡РµРЅРёСЏ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє
     */
    private function parseDescription(string $htmlDescription): array
    {
        if (empty($htmlDescription)) {
            return [];
        }

        $results = [];

        // РСЃРїРѕР»СЊР·СѓРµРј DOMDocument РґР»СЏ РїР°СЂСЃРёРЅРіР° HTML
        libxml_use_internal_errors(true);

        // РћР±РѕСЂР°С‡РёРІР°РµРј РІ РєРѕРЅС‚РµР№РЅРµСЂ РґР»СЏ РєРѕСЂСЂРµРєС‚РЅРѕРіРѕ РїР°СЂСЃРёРЅРіР°
        $wrappedHtml = '<div>'.$htmlDescription.'</div>';
        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML(mb_convert_encoding($wrappedHtml, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $lines = [];

        // РЎРЅР°С‡Р°Р»Р° РёС‰РµРј РІСЃРµ div-С‹ СЃ data-block="true" - РєР°Р¶РґР°СЏ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєР° РІ РѕС‚РґРµР»СЊРЅРѕРј Р±Р»РѕРєРµ
        $dataBlockElements = $xpath->query('//div[@data-block="true"]');

        if ($dataBlockElements && $dataBlockElements->length > 0) {
            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Р¶РґС‹Р№ div СЃ data-block="true" РєР°Рє РѕС‚РґРµР»СЊРЅСѓСЋ СЃС‚СЂРѕРєСѓ
            foreach ($dataBlockElements as $element) {
                $innerHTML = $this->getInnerHTML($element);
                $textContent = trim($element->textContent);

                if (! empty($textContent) && mb_strlen($textContent) >= 3) {
                    $lines[] = [
                        'html' => $innerHTML,
                        'text' => $textContent,
                    ];
                }
            }
        }

        // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё data-block СЌР»РµРјРµРЅС‚С‹, РёСЃРїРѕР»СЊР·СѓРµРј СЃС‚Р°РЅРґР°СЂС‚РЅСѓСЋ Р»РѕРіРёРєСѓ
        if (empty($lines)) {
            // РџРѕР»СѓС‡Р°РµРј РІСЃРµ Р±Р»РѕС‡РЅС‹Рµ СЌР»РµРјРµРЅС‚С‹ РІРµСЂС…РЅРµРіРѕ СѓСЂРѕРІРЅСЏ
            $allBlockElements = $xpath->query('//p | //div | //li');

            $topLevelElements = [];

            if ($allBlockElements && $allBlockElements->length > 0) {
                foreach ($allBlockElements as $element) {
                    // РџСЂРѕРІРµСЂСЏРµРј, РЅРµ СЏРІР»СЏРµС‚СЃСЏ Р»Рё СЌР»РµРјРµРЅС‚ РІР»РѕР¶РµРЅРЅС‹Рј
                    $isNested = false;
                    $parent = $element->parentNode;
                    while ($parent && $parent->nodeName !== '#document' && $parent->nodeName !== 'body' && $parent->nodeName !== 'div') {
                        $parentTagName = strtolower($parent->nodeName);
                        if (in_array($parentTagName, ['p', 'div', 'li'])) {
                            $isNested = true;
                            break;
                        }
                        $parent = $parent->parentNode;
                    }

                    if (! $isNested) {
                        $topLevelElements[] = $element;
                    }
                }
            }

            if (count($topLevelElements) > 0) {
                // Р•СЃР»Рё РµСЃС‚СЊ Р±Р»РѕС‡РЅС‹Рµ СЌР»РµРјРµРЅС‚С‹ РІРµСЂС…РЅРµРіРѕ СѓСЂРѕРІРЅСЏ, РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Р¶РґС‹Р№
                foreach ($topLevelElements as $element) {
                    $innerHTML = $this->getInnerHTML($element);
                    $textContent = trim($element->textContent);

                    if (empty($textContent)) {
                        continue;
                    }

                    // Р”Р»СЏ РѕСЃС‚Р°Р»СЊРЅС‹С… СЌР»РµРјРµРЅС‚РѕРІ СЂР°Р·Р±РёРІР°РµРј HTML РїРѕ <br> С‚РµРіР°Рј РґР»СЏ РїСЂР°РІРёР»СЊРЅРѕР№ РѕР±СЂР°Р±РѕС‚РєРё СЃС‚СЂРѕРє
                    $htmlParts = preg_split('/(?:<br\s*\/?>|<br>)/i', $innerHTML);

                    foreach ($htmlParts as $htmlPart) {
                        $htmlPart = trim($htmlPart);
                        if (empty($htmlPart)) {
                            continue;
                        }

                        // РЎРѕР·РґР°РµРј РІСЂРµРјРµРЅРЅС‹Р№ DOM РґР»СЏ РёР·РІР»РµС‡РµРЅРёСЏ С‚РµРєСЃС‚Р°
                        $tempDom = new \DOMDocument('1.0', 'UTF-8');
                        $tempWrapped = '<div>'.$htmlPart.'</div>';
                        @$tempDom->loadHTML(mb_convert_encoding($tempWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                        $text = trim($tempDom->textContent);

                        if (! empty($text) && mb_strlen($text) >= 3) {
                            $lines[] = [
                                'html' => $htmlPart,
                                'text' => $text,
                            ];
                        }
                    }
                }
            }
        }

        // Р•СЃР»Рё РЅРµ РїРѕР»СѓС‡РёР»РѕСЃСЊ СЂР°Р·Р±РёС‚СЊ РїРѕ СЌР»РµРјРµРЅС‚Р°Рј, СЂР°Р·Р±РёРІР°РµРј РїРѕ РїРµСЂРµРЅРѕСЃР°Рј СЃС‚СЂРѕРє Рё С‚РµРіР°Рј
        if (empty($lines)) {
            $htmlContent = $htmlDescription;
            $textContent = strip_tags($htmlDescription);

            // РЎРЅР°С‡Р°Р»Р° РїСЂРѕР±СѓРµРј СЂР°Р·Р±РёС‚СЊ РїРѕ HTML С‚РµРіР°Рј
            $htmlParts = preg_split('/(?:<br\s*\/?>|<\/p>|<\/div>|<\/li>)/i', $htmlContent);

            if (count($htmlParts) > 1) {
                foreach ($htmlParts as $htmlPart) {
                    $htmlPart = trim($htmlPart);
                    if (empty($htmlPart)) {
                        continue;
                    }

                    $tempDom = new \DOMDocument('1.0', 'UTF-8');
                    $tempWrapped = '<div>'.$htmlPart.'</div>';
                    @$tempDom->loadHTML(mb_convert_encoding($tempWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    $text = trim($tempDom->textContent);

                    if (! empty($text) && mb_strlen($text) >= 3) {
                        $lines[] = [
                            'html' => $htmlPart,
                            'text' => $text,
                        ];
                    }
                }
            } else {
                // Р•СЃР»Рё HTML РЅРµ СЂР°Р·Р±РёР»СЃСЏ, РїСЂРѕР±СѓРµРј СЂР°Р·Р±РёС‚СЊ РїРѕ РїРµСЂРµРЅРѕСЃР°Рј СЃС‚СЂРѕРє РІ С‚РµРєСЃС‚Рµ
                $textLines = preg_split('/\n/', $textContent);
                foreach ($textLines as $textLine) {
                    $textLine = trim($textLine);
                    if (! empty($textLine) && mb_strlen($textLine) >= 3) {
                        $lines[] = [
                            'html' => $textLine,
                            'text' => $textLine,
                        ];
                    }
                }
            }
        }

        // Р•СЃР»Рё РІСЃРµ РµС‰Рµ РЅРµС‚ СЃС‚СЂРѕРє, Р±РµСЂРµРј РІРµСЃСЊ РєРѕРЅС‚РµРЅС‚ РєР°Рє РѕРґРЅСѓ СЃС‚СЂРѕРєСѓ
        if (empty($lines)) {
            $htmlContent = $htmlDescription;
            $textContent = strip_tags($htmlDescription);

            if (! empty($textContent) && mb_strlen($textContent) >= 3) {
                $lines[] = [
                    'html' => $htmlContent,
                    'text' => $textContent,
                ];
            }
        }

        // РџР°СЂСЃРёРј РєР°Р¶РґСѓСЋ СЃС‚СЂРѕРєСѓ
        foreach ($lines as $lineData) {
            $lineHTML = $lineData['html'];
            $lineText = $lineData['text'];

            if (empty($lineText) || mb_strlen($lineText) < 3) {
                continue;
            }

            $propertyName = '';
            $propertyValue = '';

            // РџР°СЂСЃРёРј HTML СЃС‚СЂРѕРєРё РґР»СЏ РїРѕРёСЃРєР° Р¶РёСЂРЅРѕРіРѕ С‚РµРєСЃС‚Р°
            $lineWrapped = '<div>'.$lineHTML.'</div>';
            $lineDom = new \DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            @$lineDom->loadHTML(mb_convert_encoding($lineWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $lineXpath = new \DOMXPath($lineDom);

            // РС‰РµРј Р¶РёСЂРЅС‹Рµ СЌР»РµРјРµРЅС‚С‹ (strong, b) - СЃРЅР°С‡Р°Р»Р° СЃС‚Р°РЅРґР°СЂС‚РЅС‹Рµ С‚РµРіРё
            $boldElements = $lineXpath->query('//strong | //b');
            $boldElement = null;

            if ($boldElements && $boldElements->length > 0) {
                $boldElement = $boldElements->item(0);
            } else {
                // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё СЃС‚Р°РЅРґР°СЂС‚РЅС‹Рµ С‚РµРіРё, РёС‰РµРј РІСЃРµ СЌР»РµРјРµРЅС‚С‹ Рё РїСЂРѕРІРµСЂСЏРµРј РёС… СЃС‚РёР»Рё
                $allElements = $lineXpath->query('//*');
                if ($allElements) {
                    foreach ($allElements as $elem) {
                        // РџСЂРѕРІРµСЂСЏРµРј С‚РµРі
                        $tagName = strtolower($elem->nodeName);
                        if ($tagName === 'strong' || $tagName === 'b') {
                            $boldElement = $elem;
                            break;
                        }

                        // РџСЂРѕРІРµСЂСЏРµРј inline СЃС‚РёР»СЊ
                        if ($elem->hasAttribute('style')) {
                            $style = $elem->getAttribute('style');
                            if (preg_match('/font-weight\s*:\s*(bold|700|6\d{2}|7\d{2}|8\d{2}|900)/i', $style)) {
                                $boldElement = $elem;
                                break;
                            }
                        }

                        // РџСЂРѕРІРµСЂСЏРµРј РєР»Р°СЃСЃ
                        if ($elem->hasAttribute('class')) {
                            $className = $elem->getAttribute('class');
                            if (preg_match('/(?:^|\s)(?:bold|font-bold|font-weight-bold|fw-bold)(?:\s|$)/i', $className)) {
                                $boldElement = $elem;
                                break;
                            }
                        }
                    }
                }
            }

            if ($boldElement) {
                // РЁРђР“ 1: РР·РІР»РµРєР°РµРј Р’Р•РЎР¬ С‚РµРєСЃС‚ РёР· Р¶РёСЂРЅРѕРіРѕ СЌР»РµРјРµРЅС‚Р° - СЌС‚Рѕ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєР°
                $propertyName = trim($boldElement->textContent);
                $propertyName = preg_replace('/\s+/', ' ', $propertyName);

                // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РєРѕРЅС†Рµ РЅР°Р·РІР°РЅРёСЏ, РµСЃР»Рё РµСЃС‚СЊ
                $propertyName = preg_replace('/:\s*$/', '', $propertyName);

                if (! empty($propertyName)) {
                    // РЁРђР“ 2: РС‰РµРј Р·РЅР°С‡РµРЅРёРµ - СЃРЅР°С‡Р°Р»Р° РїСЂРѕР±СѓРµРј РЅР°Р№С‚Рё СЃР»РµРґСѓСЋС‰РёР№ sibling СЌР»РµРјРµРЅС‚
                    $propertyValue = '';
                    $nextSibling = $boldElement->nextSibling;

                    while ($nextSibling) {
                        if ($nextSibling->nodeType === XML_ELEMENT_NODE) {
                            $siblingText = trim($nextSibling->textContent);
                            if (! empty($siblingText)) {
                                $propertyValue = $siblingText;
                                break;
                            }
                        } elseif ($nextSibling->nodeType === XML_TEXT_NODE) {
                            $text = trim($nextSibling->textContent);
                            if (! empty($text)) {
                                $propertyValue = $text;
                                break;
                            }
                        }
                        $nextSibling = $nextSibling->nextSibling;
                    }

                    // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё С‡РµСЂРµР· sibling, РїСЂРѕР±СѓРµРј РёР·РІР»РµС‡СЊ РёР· СЂРѕРґРёС‚РµР»СЊСЃРєРѕРіРѕ СЌР»РµРјРµРЅС‚Р°
                    if (empty($propertyValue)) {
                        $parent = $boldElement->parentNode;
                        if ($parent) {
                            $parentText = trim($parent->textContent);
                            $boldText = trim($boldElement->textContent);

                            // РЈР±РёСЂР°РµРј РЅР°Р·РІР°РЅРёРµ РёР· С‚РµРєСЃС‚Р° СЂРѕРґРёС‚РµР»СЏ
                            $parentText = str_replace($boldText, '', $parentText);
                            $parentText = preg_replace('/^:\s*/', '', $parentText);
                            $propertyValue = trim($parentText);
                        }
                    }

                    // Р•СЃР»Рё РІСЃРµ РµС‰Рµ РїСѓСЃС‚Рѕ, РїСЂРѕР±СѓРµРј РЅР°Р№С‚Рё С‡РµСЂРµР· HTML РїРѕР·РёС†РёСЋ
                    if (empty($propertyValue)) {
                        $boldOuterHTML = $this->getOuterHTML($boldElement);
                        $elementIndex = mb_strpos($lineHTML, $boldOuterHTML);

                        if ($elementIndex !== false) {
                            $boldEndIndex = $elementIndex + mb_strlen($boldOuterHTML);
                            $afterBoldHTML = mb_substr($lineHTML, $boldEndIndex);

                            $afterBoldWrapped = '<div>'.$afterBoldHTML.'</div>';
                            $afterBoldDom = new \DOMDocument('1.0', 'UTF-8');
                            libxml_use_internal_errors(true);
                            @$afterBoldDom->loadHTML(mb_convert_encoding($afterBoldWrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                            libxml_clear_errors();
                            $propertyValue = trim($afterBoldDom->textContent);
                        }
                    }

                    // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РЅР°С‡Р°Р»Рµ, РµСЃР»Рё РµСЃС‚СЊ
                    $propertyValue = preg_replace('/^:\s*/', '', $propertyValue);

                    // РќРѕСЂРјР°Р»РёР·СѓРµРј РїСЂРѕР±РµР»С‹
                    $propertyValue = preg_replace('/\s+/', ' ', trim($propertyValue));
                }
            }

            // Р•СЃР»Рё РЅРµ РЅР°С€Р»Рё С‡РµСЂРµР· Р¶РёСЂРЅС‹Р№ С‚РµРєСЃС‚, РїСЂРѕР±СѓРµРј РІР°СЂРёР°РЅС‚ СЃ РґРІРѕРµС‚РѕС‡РёРµРј
            if (empty($propertyName) || empty($propertyValue)) {
                // Р’Р°СЂРёР°РЅС‚ 2: РС‰РµРј С‚РµРєСЃС‚ РґРѕ РґРІРѕРµС‚РѕС‡РёСЏ РІ С‚РµРєСЃС‚РѕРІРѕРј РїСЂРµРґСЃС‚Р°РІР»РµРЅРёРё
                // РќРѕ С‚РѕР»СЊРєРѕ РµСЃР»Рё РґРІРѕРµС‚РѕС‡РёРµ РЅР°С…РѕРґРёС‚СЃСЏ РІ РЅР°С‡Р°Р»Рµ СЃС‚СЂРѕРєРё РёР»Рё РїРѕСЃР»Рµ РєРѕСЂРѕС‚РєРѕРіРѕ РЅР°Р·РІР°РЅРёСЏ (РЅРµ Р±РѕР»РµРµ 3 СЃР»РѕРІ)
                $colonIndex = mb_strpos($lineText, ':');
                if ($colonIndex !== false && $colonIndex > 0 && $colonIndex < mb_strlen($lineText) - 1) {
                    $beforeColon = trim(mb_substr($lineText, 0, $colonIndex));
                    $wordsBeforeColon = preg_split('/\s+/u', $beforeColon);
                    $wordsBeforeColon = array_filter($wordsBeforeColon, function ($w) {
                        return ! empty(trim($w));
                    });

                    // РСЃРїРѕР»СЊР·СѓРµРј РґРІРѕРµС‚РѕС‡РёРµ С‚РѕР»СЊРєРѕ РµСЃР»Рё РґРѕ РЅРµРіРѕ РЅРµ Р±РѕР»РµРµ 3 СЃР»РѕРІ (С‡С‚РѕР±С‹ РЅРµ СЂР°Р·Р±РёРІР°С‚СЊ Р·РЅР°С‡РµРЅРёСЏ СЃ РґРІРѕРµС‚РѕС‡РёРµРј РІРЅСѓС‚СЂРё)
                    if (count($wordsBeforeColon) > 0 && count($wordsBeforeColon) <= 3) {
                        $propertyName = $beforeColon;
                        $propertyValue = trim(mb_substr($lineText, $colonIndex + 1));
                    } else {
                        // Р•СЃР»Рё РґРѕ РґРІРѕРµС‚РѕС‡РёСЏ Р±РѕР»СЊС€Рµ 3 СЃР»РѕРІ, СЌС‚Рѕ СЃРєРѕСЂРµРµ РІСЃРµРіРѕ РґРІРѕРµС‚РѕС‡РёРµ РІРЅСѓС‚СЂРё Р·РЅР°С‡РµРЅРёСЏ, РїСЂРѕРїСѓСЃРєР°РµРј
                        $colonIndex = false;
                    }
                }

                if ($colonIndex === false) {
                    // Р’Р°СЂРёР°РЅС‚ 3: Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° - РїРѕРёСЃРє РґРѕ РїРµСЂРІРѕРіРѕ РґРµС„РёСЃР° (РЅРѕ РЅРµ Р±РѕР»РµРµ 3-С… СЃР»РѕРІ)
                    // РС‰РµРј РґРµС„РёСЃ СЃ РїСЂРѕР±РµР»Р°РјРё РІРѕРєСЂСѓРі (РЅР°РїСЂРёРјРµСЂ: " - " РёР»Рё " -")
                    $dashPattern = '/\s*-\s*/u';
                    $dashMatch = preg_match($dashPattern, $lineText, $matches, PREG_OFFSET_CAPTURE);

                    if ($dashMatch && isset($matches[0]) && isset($matches[0][1])) {
                        $dashIndex = $matches[0][1];
                        $beforeDash = trim(mb_substr($lineText, 0, $dashIndex));

                        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РґРѕ РґРµС„РёСЃР° РЅРµ Р±РѕР»РµРµ 3-С… СЃР»РѕРІ
                        $words = preg_split('/\s+/u', $beforeDash);
                        $words = array_filter($words, function ($w) {
                            return ! empty(trim($w));
                        });

                        if (count($words) > 0 && count($words) <= 3) {
                            $propertyName = $beforeDash;
                            // Р‘РµСЂРµРј РІСЃРµ РїРѕСЃР»Рµ РґРµС„РёСЃР° (РІРєР»СЋС‡Р°СЏ СЃР°Рј РґРµС„РёСЃ Рё РїСЂРѕР±РµР»С‹)
                            $propertyValue = trim(mb_substr($lineText, $dashIndex + mb_strlen($matches[0][0])));
                        } else {
                            // Р•СЃР»Рё РЅРµС‚ РЅРё Р¶РёСЂРЅРѕРіРѕ С‚РµРєСЃС‚Р°, РЅРё РґРІРѕРµС‚РѕС‡РёСЏ, РЅРё РїРѕРґС…РѕРґСЏС‰РµРіРѕ РґРµС„РёСЃР° - РїСЂРѕРїСѓСЃРєР°РµРј СЃС‚СЂРѕРєСѓ
                            continue;
                        }
                    } else {
                        // Р•СЃР»Рё РЅРµС‚ РЅРё Р¶РёСЂРЅРѕРіРѕ С‚РµРєСЃС‚Р°, РЅРё РґРІРѕРµС‚РѕС‡РёСЏ, РЅРё РґРµС„РёСЃР° - РїСЂРѕРїСѓСЃРєР°РµРј СЃС‚СЂРѕРєСѓ
                        continue;
                    }
                }
            }

            // РќРѕСЂРјР°Р»РёР·СѓРµРј
            $propertyName = preg_replace('/\s+/', ' ', trim($propertyName));
            $propertyValue = preg_replace('/\s+/', ' ', trim($propertyValue));

            // РЈР±РёСЂР°РµРј РґРІРѕРµС‚РѕС‡РёРµ РІ РЅР°С‡Р°Р»Рµ Рё РєРѕРЅС†Рµ Р·РЅР°С‡РµРЅРёСЏ, РµСЃР»Рё РѕРЅРѕ С‚Р°Рј РµСЃС‚СЊ (РЅР° СЃР»СѓС‡Р°Р№ РѕС€РёР±РѕРє РїР°СЂСЃРёРЅРіР°)
            $propertyValue = preg_replace('/^:\s*/', '', $propertyValue);
            $propertyValue = preg_replace('/\s*:\s*$/', '', $propertyValue);
            $propertyValue = trim($propertyValue);

            // РўСЂР°РЅСЃС„РѕСЂРјРёСЂСѓРµРј РЅР°Р·РІР°РЅРёРµ: С‚РѕР»СЊРєРѕ РїРµСЂРІРѕРµ СЃР»РѕРІРѕ СЃ Р±РѕР»СЊС€РѕР№ Р±СѓРєРІС‹
            $propertyName = mb_strtolower($propertyName);
            $propertyName = mb_strtoupper(mb_substr($propertyName, 0, 1)).mb_substr($propertyName, 1);

            if (empty($propertyName) || empty($propertyValue) || mb_strlen($propertyName) < 2 || mb_strlen($propertyValue) < 1) {
                continue;
            }

            // РџСЂРѕРІРµСЂСЏРµРј РЅР° РґСѓР±Р»РёРєР°С‚С‹
            $isDuplicate = false;
            foreach ($results as $result) {
                if (mb_strtolower(trim($result['name'])) === mb_strtolower(trim($propertyName)) &&
                    mb_strtolower(trim($result['value'])) === mb_strtolower(trim($propertyValue))) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (! $isDuplicate) {
                $results[] = [
                    'name' => $propertyName,
                    'value' => $propertyValue,
                ];
            }
        }

        return $results;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ innerHTML СЌР»РµРјРµРЅС‚Р°
     */
    private function getInnerHTML(\DOMElement $element): string
    {
        $innerHTML = '';
        $children = $element->childNodes;
        foreach ($children as $child) {
            $innerHTML .= $element->ownerDocument->saveHTML($child);
        }

        return $innerHTML;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ outerHTML СЌР»РµРјРµРЅС‚Р°
     */
    private function getOuterHTML(\DOMElement $element): string
    {
        return $element->ownerDocument->saveHTML($element);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё
     */
    public function getVariationAttributes($goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $attributesString = $variation->attributes_string;

            return response()->json([
                'success' => true,
                'data' => $attributesString,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚РѕРІ РІР°СЂРёР°С†РёРё: '.$e->getMessage(),
            ], 404);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ РѕСЃС‚Р°С‚РѕРє РІР°СЂРёР°С†РёРё
     */
    public function updateVariationStock(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'stock_quantity' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $variation->stock_quantity = $request->get('stock_quantity');
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'РћСЃС‚Р°С‚РѕРє РѕР±РЅРѕРІР»РµРЅ',
                'data' => [
                    'id' => $variation->id,
                    'stock_quantity' => $variation->stock_quantity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ РѕСЃС‚Р°С‚РєР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ РѕСЃС‚Р°С‚РѕРє РЅР° Сѓ/СЃ РІР°СЂРёР°С†РёРё
     */
    public function updateVariationRemoteStock(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'remote_stock_quantity' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $remoteStockValue = $request->get('remote_stock_quantity');
            $variation->remote_stock_quantity = ($remoteStockValue === '' || $remoteStockValue === null) ? null : (string) $remoteStockValue;
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'РћСЃС‚Р°С‚РѕРє РЅР° Сѓ/СЃ РѕР±РЅРѕРІР»РµРЅ',
                'data' => [
                    'id' => $variation->id,
                    'remote_stock_quantity' => $variation->remote_stock_quantity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ РѕСЃС‚Р°С‚РєР° РЅР° Сѓ/СЃ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ Р±С‹СЃС‚СЂС‹Р№ РѕСЃС‚Р°С‚РѕРє РЅР° Сѓ/СЃ РІР°СЂРёР°С†РёРё
     */
    public function updateVariationFastRemoteStock(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'fast_remote_stock_quantity' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $fastRemoteStockValue = $request->get('fast_remote_stock_quantity');
            $variation->fast_remote_stock_quantity = ($fastRemoteStockValue === '' || $fastRemoteStockValue === null) ? null : (string) $fastRemoteStockValue;
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Р‘С‹СЃС‚СЂС‹Р№ РѕСЃС‚Р°С‚РѕРє РЅР° Сѓ/СЃ РѕР±РЅРѕРІР»РµРЅ',
                'data' => [
                    'id' => $variation->id,
                    'fast_remote_stock_quantity' => $variation->fast_remote_stock_quantity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ Р±С‹СЃС‚СЂРѕРіРѕ РѕСЃС‚Р°С‚РєР° РЅР° Сѓ/СЃ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ С†РµРЅС‹ РІР°СЂРёР°С†РёРё
     */
    public function updateVariationPrice(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'price' => 'required|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'demping_price' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $price = round((float) $request->get('price'));
            $salePrice = $request->get('sale_price');
            if ($salePrice !== null && $salePrice !== '') {
                $salePrice = round((float) $salePrice);
                // РђРєС†РёРѕРЅРЅР°СЏ С†РµРЅР° РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ Р±РѕР»СЊС€Рµ РёР»Рё СЂР°РІРЅР° Р±Р°Р·РѕРІРѕР№
                if ($salePrice >= $price) {
                    $salePrice = null;
                }
                // РђРєС†РёРѕРЅРЅР°СЏ С†РµРЅР° РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РѕС‚СЂРёС†Р°С‚РµР»СЊРЅРѕР№
                if ($salePrice < 0) {
                    $salePrice = 0;
                }
            } else {
                $salePrice = null;
            }

            $dempingPrice = $request->get('demping_price');
            if ($dempingPrice !== null && $dempingPrice !== '') {
                $dempingPrice = round((float) $dempingPrice);
                if ($dempingPrice < 0) {
                    $dempingPrice = 0;
                }
            } else {
                $dempingPrice = null;
            }

            $variation->price = $price;
            $variation->sale_price = $salePrice;
            $variation->demping_price = $dempingPrice;
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Р¦РµРЅС‹ РѕР±РЅРѕРІР»РµРЅС‹',
                'data' => [
                    'id' => $variation->id,
                    'price' => $variation->price,
                    'sale_price' => $variation->sale_price,
                    'demping_price' => $variation->demping_price,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ С†РµРЅ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ РґРµРјРїРёРЅРі РІР°СЂРёР°С†РёРё
     */
    public function updateVariationDemping(Request $request, $goodId, $variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::where('good_id', $goodId)
                ->where('id', $variationId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'show_demping' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $variation->show_demping = $request->get('show_demping');
            $variation->save();

            return response()->json([
                'success' => true,
                'message' => 'Р”РµРјРїРёРЅРі РѕР±РЅРѕРІР»РµРЅ',
                'data' => [
                    'id' => $variation->id,
                    'show_demping' => $variation->show_demping,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ РґРµРјРїРёРЅРіР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџСЂРѕРєСЃРё РґР»СЏ Р·Р°РіСЂСѓР·РєРё YML С„РёРґРѕРІ (РѕР±С…РѕРґ CORS)
     */
    public function proxyYMLFeed(Request $request): JsonResponse
    {
        try {
            // Middleware auth:sanctum СѓР¶Рµ РїСЂРѕРІРµСЂРёР» Р°РІС‚РѕСЂРёР·Р°С†РёСЋ
            // Р•СЃР»Рё Р·Р°РїСЂРѕСЃ РґРѕС€РµР» РґРѕ СЌС‚РѕРіРѕ РјРµС‚РѕРґР°, Р·РЅР°С‡РёС‚ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°РІС‚РѕСЂРёР·РѕРІР°РЅ
            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'url' => 'required|url|max:2048',
                'username' => 'nullable|string|max:255',
                'password' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $url = $request->input('url');
            $username = $request->input('username');
            $password = $request->input('password');

            // Р’Р°Р»РёРґР°С†РёСЏ URL
            if (empty($url)) {
                return response()->json([
                    'success' => false,
                    'error' => 'URL РЅРµ СѓРєР°Р·Р°РЅ',
                ], 400);
            }

            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'success' => false,
                    'error' => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ URL',
                ], 400);
            }

            // Р—Р°РіСЂСѓР¶Р°РµРј YML С„РёРґ С‡РµСЂРµР· cURL (РЅР° СЃРµСЂРІРµСЂРµ РЅРµС‚ РѕРіСЂР°РЅРёС‡РµРЅРёР№ CORS)
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            // РќР°СЃС‚СЂРѕР№РєРё SSL (Р°РЅР°Р»РѕРіРёС‡РЅРѕ РјРµС‚РѕРґСѓ downloadImage)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_ALLOW_BEAST | CURLSSLOPT_NO_REVOKE);

            // HTTP Basic Authentication, РµСЃР»Рё СѓРєР°Р·Р°РЅС‹ СѓС‡РµС‚РЅС‹Рµ РґР°РЅРЅС‹Рµ
            if (! empty($username) && ! empty($password)) {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
            }

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
                    $errorMessage .= ' (РєРѕРґ РѕС€РёР±РєРё: '.$curlErrno.')';
                }

                Log::error('РћС€РёР±РєР° cURL РїСЂРё Р·Р°РіСЂСѓР·РєРµ YML С„РёРґР°', [
                    'url' => $url,
                    'curl_error' => $curlError,
                    'curl_errno' => $curlErrno,
                    'http_code' => $httpCode,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'РћС€РёР±РєР° Р·Р°РіСЂСѓР·РєРё С„РёРґР°: '.$errorMessage,
                ], 500);
            }

            if ($httpCode !== 200) {
                return response()->json([
                    'success' => false,
                    'error' => "РћС€РёР±РєР° Р·Р°РіСЂСѓР·РєРё С„РёРґР°: HTTP {$httpCode}",
                ], $httpCode);
            }

            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ СЌС‚Рѕ РґРµР№СЃС‚РІРёС‚РµР»СЊРЅРѕ XML
            if (empty($xmlContent)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Р¤РёРґ РїСѓСЃС‚',
                ], 400);
            }

            // РџСЂРѕРІРµСЂСЏРµРј Р±Р°Р·РѕРІСѓСЋ СЃС‚СЂСѓРєС‚СѓСЂСѓ YML
            if (strpos($xmlContent, 'yml_catalog') === false && strpos($xmlContent, '<?xml') === false) {
                return response()->json([
                    'success' => false,
                    'error' => 'Р¤Р°Р№Р» РЅРµ СЏРІР»СЏРµС‚СЃСЏ РІР°Р»РёРґРЅС‹Рј YML С„РёРґРѕРј',
                ], 400);
            }

            // РџР°СЂСЃРёРј YML РЅР° СЃРµСЂРІРµСЂРµ
            try {
                // РћС‚РєР»СЋС‡Р°РµРј РѕС€РёР±РєРё libxml РґР»СЏ Р±РѕР»РµРµ РіРёР±РєРѕРіРѕ РїР°СЂСЃРёРЅРіР°
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($xmlContent);

                if ($xml === false) {
                    $errors = libxml_get_errors();
                    libxml_clear_errors();
                    $errorMessages = array_map(function ($error) {
                        return trim($error->message);
                    }, $errors);

                    return response()->json([
                        'success' => false,
                        'error' => 'РћС€РёР±РєР° РїР°СЂСЃРёРЅРіР° XML: '.implode('; ', array_slice($errorMessages, 0, 3)),
                    ], 400);
                }

                // РџСЂРѕРІРµСЂСЏРµРј СЃС‚СЂСѓРєС‚СѓСЂСѓ
                if (! isset($xml->shop)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'РќРµ РЅР°Р№РґРµРЅ СЌР»РµРјРµРЅС‚ shop РІ YML С„РёРґРµ',
                    ], 400);
                }

                // РР·РІР»РµРєР°РµРј РґР°РЅРЅС‹Рµ Рѕ РјР°РіР°Р·РёРЅРµ
                $shopData = [
                    'name' => (string) ($xml->shop->name ?? ''),
                    'company' => (string) ($xml->shop->company ?? ''),
                    'url' => (string) ($xml->shop->url ?? ''),
                ];

                // РР·РІР»РµРєР°РµРј РІР°Р»СЋС‚С‹
                $currencies = [];
                if (isset($xml->shop->currencies->currency)) {
                    foreach ($xml->shop->currencies->currency as $currency) {
                        $currencies[] = [
                            'id' => (string) $currency['id'],
                            'rate' => (float) ($currency['rate'] ?? 1),
                        ];
                    }
                }

                // РР·РІР»РµРєР°РµРј РєР°С‚РµРіРѕСЂРёРё
                $categories = [];
                if (isset($xml->shop->categories->category)) {
                    foreach ($xml->shop->categories->category as $category) {
                        $categories[] = [
                            'id' => (string) $category['id'],
                            'name' => trim((string) $category),
                            'parentId' => isset($category['parentId']) ? (string) $category['parentId'] : null,
                        ];
                    }
                }

                // РР·РІР»РµРєР°РµРј С‚РѕРІР°СЂС‹ (offers)
                $offers = [];
                if (isset($xml->shop->offers->offer)) {
                    foreach ($xml->shop->offers->offer as $offer) {
                        $offerData = [
                            'id' => (string) $offer['id'],
                            'available' => (string) ($offer['available'] ?? 'false') === 'true',
                        ];

                        // РћСЃРЅРѕРІРЅС‹Рµ РїРѕР»СЏ
                        if (isset($offer->name) && (string) $offer->name !== '') {
                            $offerData['name'] = trim((string) $offer->name);
                        }
                        if (isset($offer->price) && (string) $offer->price !== '') {
                            $offerData['price'] = (float) $offer->price;
                        }
                        if (isset($offer->currencyId) && (string) $offer->currencyId !== '') {
                            $offerData['currencyId'] = (string) $offer->currencyId;
                        }
                        if (isset($offer->categoryId) && (string) $offer->categoryId !== '') {
                            $offerData['categoryId'] = (string) $offer->categoryId;
                        }
                        if (isset($offer->url) && (string) $offer->url !== '') {
                            $offerData['url'] = (string) $offer->url;
                        }
                        if (isset($offer->vendor) && (string) $offer->vendor !== '') {
                            $offerData['vendor'] = (string) $offer->vendor;
                        }
                        if (isset($offer->model) && (string) $offer->model !== '') {
                            $offerData['model'] = (string) $offer->model;
                        }
                        if (isset($offer->description) && (string) $offer->description !== '') {
                            $offerData['description'] = trim((string) $offer->description);
                        }
                        if (isset($offer->vendorCode) && (string) $offer->vendorCode !== '') {
                            $offerData['vendorCode'] = (string) $offer->vendorCode;
                        }
                        if (isset($offer->barcode) && (string) $offer->barcode !== '') {
                            $offerData['barcode'] = (string) $offer->barcode;
                        }
                        if (isset($offer->oldprice) && (string) $offer->oldprice !== '') {
                            $offerData['oldprice'] = (float) $offer->oldprice;
                        }
                        if (isset($offer->sales_notes) && (string) $offer->sales_notes !== '') {
                            $offerData['sales_notes'] = (string) $offer->sales_notes;
                        }
                        if (isset($offer->manufacturer_warranty)) {
                            $offerData['manufacturer_warranty'] = (string) $offer->manufacturer_warranty === 'true';
                        }
                        if (isset($offer->country_of_origin) && (string) $offer->country_of_origin !== '') {
                            $offerData['country_of_origin'] = (string) $offer->country_of_origin;
                        }
                        if (isset($offer->adult)) {
                            $offerData['adult'] = (string) $offer->adult === 'true';
                        }

                        // РР·РѕР±СЂР°Р¶РµРЅРёСЏ (РјРѕР¶РµС‚ Р±С‹С‚СЊ РЅРµСЃРєРѕР»СЊРєРѕ)
                        $pictures = [];
                        if (isset($offer->picture)) {
                            // Р’ SimpleXML picture РјРѕР¶РµС‚ Р±С‹С‚СЊ РѕРґРЅРёРј СЌР»РµРјРµРЅС‚РѕРј РёР»Рё РєРѕР»Р»РµРєС†РёРµР№
                            // РСЃРїРѕР»СЊР·СѓРµРј count() РґР»СЏ РїСЂРѕРІРµСЂРєРё РєРѕР»РёС‡РµСЃС‚РІР° СЌР»РµРјРµРЅС‚РѕРІ
                            $pictureCount = count($offer->picture);
                            if ($pictureCount > 1) {
                                // РќРµСЃРєРѕР»СЊРєРѕ РёР·РѕР±СЂР°Р¶РµРЅРёР№ - РёС‚РµСЂРёСЂСѓРµРј
                                foreach ($offer->picture as $picture) {
                                    $pictureUrl = trim((string) $picture);
                                    if ($pictureUrl) {
                                        $pictures[] = $pictureUrl;
                                    }
                                }
                            } else {
                                // РћРґРЅРѕ РёР·РѕР±СЂР°Р¶РµРЅРёРµ
                                $pictureUrl = trim((string) $offer->picture);
                                if ($pictureUrl) {
                                    $pictures[] = $pictureUrl;
                                }
                            }
                        }
                        // Р¤РѕСЂРјРёСЂСѓРµРј РїРѕР»Рµ picture СЃРѕРіР»Р°СЃРЅРѕ РёРЅС‚РµСЂС„РµР№СЃСѓ YMLOffer
                        if (count($pictures) === 1) {
                            $offerData['picture'] = $pictures[0];
                        } elseif (count($pictures) > 1) {
                            $offerData['picture'] = $pictures;
                        }

                        // РџР°СЂР°РјРµС‚СЂС‹ (РјРѕР¶РµС‚ Р±С‹С‚СЊ РЅРµСЃРєРѕР»СЊРєРѕ)
                        $params = [];
                        if (isset($offer->param)) {
                            // Р’ SimpleXML param РјРѕР¶РµС‚ Р±С‹С‚СЊ РѕРґРЅРёРј СЌР»РµРјРµРЅС‚РѕРј РёР»Рё РєРѕР»Р»РµРєС†РёРµР№
                            $paramCount = count($offer->param);
                            if ($paramCount > 1) {
                                // РќРµСЃРєРѕР»СЊРєРѕ РїР°СЂР°РјРµС‚СЂРѕРІ - РёС‚РµСЂРёСЂСѓРµРј
                                foreach ($offer->param as $param) {
                                    $paramName = (string) ($param['name'] ?? '');
                                    $paramValue = trim((string) $param);
                                    if ($paramName && $paramValue) {
                                        $params[] = [
                                            'name' => $paramName,
                                            'value' => $paramValue,
                                        ];
                                    }
                                }
                            } else {
                                // РћРґРёРЅ РїР°СЂР°РјРµС‚СЂ
                                $paramName = (string) ($offer->param['name'] ?? '');
                                $paramValue = trim((string) $offer->param);
                                if ($paramName && $paramValue) {
                                    $params[] = [
                                        'name' => $paramName,
                                        'value' => $paramValue,
                                    ];
                                }
                            }
                        }
                        if (! empty($params)) {
                            $offerData['params'] = $params;
                        }

                        $offers[] = $offerData;
                    }
                }

                // Р¤РѕСЂРјРёСЂСѓРµРј СЂРµР·СѓР»СЊС‚Р°С‚
                $ymlData = [
                    'shop' => $shopData,
                    'currencies' => $currencies,
                    'categories' => $categories,
                    'offers' => $offers,
                    'date' => isset($xml['date']) ? (string) $xml['date'] : null,
                ];

                // Р’РѕР·РІСЂР°С‰Р°РµРј СЂР°СЃРїР°СЂСЃРµРЅРЅС‹Рµ РґР°РЅРЅС‹Рµ
                return response()->json([
                    'success' => true,
                    'data' => $ymlData,
                ]);

            } catch (\Exception $parseError) {
                // Р•СЃР»Рё РїР°СЂСЃРёРЅРі РЅРµ СѓРґР°Р»СЃСЏ, РІРѕР·РІСЂР°С‰Р°РµРј XML РґР»СЏ РїР°СЂСЃРёРЅРіР° РЅР° РєР»РёРµРЅС‚Рµ
                return response()->json([
                    'success' => true,
                    'data' => [
                        'xml' => $xmlContent,
                        'url' => $url,
                        'size' => strlen($xmlContent),
                    ],
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'РћС€РёР±РєР° РїСЂРё Р·Р°РіСЂСѓР·РєРµ С„РёРґР°: '.$e->getMessage(),
                'message' => 'РћС€РёР±РєР° РїСЂРё Р·Р°РіСЂСѓР·РєРµ С„РёРґР°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Р—Р°РіСЂСѓР·РєР° YML/XML С„Р°Р№Р»Р° РЅР° СЃРµСЂРІРµСЂ РґР»СЏ РїРѕСЃР»РµРґСѓСЋС‰РµРіРѕ РїР°СЂСЃРёРЅРіР°
     */
    public function uploadYMLFile(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xml,yml|max:51200', // 50MB max
            ]);

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();

            // РџСЂРѕРІРµСЂСЏРµРј СЂР°СЃС€РёСЂРµРЅРёРµ
            if (! in_array(strtolower($extension), ['xml', 'yml'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Р¤Р°Р№Р» РґРѕР»Р¶РµРЅ РёРјРµС‚СЊ СЂР°СЃС€РёСЂРµРЅРёРµ .xml РёР»Рё .yml',
                ], 400);
            }

            // Р“РµРЅРµСЂРёСЂСѓРµРј СѓРЅРёРєР°Р»СЊРЅРѕРµ РёРјСЏ С„Р°Р№Р»Р°
            $fileName = 'yml_upload_'.time().'_'.Str::random(8).'.'.$extension;
            $filePath = storage_path('app/temp/'.$fileName);

            // РЎРѕР·РґР°РµРј РґРёСЂРµРєС‚РѕСЂРёСЋ РµСЃР»Рё РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚
            $directory = dirname($filePath);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // РЎРѕС…СЂР°РЅСЏРµРј С„Р°Р№Р»
            $file->move($directory, basename($filePath));

            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ С„Р°Р№Р» СЃРѕС…СЂР°РЅРµРЅ
            if (! file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРѕС…СЂР°РЅРёС‚СЊ С„Р°Р№Р»',
                ], 500);
            }

            // Р’РѕР·РІСЂР°С‰Р°РµРј URL РґР»СЏ РґРѕСЃС‚СѓРїР° Рє С„Р°Р№Р»Сѓ
            $fileUrl = url('/api/admin/shop/goods/temp-yml-file/'.$fileName);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => $fileUrl,
                    'filename' => $fileName,
                    'original_name' => $originalName,
                    'size' => filesize($filePath),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'РћС€РёР±РєР° РїСЂРё Р·Р°РіСЂСѓР·РєРµ С„Р°Р№Р»Р°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РЈРґР°Р»РµРЅРёРµ РІСЂРµРјРµРЅРЅРѕРіРѕ YML С„Р°Р№Р»Р°
     */
    public function deleteTempYMLFile(Request $request, string $filename): JsonResponse
    {
        try {
            // РџСЂРѕРІРµСЂСЏРµРј С„РѕСЂРјР°С‚ РёРјРµРЅРё С„Р°Р№Р»Р° РґР»СЏ Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё
            if (! preg_match('/^yml_upload_\d+_[a-zA-Z0-9]+\.(xml|yml)$/', $filename)) {
                return response()->json([
                    'success' => false,
                    'error' => 'РќРµРєРѕСЂСЂРµРєС‚РЅРѕРµ РёРјСЏ С„Р°Р№Р»Р°',
                ], 400);
            }

            $filePath = storage_path('app/temp/'.$filename);

            if (file_exists($filePath)) {
                unlink($filePath);

                return response()->json([
                    'success' => true,
                    'message' => 'Р¤Р°Р№Р» СѓРґР°Р»РµРЅ',
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Р¤Р°Р№Р» РЅРµ РЅР°Р№РґРµРЅ',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'РћС€РёР±РєР° РїСЂРё СѓРґР°Р»РµРЅРёРё С„Р°Р№Р»Р°: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РІСЂРµРјРµРЅРЅС‹Р№ YML С„Р°Р№Р»
     */
    public function getTempYMLFile(Request $request, string $filename)
    {
        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°РІС‚РѕСЂРёР·РѕРІР°РЅ С‡РµСЂРµР· С‚РѕРєРµРЅ (РґР»СЏ API Р·Р°РїСЂРѕСЃРѕРІ)
        if (! $request->user()) {
            abort(403, 'Unauthorized');
        }

        // РџСЂРѕРІРµСЂСЏРµРј С„РѕСЂРјР°С‚ РёРјРµРЅРё С„Р°Р№Р»Р° РґР»СЏ Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё
        if (! preg_match('/^yml_upload_\d+_[a-zA-Z0-9]+\.(xml|yml)$/', $filename)) {
            abort(404, 'File not found');
        }

        $filePath = storage_path('app/temp/'.$filename);

        if (! file_exists($filePath)) {
            abort(404, 'File not found');
        }

        // Р§РёС‚Р°РµРј С„Р°Р№Р» Рё РІРѕР·РІСЂР°С‰Р°РµРј РєР°Рє С‚РµРєСЃС‚
        $content = file_get_contents($filePath);

        return response($content, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃС‚Р°С‚РёСЃС‚РёРєСѓ РєРѕР»РёС‡РµСЃС‚РІР° С‚РѕРІР°СЂРѕРІ РїРѕ РєР°С‚РµРіРѕСЂРёСЏРј
     */
    public function getCategoriesStats(Request $request): JsonResponse
    {
        try {
            // РџРѕР»СѓС‡Р°РµРј РєРѕР»РёС‡РµСЃС‚РІРѕ С‚РѕРІР°СЂРѕРІ РґР»СЏ РєР°Р¶РґРѕР№ РєР°С‚РµРіРѕСЂРёРё РѕРґРЅРёРј SQL Р·Р°РїСЂРѕСЃРѕРј
            $stats = DB::table('shop_good_categories')
                ->join('shop_goods', 'shop_good_categories.good_id', '=', 'shop_goods.id')
                ->where('shop_goods.is_active', true)
                ->select('shop_good_categories.category_id', DB::raw('COUNT(*) as products_count'))
                ->groupBy('shop_good_categories.category_id')
                ->get()
                ->pluck('products_count', 'category_id')
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ СЃС‚Р°С‚РёСЃС‚РёРєРё: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃРїРёСЃРѕРє РІСЃРµС… СѓРЅРёРєР°Р»СЊРЅС‹С… РЅР°Р·РІР°РЅРёР№ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє
     */
    public function getCharacteristicsList(): JsonResponse
    {
        try {
            // РџРѕР»СѓС‡Р°РµРј РІСЃРµ СѓРЅРёРєР°Р»СЊРЅС‹Рµ РЅР°Р·РІР°РЅРёСЏ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє РёР· С‚Р°Р±Р»РёС†С‹ shop_properties
            // С‡РµСЂРµР· СЃРІСЏР·СЊ СЃ shop_good_properties
            $characteristics = DB::table('shop_properties')
                ->join('shop_good_properties', 'shop_properties.id', '=', 'shop_good_properties.property_id')
                ->select('shop_properties.name')
                ->distinct()
                ->whereNotNull('shop_properties.name')
                ->where('shop_properties.name', '!=', '')
                ->orderBy('shop_properties.name')
                ->pluck('shop_properties.name')
                ->map(function ($name) {
                    return [
                        'id' => $name,
                        'name' => $name,
                    ];
                })
                ->values()
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $characteristics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ СЃРїРёСЃРєР° С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРµСЂРµРЅРѕСЃ РґР°РЅРЅС‹С… РјРµР¶РґСѓ С‚РѕРІР°СЂР°РјРё
     */
    public function transferData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source_good_id' => 'required|exists:shop_goods,id',
            'target_good_id' => 'required|exists:shop_goods,id|different:source_good_id',
            'transfer' => 'required|array',
            'transfer.description' => 'nullable|boolean',
            'transfer.short_description' => 'nullable|boolean',
            'transfer.variations' => 'nullable|boolean',
            'transfer.images' => 'nullable|boolean',
            'delete_source' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $sourceGood = ShopGood::with(['variations', 'images'])->findOrFail($request->source_good_id);
            $targetGood = ShopGood::with(['variations', 'images'])->findOrFail($request->target_good_id);
            $transfer = $request->transfer;
            $deleteSource = $request->boolean('delete_source', false);

            $transferredItems = [];

            // Р¤СѓРЅРєС†РёСЏ РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє РІР°СЂРёР°С†РёР№ (РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РІ РЅРµСЃРєРѕР»СЊРєРёС… РјРµСЃС‚Р°С…)
            $compareVariationAttributes = function ($attrs1, $attrs2) {
                if (count($attrs1) !== count($attrs2)) {
                    return false;
                }

                // РЎРѕСЂС‚РёСЂСѓРµРј РїРѕ attribute_id Рё value_id РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ
                $sorted1 = collect($attrs1)->sortBy(function ($attr) {
                    return $attr['attribute_id'].'_'.$attr['value_id'];
                })->values()->toArray();

                $sorted2 = collect($attrs2)->sortBy(function ($attr) {
                    return $attr['attribute_id'].'_'.$attr['value_id'];
                })->values()->toArray();

                return json_encode($sorted1) === json_encode($sorted2);
            };

            // РџРµСЂРµРЅРѕСЃ РѕРїРёСЃР°РЅРёСЏ
            if (! empty($transfer['description'])) {
                $targetGood->description = $sourceGood->description;
                $transferredItems[] = 'РѕРїРёСЃР°РЅРёРµ';
            }

            // РџРµСЂРµРЅРѕСЃ РєСЂР°С‚РєРѕРіРѕ РѕРїРёСЃР°РЅРёСЏ
            if (! empty($transfer['short_description'])) {
                $targetGood->short_description = $sourceGood->short_description;
                $transferredItems[] = 'РєСЂР°С‚РєРѕРµ РѕРїРёСЃР°РЅРёРµ';
            }

            // РЎРѕС…СЂР°РЅСЏРµРј РёР·РјРµРЅРµРЅРёСЏ РІ РѕСЃРЅРѕРІРЅС‹С… РїРѕР»СЏС…
            if (! empty($transfer['description']) || ! empty($transfer['short_description'])) {
                $targetGood->save();
            }

            // РџРµСЂРµРЅРѕСЃ РІР°СЂРёР°С†РёР№
            if (! empty($transfer['variations']) && $sourceGood->variations) {
                $sourceVariations = $sourceGood->variations;

                // Р—Р°РіСЂСѓР¶Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РІСЃРµС… РІР°СЂРёР°С†РёР№ РёСЃС‚РѕС‡РЅРёРєР°
                $sourceVariationIds = $sourceVariations->pluck('id')->toArray();
                $sourceVariationAttributes = [];

                if (! empty($sourceVariationIds)) {
                    $attrsRows = DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $sourceVariationIds)
                        ->select(
                            'vav.variation_id',
                            'a.id as attribute_id',
                            'a.name as attribute_name',
                            'av.id as value_id',
                            'av.value as value_value'
                        )
                        ->get();

                    foreach ($attrsRows as $row) {
                        if (! isset($sourceVariationAttributes[$row->variation_id])) {
                            $sourceVariationAttributes[$row->variation_id] = [];
                        }
                        $sourceVariationAttributes[$row->variation_id][] = [
                            'attribute_id' => $row->attribute_id,
                            'attribute_name' => $row->attribute_name,
                            'value_id' => $row->value_id,
                            'value' => $row->value_value,
                        ];
                    }
                }

                // Р—Р°РіСЂСѓР¶Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РґР»СЏ РІСЃРµС… РІР°СЂРёР°С†РёР№ РїРѕР»СѓС‡Р°С‚РµР»СЏ
                $targetVariations = $targetGood->variations;
                $targetVariationIds = $targetVariations->pluck('id')->toArray();
                $targetVariationAttributes = [];

                if (! empty($targetVariationIds)) {
                    $attrsRows = DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $targetVariationIds)
                        ->select(
                            'vav.variation_id',
                            'a.id as attribute_id',
                            'a.name as attribute_name',
                            'av.id as value_id',
                            'av.value as value_value'
                        )
                        ->get();

                    foreach ($attrsRows as $row) {
                        if (! isset($targetVariationAttributes[$row->variation_id])) {
                            $targetVariationAttributes[$row->variation_id] = [];
                        }
                        $targetVariationAttributes[$row->variation_id][] = [
                            'attribute_id' => $row->attribute_id,
                            'attribute_name' => $row->attribute_name,
                            'value_id' => $row->value_id,
                            'value' => $row->value_value,
                        ];
                    }
                }

                $createdVariations = 0;
                $skippedVariations = 0;

                // РЎРѕР·РґР°РµРј РІР°СЂРёР°С†РёРё РґР»СЏ РїРѕР»СѓС‡Р°С‚РµР»СЏ
                foreach ($sourceVariations as $sourceVariation) {
                    $sourceAttrs = $sourceVariationAttributes[$sourceVariation->id] ?? [];

                    // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё СѓР¶Рµ РІР°СЂРёР°С†РёСЏ СЃ С‚Р°РєРёРјРё Р¶Рµ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєР°РјРё
                    $duplicateFound = false;
                    foreach ($targetVariations as $targetVariation) {
                        $targetAttrs = $targetVariationAttributes[$targetVariation->id] ?? [];
                        if ($compareVariationAttributes($sourceAttrs, $targetAttrs)) {
                            $duplicateFound = true;
                            $skippedVariations++;
                            break;
                        }
                    }

                    if (! $duplicateFound) {
                        // РЎРѕР·РґР°РµРј РЅРѕРІСѓСЋ РІР°СЂРёР°С†РёСЋ
                        $newVariation = \App\Models\ShopGoodVariation::create([
                            'good_id' => $targetGood->id,
                            'name' => $sourceVariation->name,
                            'sku' => $sourceVariation->sku,
                            'price' => $sourceVariation->price,
                            'sale_price' => $sourceVariation->sale_price,
                            'demping_price' => $sourceVariation->demping_price,
                            'show_demping' => $sourceVariation->show_demping,
                            'stock_quantity' => $sourceVariation->stock_quantity,
                            'remote_stock_quantity' => $sourceVariation->remote_stock_quantity,
                            'is_active' => $sourceVariation->is_active,
                        ]);

                        // РљРѕРїРёСЂСѓРµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёРё
                        if (! empty($sourceAttrs)) {
                            foreach ($sourceAttrs as $attr) {
                                DB::table('shop_variation_attributes_values')->insert([
                                    'variation_id' => $newVariation->id,
                                    'attribute_value_id' => $attr['value_id'],
                                ]);
                            }
                        }

                        $createdVariations++;
                    }
                }

                $transferredItems[] = "РІР°СЂРёР°С†РёРё (СЃРѕР·РґР°РЅРѕ: {$createdVariations}, РїСЂРѕРїСѓС‰РµРЅРѕ: {$skippedVariations})";
            }

            // РџРµСЂРµРЅРѕСЃ РёР·РѕР±СЂР°Р¶РµРЅРёР№ (РїРѕСЃР»Рµ РѕРїРµСЂР°С†РёР№ СЃ РІР°СЂРёР°С†РёСЏРјРё)
            if (! empty($transfer['images'])) {
                // Р—Р°РіСЂСѓР¶Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё СЃ Р°С‚СЂРёР±СѓС‚Р°РјРё РґР»СЏ РѕР±РѕРёС… С‚РѕРІР°СЂРѕРІ
                $sourceVariations = ShopGood::with(['variations'])->find($sourceGood->id)->variations;
                $targetVariations = ShopGood::with(['variations'])->find($targetGood->id)->variations;

                // Р—Р°РіСЂСѓР¶Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёР№ РёСЃС‚РѕС‡РЅРёРєР°
                $sourceVariationIds = $sourceVariations->pluck('id')->toArray();
                $sourceVariationAttributesMap = [];

                if (! empty($sourceVariationIds)) {
                    $attrsRows = DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $sourceVariationIds)
                        ->select(
                            'vav.variation_id',
                            'a.id as attribute_id',
                            'av.id as value_id'
                        )
                        ->get();

                    foreach ($attrsRows as $row) {
                        if (! isset($sourceVariationAttributesMap[$row->variation_id])) {
                            $sourceVariationAttributesMap[$row->variation_id] = [];
                        }
                        $sourceVariationAttributesMap[$row->variation_id][] = [
                            'attribute_id' => $row->attribute_id,
                            'value_id' => $row->value_id,
                        ];
                    }
                }

                // Р—Р°РіСЂСѓР¶Р°РµРј Р°С‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёР№ РїРѕР»СѓС‡Р°С‚РµР»СЏ
                $targetVariationIds = $targetVariations->pluck('id')->toArray();
                $targetVariationAttributesMap = [];

                if (! empty($targetVariationIds)) {
                    $attrsRows = DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $targetVariationIds)
                        ->select(
                            'vav.variation_id',
                            'a.id as attribute_id',
                            'av.id as value_id'
                        )
                        ->get();

                    foreach ($attrsRows as $row) {
                        if (! isset($targetVariationAttributesMap[$row->variation_id])) {
                            $targetVariationAttributesMap[$row->variation_id] = [];
                        }
                        $targetVariationAttributesMap[$row->variation_id][] = [
                            'attribute_id' => $row->attribute_id,
                            'value_id' => $row->value_id,
                        ];
                    }
                }

                // Р¤СѓРЅРєС†РёСЏ РґР»СЏ РїРѕРёСЃРєР° СЃРѕРѕС‚РІРµС‚СЃС‚РІСѓСЋС‰РµР№ РІР°СЂРёР°С†РёРё РїРѕР»СѓС‡Р°С‚РµР»СЏ РїРѕ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєР°Рј
                $findMatchingVariation = function ($sourceAttrs, $targetVariations, $targetVariationAttributesMap) use ($compareVariationAttributes) {
                    foreach ($targetVariations as $targetVariation) {
                        $targetAttrs = $targetVariationAttributesMap[$targetVariation->id] ?? [];
                        if ($compareVariationAttributes($sourceAttrs, $targetAttrs)) {
                            return $targetVariation->id;
                        }
                    }

                    return null;
                };

                // РџРµСЂРµРЅРѕСЃРёРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                $sourceMainImages = DB::table('shop_good_images')
                    ->where('good_id', $sourceGood->id)
                    ->whereNull('variation_id')
                    ->get();

                foreach ($sourceMainImages as $image) {
                    DB::table('shop_good_images')
                        ->where('id', $image->id)
                        ->update([
                            'good_id' => $targetGood->id,
                            'variation_id' => null,
                        ]);
                }

                // РџРµСЂРµРЅРѕСЃРёРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёР№
                foreach ($sourceVariations as $sourceVariation) {
                    $sourceAttrs = $sourceVariationAttributesMap[$sourceVariation->id] ?? [];
                    $matchingTargetVariationId = $findMatchingVariation($sourceAttrs, $targetVariations, $targetVariationAttributesMap);

                    $sourceVariationImages = DB::table('shop_good_images')
                        ->where('variation_id', $sourceVariation->id)
                        ->get();

                    foreach ($sourceVariationImages as $image) {
                        DB::table('shop_good_images')
                            ->where('id', $image->id)
                            ->update([
                                'good_id' => $targetGood->id,
                                'variation_id' => $matchingTargetVariationId,
                            ]);
                    }
                }

                $transferredItems[] = 'РёР·РѕР±СЂР°Р¶РµРЅРёСЏ';
            }

            // РЈРґР°Р»РµРЅРёРµ РёСЃС…РѕРґРЅРѕРіРѕ С‚РѕРІР°СЂР°, РµСЃР»Рё СѓРєР°Р·Р°РЅРѕ
            if ($deleteSource) {
                $this->logAudit($sourceGood, 'deleted', $sourceGood->toArray(), null);
                $sourceGood->delete();
            }

            DB::commit();

            $message = 'Р”Р°РЅРЅС‹Рµ СѓСЃРїРµС€РЅРѕ РїРµСЂРµРЅРµСЃРµРЅС‹: '.implode(', ', $transferredItems);
            if ($deleteSource) {
                $message .= '. РСЃС…РѕРґРЅС‹Р№ С‚РѕРІР°СЂ СѓРґР°Р»РµРЅ.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'transferred' => $transferredItems,
                    'delete_source' => $deleteSource,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('РћС€РёР±РєР° РїРµСЂРµРЅРѕСЃР° РґР°РЅРЅС‹С… С‚РѕРІР°СЂРѕРІ: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРµСЂРµРЅРѕСЃР° РґР°РЅРЅС‹С…: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РР·РјРµРЅРёС‚СЊ РІР°СЂРёР°С†РёСЋ - РїРµСЂРµРЅРѕСЃ РґР°РЅРЅС‹С… РёР· С‚РѕРІР°СЂРѕРІ Р±РµР· РІР°СЂРёР°С†РёР№ РІ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР° СЃ РІР°СЂРёР°С†РёСЏРјРё
     */
    public function changeVariation(Request $request): JsonResponse
    {

        // РЎРЅР°С‡Р°Р»Р° РїСЂРѕРІРµСЂРёРј РѕСЃРЅРѕРІРЅС‹Рµ РїРѕР»СЏ
        $basicValidator = Validator::make($request->all(), [
            'good_with_variations_id' => 'required|exists:shop_goods,id',
            'goods_without_variations' => 'required|array|min:1',
            'goods_without_variations.*.update_description' => 'nullable|boolean',
            'goods_without_variations.*.update_short_description' => 'nullable|boolean',
            'goods_without_variations.*.update_slug' => 'nullable|boolean',
            'goods_without_variations.*.update_prices' => 'nullable|boolean',
            'goods_without_variations.*.selected_image_ids' => 'nullable|array',
            'goods_without_variations.*.selected_image_ids.*' => 'exists:shop_good_images,id',
        ]);

        if ($basicValidator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё РѕСЃРЅРѕРІРЅС‹С… РїРѕР»РµР№',
                'errors' => $basicValidator->errors(),
            ], 422);
        }

        // РўРµРїРµСЂСЊ РїСЂРѕРІРµСЂРёРј СЃСѓС‰РµСЃС‚РІРѕРІР°РЅРёРµ С‚РѕРІР°СЂРѕРІ Рё РІР°СЂРёР°С†РёР№
        $validator = Validator::make($request->all(), [
            'goods_without_variations.*.id' => 'required|exists:shop_goods,id',
        ]);

        // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РІР°Р»РёРґР°С†РёСЏ РґР»СЏ variation_ids РёР»Рё variation_id
        $validator->after(function ($validator) use ($request) {
            $goodsWithoutVariations = $request->input('goods_without_variations', []);

            foreach ($goodsWithoutVariations as $index => $goodData) {
                if (! isset($goodData['variation_ids']) && ! isset($goodData['variation_id'])) {
                    $validator->errors()->add("goods_without_variations.{$index}.variation_ids", 'РџРѕР»Рµ variation_ids РёР»Рё variation_id РѕР±СЏР·Р°С‚РµР»СЊРЅРѕ РґР»СЏ Р·Р°РїРѕР»РЅРµРЅРёСЏ.');
                }

                if (isset($goodData['variation_ids'])) {
                    if (! is_array($goodData['variation_ids']) || empty($goodData['variation_ids'])) {
                        $validator->errors()->add("goods_without_variations.{$index}.variation_ids", 'РџРѕР»Рµ variation_ids РґРѕР»Р¶РЅРѕ Р±С‹С‚СЊ РЅРµРїСѓСЃС‚С‹Рј РјР°СЃСЃРёРІРѕРј.');
                    } else {
                        foreach ($goodData['variation_ids'] as $varIndex => $variationId) {
                            if (! is_numeric($variationId) || ! \App\Models\ShopGoodVariation::where('id', $variationId)->exists()) {
                                $validator->errors()->add("goods_without_variations.{$index}.variation_ids.{$varIndex}", 'РЈРєР°Р·Р°РЅРЅР°СЏ РІР°СЂРёР°С†РёСЏ РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚.');
                            }
                        }
                    }
                }

                if (isset($goodData['variation_id']) && ! isset($goodData['variation_ids'])) {
                    if (! is_numeric($goodData['variation_id']) || ! \App\Models\ShopGoodVariation::where('id', $goodData['variation_id'])->exists()) {
                        $validator->errors()->add("goods_without_variations.{$index}.variation_id", 'РЈРєР°Р·Р°РЅРЅР°СЏ РІР°СЂРёР°С†РёСЏ РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚.');
                    }
                }
            }
        });

        // Р¤РёР»СЊС‚СЂСѓРµРј С‚РѕР»СЊРєРѕ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ С‚РѕРІР°СЂС‹
        $goodsWithoutVariations = $request->input('goods_without_variations', []);
        $validGoodsWithoutVariations = [];

        foreach ($goodsWithoutVariations as $goodData) {
            $goodId = $goodData['id'];
            if (\App\Models\ShopGood::where('id', $goodId)->exists()) {
                $validGoodsWithoutVariations[] = $goodData;
            }
        }

        // РџСЂРѕРІРµСЂСЏРµРј, РѕСЃС‚Р°Р»РёСЃСЊ Р»Рё С‚РѕРІР°СЂС‹ РїРѕСЃР»Рµ С„РёР»СЊС‚СЂР°С†РёРё
        if (empty($validGoodsWithoutVariations)) {
            return response()->json([
                'success' => false,
                'message' => 'РќРё РѕРґРёРЅ РёР· СѓРєР°Р·Р°РЅРЅС‹С… С‚РѕРІР°СЂРѕРІ РЅРµ РЅР°Р№РґРµРЅ',
            ], 422);
        }

        // РћР±РЅРѕРІР»СЏРµРј РґР°РЅРЅС‹Рµ Р·Р°РїСЂРѕСЃР° РѕС‚С„РёР»СЊС‚СЂРѕРІР°РЅРЅС‹РјРё С‚РѕРІР°СЂР°РјРё
        $request->merge(['goods_without_variations' => $validGoodsWithoutVariations]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё РїРѕСЃР»Рµ С„РёР»СЊС‚СЂР°С†РёРё С‚РѕРІР°СЂРѕРІ',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $goodWithVariations = ShopGood::with(['variations'])->findOrFail($request->good_with_variations_id);
            $goodsWithoutVariations = $validGoodsWithoutVariations; // РСЃРїРѕР»СЊР·СѓРµРј РѕС‚С„РёР»СЊС‚СЂРѕРІР°РЅРЅС‹Р№ РјР°СЃСЃРёРІ

            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ С‚РѕРІР°СЂ РґРµР№СЃС‚РІРёС‚РµР»СЊРЅРѕ РёРјРµРµС‚ РІР°СЂРёР°С†РёРё
            if ($goodWithVariations->variations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Р’С‹Р±СЂР°РЅРЅС‹Р№ С‚РѕРІР°СЂ РЅРµ РёРјРµРµС‚ РІР°СЂРёР°С†РёР№',
                ], 422);
            }

            $updatedVariations = [];
            $descriptionUpdateGoodId = null;
            $shortDescriptionUpdateGoodId = null;
            $slugUpdateGoodId = null;
            $transferredImagesInfo = []; // РРЅС„РѕСЂРјР°С†РёСЏ Рѕ РїРµСЂРµРЅРµСЃРµРЅРЅС‹С… РёР·РѕР±СЂР°Р¶РµРЅРёСЏС…

            // РЎРѕС…СЂР°РЅСЏРµРј РґР°РЅРЅС‹Рµ С‚РѕРІР°СЂРѕРІ-РёСЃС‚РѕС‡РЅРёРєРѕРІ РїРµСЂРµРґ РёС… СѓРґР°Р»РµРЅРёРµРј
            $sourceGoodsData = [];
            foreach ($goodsWithoutVariations as $goodData) {
                $goodId = $goodData['id'];
                $good = ShopGood::find($goodId);
                if ($good) {
                    $sourceGoodsData[$goodId] = [
                        'description' => $good->description,
                        'short_description' => $good->short_description,
                        'slug' => $good->slug,
                    ];
                }
            }

            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Р¶РґС‹Р№ С‚РѕРІР°СЂ Р±РµР· РІР°СЂРёР°С†РёР№
            foreach ($goodsWithoutVariations as $index => $goodData) {

                // РџСЂРѕРІРµСЂСЏРµРј РЅР°Р»РёС‡РёРµ variation_ids (РЅРѕРІС‹Р№ С„РѕСЂРјР°С‚) РёР»Рё variation_id (СЃС‚Р°СЂС‹Р№ С„РѕСЂРјР°С‚ РґР»СЏ РѕР±СЂР°С‚РЅРѕР№ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚Рё)
                if (isset($goodData['variation_ids']) && is_array($goodData['variation_ids'])) {
                    $variationIds = $goodData['variation_ids'];
                } elseif (isset($goodData['variation_id'])) {
                    // РћР±СЂР°С‚РЅР°СЏ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚СЊ СЃРѕ СЃС‚Р°СЂС‹Рј С„РѕСЂРјР°С‚РѕРј
                    $variationIds = [$goodData['variation_id']];
                } else {
                    continue;
                }
                $goodId = $goodData['id'];
                $variationIds = $goodData['variation_ids']; // РўРµРїРµСЂСЊ РјР°СЃСЃРёРІ РІР°СЂРёР°С†РёР№
                $updateDescription = $goodData['update_description'] ?? false;
                $updateShortDescription = $goodData['update_short_description'] ?? false;
                $updateSlug = $goodData['update_slug'] ?? false;
                $updatePrices = $goodData['update_prices'] ?? false;
                $selectedImageIds = $goodData['selected_image_ids'] ?? [];

                // Р—Р°РіСЂСѓР¶Р°РµРј С‚РѕРІР°СЂ Р±РµР· РІР°СЂРёР°С†РёР№
                $goodWithoutVariations = ShopGood::with(['images'])->findOrFail($goodId);

                // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ С‚РѕРІР°СЂ РґРµР№СЃС‚РІРёС‚РµР»СЊРЅРѕ Р±РµР· РІР°СЂРёР°С†РёР№
                if ($goodWithoutVariations->variations()->count() > 0) {
                    continue; // РџСЂРѕРїСѓСЃРєР°РµРј С‚РѕРІР°СЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё
                }

                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Р¶РґСѓСЋ РІР°СЂРёР°С†РёСЋ
                foreach ($variationIds as $variationId) {
                    try {
                        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РІР°СЂРёР°С†РёСЏ РїСЂРёРЅР°РґР»РµР¶РёС‚ С‚РѕРІР°СЂСѓ СЃ РІР°СЂРёР°С†РёСЏРјРё
                        $variation = ShopGoodVariation::where('id', $variationId)
                            ->where('good_id', $goodWithVariations->id)
                            ->firstOrFail();

                        // РЎРѕС…СЂР°РЅСЏРµРј РѕСЂРёРіРёРЅР°Р»СЊРЅРѕРµ РЅР°Р·РІР°РЅРёРµ РІР°СЂРёР°С†РёРё РґР»СЏ РёСЃС‚РѕСЂРёРё
                        $originalVariationName = $variation->name;

                        // РћР±РЅРѕРІР»СЏРµРј РґР°РЅРЅС‹Рµ РІР°СЂРёР°С†РёРё РґР°РЅРЅС‹РјРё РёР· С‚РѕРІР°СЂР° Р±РµР· РІР°СЂРёР°С†РёР№
                        // РќР• РїРµСЂРµРЅРѕСЃРёРј РѕСЃС‚Р°С‚РєРё - С‚РѕР»СЊРєРѕ РѕСЃРЅРѕРІРЅС‹Рµ РґР°РЅРЅС‹Рµ Рё С†РµРЅС‹ (РїРѕ РІС‹Р±РѕСЂСѓ)
                        $updateData = [
                            'name' => $goodWithoutVariations->name,
                            'sku' => $goodWithoutVariations->sku,
                        ];

                        // РџРµСЂРµРЅРѕСЃРёРј С†РµРЅС‹ С‚РѕР»СЊРєРѕ РµСЃР»Рё РІС‹Р±СЂР°РЅР° СЃРѕРѕС‚РІРµС‚СЃС‚РІСѓСЋС‰Р°СЏ РѕРїС†РёСЏ
                        if ($updatePrices) {
                            $updateData['price'] = $goodWithoutVariations->price;
                            $updateData['sale_price'] = $goodWithoutVariations->sale_price;
                            $updateData['demping_price'] = $goodWithoutVariations->demping_price;
                        }

                        $variation->update($updateData);

                        $updatedVariations[] = $variation->id;
                    } catch (\Exception $e) {
                        // РџСЂРѕРґРѕР»Р¶Р°РµРј РѕР±СЂР°Р±РѕС‚РєСѓ РґСЂСѓРіРёС… РІР°СЂРёР°С†РёР№
                        continue;
                    }
                }

                // РљРѕРїРёСЂСѓРµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР° РІ РєР°Р¶РґСѓСЋ РІР°СЂРёР°С†РёСЋ
                // Р­С‚Рѕ РїРѕР·РІРѕР»СЏРµС‚ РєР°Р¶РґРѕР№ РІР°СЂРёР°С†РёРё РёРјРµС‚СЊ СЃРІРѕРё СЃРѕР±СЃС‚РІРµРЅРЅС‹Рµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ
                if (! empty($variationIds)) {
                    // РџРѕР»СѓС‡Р°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР° - Р»РёР±Рѕ РІС‹Р±СЂР°РЅРЅС‹Рµ, Р»РёР±Рѕ РІСЃРµ
                    $sourceImagesQuery = DB::table('shop_good_images')
                        ->where('good_id', $goodWithoutVariations->id)
                        ->whereNull('variation_id');

                    if (! empty($selectedImageIds)) {
                        $sourceImagesQuery->whereIn('id', $selectedImageIds);
                    }

                    $sourceImages = $sourceImagesQuery->get();

                    $imagesFound = $sourceImages->count();
                    $totalImagesCopied = 0;

                    // РљРѕРїРёСЂСѓРµРј РєР°Р¶РґРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ РІ РєР°Р¶РґСѓСЋ РІР°СЂРёР°С†РёСЋ
                    foreach ($variationIds as $variationId) {
                        foreach ($sourceImages as $sourceImage) {
                            DB::table('shop_good_images')->insert([
                                'good_id' => null, // РЈР±РёСЂР°РµРј СЃРІСЏР·СЊ СЃ С‚РѕРІР°СЂРѕРј
                                'variation_id' => $variationId, // РџСЂРёРІСЏР·С‹РІР°РµРј Рє РІР°СЂРёР°С†РёРё
                                'file_path' => $sourceImage->file_path,
                                'alt_text' => $sourceImage->alt_text,
                                'is_main' => $sourceImage->is_main,
                                'sort_order' => $sourceImage->sort_order,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $totalImagesCopied++;
                        }
                    }

                    // РЎРѕС…СЂР°РЅСЏРµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ СЃРєРѕРїРёСЂРѕРІР°РЅРЅС‹С… РёР·РѕР±СЂР°Р¶РµРЅРёСЏС…
                    $transferredImagesInfo[] = [
                        'good_id' => $goodWithoutVariations->id,
                        'variation_ids' => $variationIds,
                        'images_found' => $imagesFound,
                        'images_copied' => $totalImagesCopied,
                        'selected_images' => ! empty($selectedImageIds),
                        'note' => ! empty($selectedImageIds)
                            ? 'Р’С‹Р±СЂР°РЅРЅС‹Рµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ СЃРєРѕРїРёСЂРѕРІР°РЅС‹ РІ РєР°Р¶РґСѓСЋ РІР°СЂРёР°С†РёСЋ'
                            : 'Р’СЃРµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ СЃРєРѕРїРёСЂРѕРІР°РЅС‹ РІ РєР°Р¶РґСѓСЋ РІР°СЂРёР°С†РёСЋ',
                    ];
                }

                // Р—Р°РїРѕРјРёРЅР°РµРј С‚РѕРІР°СЂС‹ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ РїРѕР»РµР№ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР°
                if ($updateDescription) {
                    $descriptionUpdateGoodId = $goodWithoutVariations->id;
                }
                if ($updateShortDescription) {
                    $shortDescriptionUpdateGoodId = $goodWithoutVariations->id;
                }
                if ($updateSlug) {
                    $slugUpdateGoodId = $goodWithoutVariations->id;
                }
            }

            // РЈРґР°Р»СЏРµРј РѕСЂРёРіРёРЅР°Р»СЊРЅС‹Рµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР°-РёСЃС‚РѕС‡РЅРёРєР° (РѕРЅРё СѓР¶Рµ СЃРєРѕРїРёСЂРѕРІР°РЅС‹ РІ РІР°СЂРёР°С†РёРё)
            $goodIdsToDelete = array_column($goodsWithoutVariations, 'id');
            foreach ($goodIdsToDelete as $goodId) {
                // РЈРґР°Р»СЏРµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР°-РёСЃС‚РѕС‡РЅРёРєР°, РїРѕСЃРєРѕР»СЊРєСѓ РѕРЅРё СЃРєРѕРїРёСЂРѕРІР°РЅС‹ РІ РІР°СЂРёР°С†РёРё
                DB::table('shop_good_images')
                    ->where('good_id', $goodId)
                    ->delete();
            }

            // РџСЂРѕРІРµСЂСЏРµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёР№ РїРµСЂРµРґ СѓРґР°Р»РµРЅРёРµРј С‚РѕРІР°СЂРѕРІ
            $variationImagesBeforeDelete = [];
            foreach ($updatedVariations as $varId) {
                $variationImagesBeforeDelete[$varId] = DB::table('shop_good_images')
                    ->where('variation_id', $varId)
                    ->count();
            }

            // РЈРґР°Р»СЏРµРј С‚РѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№ РїРѕСЃР»Рµ РїРµСЂРµРЅРѕСЃР° РёР·РѕР±СЂР°Р¶РµРЅРёР№
            foreach ($goodIdsToDelete as $goodId) {
                $goodToDelete = ShopGood::find($goodId);
                if ($goodToDelete) {
                    $this->logAudit($goodToDelete, 'deleted', $goodToDelete->toArray(), null);
                    $goodToDelete->delete();
                }
            }

            // РўРµРїРµСЂСЊ РѕР±РЅРѕРІР»СЏРµРј РїРѕР»СЏ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР° (РїРѕСЃР»Рµ СѓРґР°Р»РµРЅРёСЏ С‚РѕРІР°СЂРѕРІ-РёСЃС‚РѕС‡РЅРёРєРѕРІ)
            $updateFields = [];
            $skippedFields = []; // РџРѕР»СЏ, РєРѕС‚РѕСЂС‹Рµ РЅРµ СѓРґР°Р»РѕСЃСЊ РѕР±РЅРѕРІРёС‚СЊ

            if ($descriptionUpdateGoodId && isset($sourceGoodsData[$descriptionUpdateGoodId])) {
                $updateFields['description'] = $sourceGoodsData[$descriptionUpdateGoodId]['description'];
            }

            if ($shortDescriptionUpdateGoodId && isset($sourceGoodsData[$shortDescriptionUpdateGoodId])) {
                $updateFields['short_description'] = $sourceGoodsData[$shortDescriptionUpdateGoodId]['short_description'];
            }

            if ($slugUpdateGoodId && isset($sourceGoodsData[$slugUpdateGoodId])) {
                $slug = $sourceGoodsData[$slugUpdateGoodId]['slug'];
                // РџСЂРѕРІРµСЂСЏРµРј СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ slug (С‚РµРїРµСЂСЊ С‚РѕРІР°СЂС‹-РёСЃС‚РѕС‡РЅРёРєРё СѓРґР°Р»РµРЅС‹, С‚Р°Рє С‡С‚Рѕ slug РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ СЃРІРѕР±РѕРґРµРЅ)
                $existingSlug = ShopGood::where('slug', $slug)
                    ->where('id', '!=', $goodWithVariations->id)
                    ->exists();

                if ($existingSlug) {
                    $skippedFields['slug'] = 'Slug "'.$slug.'" СѓР¶Рµ РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РґСЂСѓРіРёРј С‚РѕРІР°СЂРѕРј';
                } else {
                    $updateFields['slug'] = $slug;
                }
            }

            // РћР±РЅРѕРІР»СЏРµРј РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ, РµСЃР»Рё РµСЃС‚СЊ РїРѕР»СЏ РґР»СЏ РѕР±РЅРѕРІР»РµРЅРёСЏ
            if (! empty($updateFields)) {
                $goodWithVariations->update($updateFields);
            }

            // Р¤РёРЅР°Р»СЊРЅР°СЏ РїСЂРѕРІРµСЂРєР° РёР·РѕР±СЂР°Р¶РµРЅРёР№ РІР°СЂРёР°С†РёР№ РїРѕСЃР»Рµ РєРѕРїРёСЂРѕРІР°РЅРёСЏ
            $variationImagesAfterCopy = [];
            foreach ($updatedVariations as $varId) {
                $variationImagesAfterCopy[$varId] = DB::table('shop_good_images')
                    ->where('variation_id', $varId)
                    ->count();
            }

            DB::commit();

            // Р¤РѕСЂРјРёСЂСѓРµРј РґРµС‚Р°Р»СЊРЅСѓСЋ РёРЅС„РѕСЂРјР°С†РёСЋ РѕР± РёР·РѕР±СЂР°Р¶РµРЅРёСЏС…
            $imagesSummary = [];
            foreach ($transferredImagesInfo as $info) {
                $imagesSummary[] = [
                    'source_good_id' => $info['good_id'],
                    'variation_ids' => $info['variation_ids'] ?? [],
                    'images_found' => $info['images_found'],
                    'images_copied' => $info['images_copied'] ?? 0,
                    'note' => $info['note'] ?? '',
                ];

                // Р”РѕР±Р°РІР»СЏРµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ С„РёРЅР°Р»СЊРЅРѕРј РєРѕР»РёС‡РµСЃС‚РІРµ РёР·РѕР±СЂР°Р¶РµРЅРёР№ РґР»СЏ РєР°Р¶РґРѕР№ РІР°СЂРёР°С†РёРё
                if (! empty($info['variation_ids'])) {
                    foreach ($info['variation_ids'] as $varId) {
                        $imagesSummary[] = [
                            'variation_id' => $varId,
                            'images_after_copy' => $variationImagesAfterCopy[$varId] ?? 0,
                            'note' => 'Р¤РёРЅР°Р»СЊРЅРѕРµ РєРѕР»РёС‡РµСЃС‚РІРѕ РёР·РѕР±СЂР°Р¶РµРЅРёР№ РІ РІР°СЂРёР°С†РёРё',
                        ];
                    }
                }
            }

            // Р¤РѕСЂРјРёСЂСѓРµРј СЃРѕРѕР±С‰РµРЅРёРµ СЃ СѓС‡РµС‚РѕРј РїСЂРѕРїСѓС‰РµРЅРЅС‹С… РїРѕР»РµР№
            $message = 'Р”Р°РЅРЅС‹Рµ СѓСЃРїРµС€РЅРѕ РїРµСЂРµРЅРµСЃРµРЅС‹ РІ РІР°СЂРёР°С†РёРё. РћР±РЅРѕРІР»РµРЅРѕ РІР°СЂРёР°С†РёР№: '.count($updatedVariations).'. РЈРґР°Р»РµРЅРѕ С‚РѕРІР°СЂРѕРІ: '.count($goodIdsToDelete);

            if (! empty($skippedFields)) {
                $message .= '. РќРµРєРѕС‚РѕСЂС‹Рµ РїРѕР»СЏ РЅРµ СѓРґР°Р»РѕСЃСЊ РѕР±РЅРѕРІРёС‚СЊ: '.implode(', ', array_values($skippedFields));
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'updated_variations' => $updatedVariations,
                    'deleted_goods' => $goodIdsToDelete,
                    'images_transfer' => $imagesSummary,
                    'skipped_fields' => $skippedFields,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('РћС€РёР±РєР° РёР·РјРµРЅРµРЅРёСЏ РІР°СЂРёР°С†РёРё: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РёР·РјРµРЅРµРЅРёСЏ РІР°СЂРёР°С†РёРё: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРµСЂРµРЅРѕСЃ РјРµРґРёР° РёР· РІР°СЂРёР°С†РёР№ РІ РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ
     */
    public function transferMediaVarToMain(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'goods_ids' => 'required|array|min:1',
            'goods_ids.*' => 'exists:shop_goods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $goodsIds = $request->input('goods_ids');
            $totalVariationsDeleted = 0;
            $totalImagesTransferred = 0;
            $processedGoods = [];

            foreach ($goodsIds as $goodId) {
                $good = ShopGood::with(['variations' => function ($query) {
                    $query->with('images');
                }])->findOrFail($goodId);

                // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ С‚РѕРІР°СЂ РёРјРµРµС‚ РІР°СЂРёР°С†РёРё
                if ($good->variations->isEmpty()) {
                    continue; // РџСЂРѕРїСѓСЃРєР°РµРј С‚РѕРІР°СЂС‹ Р±РµР· РІР°СЂРёР°С†РёР№
                }

                $goodImagesTransferred = 0;

                // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Р¶РґСѓСЋ РІР°СЂРёР°С†РёСЋ
                foreach ($good->variations as $variation) {
                    // РџРµСЂРµРЅРѕСЃРёРј РІСЃРµ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РёР· РІР°СЂРёР°С†РёРё РІ РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ
                    foreach ($variation->images as $image) {
                        // РњРµРЅСЏРµРј РїСЂРёРЅР°РґР»РµР¶РЅРѕСЃС‚СЊ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ СЃ РІР°СЂРёР°С†РёРё РЅР° С‚РѕРІР°СЂ
                        $image->update([
                            'good_id' => $good->id,
                            'variation_id' => null,
                        ]);
                        $goodImagesTransferred++;
                        $totalImagesTransferred++;
                    }

                    // РЈРґР°Р»СЏРµРј РІР°СЂРёР°С†РёСЋ
                    $variation->delete();
                    $totalVariationsDeleted++;
                }

                $processedGoods[] = [
                    'id' => $good->id,
                    'name' => $good->name,
                    'variations_deleted' => $good->variations->count(),
                    'images_transferred' => $goodImagesTransferred,
                ];
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'РњРµРґРёР° СѓСЃРїРµС€РЅРѕ РїРµСЂРµРЅРµСЃРµРЅРѕ РёР· РІР°СЂРёР°С†РёР№ РІ РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ. РЈРґР°Р»РµРЅРѕ РІР°СЂРёР°С†РёР№: '.$totalVariationsDeleted.'. РџРµСЂРµРЅРµСЃРµРЅРѕ РёР·РѕР±СЂР°Р¶РµРЅРёР№: '.$totalImagesTransferred,
                'data' => [
                    'processed_goods' => $processedGoods,
                    'total_variations_deleted' => $totalVariationsDeleted,
                    'total_images_transferred' => $totalImagesTransferred,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('РћС€РёР±РєР° РїРµСЂРµРЅРѕСЃР° РјРµРґРёР° РёР· РІР°СЂРёР°С†РёР№: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРµСЂРµРЅРѕСЃР° РјРµРґРёР° РёР· РІР°СЂРёР°С†РёР№: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РєРѕР»РёС‡РµСЃС‚РІРѕ РёР·РѕР±СЂР°Р¶РµРЅРёР№ РІ РІР°СЂРёР°С†РёСЏС… РґР»СЏ СѓРєР°Р·Р°РЅРЅС‹С… С‚РѕРІР°СЂРѕРІ
     */
    public function getVariationsImagesCount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:shop_goods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $goodsIds = $request->input('ids');
            $result = [];

            foreach ($goodsIds as $goodId) {
                $imagesCount = DB::table('shop_good_images')
                    ->join('shop_good_variations', 'shop_good_images.variation_id', '=', 'shop_good_variations.id')
                    ->where('shop_good_variations.good_id', $goodId)
                    ->whereNull('shop_good_images.good_id') // РўРѕР»СЊРєРѕ РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёР№, РЅРµ С‚РѕРІР°СЂР°
                    ->count();

                $result[$goodId] = $imagesCount;
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ РєРѕР»РёС‡РµСЃС‚РІР° РёР·РѕР±СЂР°Р¶РµРЅРёР№ РІ РІР°СЂРёР°С†РёСЏС…: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ РєРѕР»РёС‡РµСЃС‚РІР° РёР·РѕР±СЂР°Р¶РµРЅРёР№ РІ РІР°СЂРёР°С†РёСЏС…: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃРїРёСЃРѕРє СѓРЅРёРєР°Р»СЊРЅС‹С… РїРѕСЃС‚Р°РІС‰РёРєРѕРІ РёР· С‚РѕРІР°СЂРѕРІ
     */
    public function getSuppliers(): JsonResponse
    {
        try {
            // РџРѕР»СѓС‡Р°РµРј РїРѕСЃС‚Р°РІС‰РёРєРѕРІ РёР· РѕСЃРЅРѕРІРЅС‹С… С‚РѕРІР°СЂРѕРІ
            $suppliersFromGoods = ShopGood::whereNotNull('supplier')
                ->where('supplier', '!=', '')
                ->distinct()
                ->pluck('supplier')
                ->filter()
                ->toArray();

            // РџРѕР»СѓС‡Р°РµРј РїРѕСЃС‚Р°РІС‰РёРєРѕРІ РёР· РІР°СЂРёР°С†РёР№
            $suppliersFromVariations = ShopGoodVariation::whereNotNull('supplier')
                ->where('supplier', '!=', '')
                ->distinct()
                ->pluck('supplier')
                ->filter()
                ->toArray();

            // РћР±СЉРµРґРёРЅСЏРµРј Рё РїРѕР»СѓС‡Р°РµРј СѓРЅРёРєР°Р»СЊРЅС‹Р№ СЃРїРёСЃРѕРє
            $allSuppliers = array_unique(array_merge($suppliersFromGoods, $suppliersFromVariations));

            // РЎРѕСЂС‚РёСЂСѓРµРј РїРѕ Р°Р»С„Р°РІРёС‚Сѓ
            sort($allSuppliers);

            return response()->json([
                'success' => true,
                'data' => array_values($allSuppliers), // array_values РґР»СЏ РїРµСЂРµРёРЅРґРµРєСЃР°С†РёРё РјР°СЃСЃРёРІР°
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ СЃРїРёСЃРєР° РїРѕСЃС‚Р°РІС‰РёРєРѕРІ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РњР°СЃСЃРѕРІРѕРµ СЃРѕР·РґР°РЅРёРµ РІР°СЂРёР°С†РёР№ РёР· С‚РѕРІР°СЂРѕРІ
     * РџСЂРµРѕР±СЂР°Р·СѓРµС‚ РЅРµСЃРєРѕР»СЊРєРѕ С‚РѕРІР°СЂРѕРІ РІ РІР°СЂРёР°С†РёРё РѕРґРЅРѕРіРѕ РіР»Р°РІРЅРѕРіРѕ С‚РѕРІР°СЂР°
     */
    public function bulkCreateVariations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'main_good_id' => 'required|exists:shop_goods,id',
            'main_good_name' => 'nullable|string|max:255',
            'selected_attributes' => 'required|array|min:1',
            'selected_attributes.*' => 'exists:shop_variation_attributes,id',
            'goods_mapping' => 'required|array|min:1',
            'goods_mapping.*.good_id' => 'required|exists:shop_goods,id',
            'goods_mapping.*.attribute_values' => 'required|array',
            'goods_mapping.*.attribute_values.*.attribute_id' => 'nullable|exists:shop_variation_attributes,id',
            'goods_mapping.*.attribute_values.*.value_id' => 'nullable|exists:shop_variation_attribute_values,id',
            'goods_mapping.*.attribute_values.*.value' => 'nullable|string|max:255',
            'goods_mapping.*.attribute_values.*.attribute_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅР°СЏ РІР°Р»РёРґР°С†РёСЏ: РєР°Р¶РґС‹Р№ attribute_value РґРѕР»Р¶РµРЅ РёРјРµС‚СЊ Р»РёР±Рѕ attribute_id, Р»РёР±Рѕ attribute_name
        foreach ($request->goods_mapping as $index => $mapping) {
            foreach ($mapping['attribute_values'] as $valueIndex => $attrValue) {
                if (! isset($attrValue['attribute_id']) && ! isset($attrValue['attribute_name'])) {
                    return response()->json([
                        'success' => false,
                        'message' => "РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё: goods_mapping[{$index}].attribute_values[{$valueIndex}] РґРѕР»Р¶РµРЅ СЃРѕРґРµСЂР¶Р°С‚СЊ Р»РёР±Рѕ attribute_id, Р»РёР±Рѕ attribute_name",
                    ], 422);
                }
                if (! isset($attrValue['value_id']) && ! isset($attrValue['value'])) {
                    return response()->json([
                        'success' => false,
                        'message' => "РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё: goods_mapping[{$index}].attribute_values[{$valueIndex}] РґРѕР»Р¶РµРЅ СЃРѕРґРµСЂР¶Р°С‚СЊ Р»РёР±Рѕ value_id, Р»РёР±Рѕ value",
                    ], 422);
                }
            }
        }

        try {
            DB::beginTransaction();

            $mainGoodId = $request->main_good_id;
            $mainGoodName = $request->main_good_name;
            $selectedAttributes = $request->selected_attributes;
            $goodsMapping = $request->goods_mapping;

            // Р—Р°РіСЂСѓР¶Р°РµРј РіР»Р°РІРЅС‹Р№ С‚РѕРІР°СЂ
            $mainGood = ShopGood::with(['variations'])->findOrFail($mainGoodId);

            // РћР±РЅРѕРІР»СЏРµРј РЅР°Р·РІР°РЅРёРµ РіР»Р°РІРЅРѕРіРѕ С‚РѕРІР°СЂР°, РµСЃР»Рё СѓРєР°Р·Р°РЅРѕ
            if ($mainGoodName && trim($mainGoodName) !== '') {
                $mainGood->name = trim($mainGoodName);
                $mainGood->save();
            }

            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РІСЃРµ С‚РѕРІР°СЂС‹ РІ СЃРїРёСЃРєРµ СЃСѓС‰РµСЃС‚РІСѓСЋС‚
            $goodsToConvert = array_column($goodsMapping, 'good_id');
            // РўРµРїРµСЂСЊ РіР»Р°РІРЅС‹Р№ С‚РѕРІР°СЂ РјРѕР¶РµС‚ Р±С‹С‚СЊ РІ СЃРїРёСЃРєРµ - РґР»СЏ РЅРµРіРѕ С‚РѕР¶Рµ СЃРѕР·РґР°РґРёРј РІР°СЂРёР°С†РёСЋ

            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РіР»Р°РІРЅС‹Р№ С‚РѕРІР°СЂ РЅРµ РёРјРµРµС‚ РІР°СЂРёР°С†РёР№ (РѕРїС†РёРѕРЅР°Р»СЊРЅРѕ, РјРѕР¶РЅРѕ СЂР°Р·СЂРµС€РёС‚СЊ)
            // if ($mainGood->variations()->count() > 0) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Р“Р»Р°РІРЅС‹Р№ С‚РѕРІР°СЂ СѓР¶Рµ РёРјРµРµС‚ РІР°СЂРёР°С†РёРё'
            //     ], 422);
            // }

            $createdVariations = 0;
            $skippedVariations = 0;
            $errors = [];

            // РџРѕР»СѓС‡Р°РµРј РјР°РєСЃРёРјР°Р»СЊРЅС‹Р№ sort_order РґР»СЏ РІР°СЂРёР°С†РёР№ РіР»Р°РІРЅРѕРіРѕ С‚РѕРІР°СЂР°
            $maxSortOrder = $mainGood->variations()->max('sort_order') ?? 0;

            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Р¶РґС‹Р№ С‚РѕРІР°СЂ
            foreach ($goodsMapping as $mapping) {
                $sourceGoodId = $mapping['good_id'];
                $attributeValues = $mapping['attribute_values'];
                $isMainGood = ($sourceGoodId == $mainGoodId);

                // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РІСЃРµ РІС‹Р±СЂР°РЅРЅС‹Рµ Р°С‚СЂРёР±СѓС‚С‹ РїСЂРёСЃСѓС‚СЃС‚РІСѓСЋС‚
                $mappingAttributeIds = [];
                foreach ($attributeValues as $attrValue) {
                    if (isset($attrValue['attribute_id'])) {
                        $mappingAttributeIds[] = $attrValue['attribute_id'];
                    } elseif (isset($attrValue['attribute_name'])) {
                        // Р”Р»СЏ РІСЂРµРјРµРЅРЅС‹С… Р°С‚СЂРёР±СѓС‚РѕРІ РЅР°С…РѕРґРёРј ID РїРѕ РёРјРµРЅРё
                        $attribute = DB::table('shop_variation_attributes')
                            ->where('name', $attrValue['attribute_name'])
                            ->first();
                        if ($attribute) {
                            $mappingAttributeIds[] = $attribute->id;
                        }
                    }
                }
                $missingAttributes = array_diff($selectedAttributes, $mappingAttributeIds);
                if (! empty($missingAttributes)) {
                    $errors[] = "РўРѕРІР°СЂ ID {$sourceGoodId}: РЅРµ СѓРєР°Р·Р°РЅС‹ Р·РЅР°С‡РµРЅРёСЏ РґР»СЏ РІСЃРµС… РІС‹Р±СЂР°РЅРЅС‹С… Р°С‚СЂРёР±СѓС‚РѕРІ";

                    continue;
                }

                // Р—Р°РіСЂСѓР¶Р°РµРј РёСЃС…РѕРґРЅС‹Р№ С‚РѕРІР°СЂ
                $sourceGood = ShopGood::findOrFail($sourceGoodId);

                // РџСЂРѕРІРµСЂСЏРµРј, РЅРµС‚ Р»Рё СѓР¶Рµ РІР°СЂРёР°С†РёРё СЃ С‚Р°РєРёРјРё Р¶Рµ Р°С‚СЂРёР±СѓС‚Р°РјРё
                $existingVariation = $this->findVariationByAttributes($mainGoodId, $attributeValues);
                if ($existingVariation) {
                    $skippedVariations++;
                    $errors[] = "РўРѕРІР°СЂ ID {$sourceGoodId}: РІР°СЂРёР°С†РёСЏ СЃ С‚Р°РєРёРјРё Р°С‚СЂРёР±СѓС‚Р°РјРё СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚";
                    // Р•СЃР»Рё СЌС‚Рѕ РЅРµ РіР»Р°РІРЅС‹Р№ С‚РѕРІР°СЂ, СѓРґР°Р»СЏРµРј РµРіРѕ
                    if (! $isMainGood) {
                        $sourceGood->delete();
                    }

                    continue;
                }

                // РЎРѕР·РґР°РµРј РІР°СЂРёР°С†РёСЋ
                // РСЃРїРѕР»СЊР·СѓРµРј РЅР°Р·РІР°РЅРёРµ РёР· goods_mapping, РµСЃР»Рё РїРµСЂРµРґР°РЅРѕ, РёРЅР°С‡Рµ РЅР°Р·РІР°РЅРёРµ РёСЃС…РѕРґРЅРѕРіРѕ С‚РѕРІР°СЂР°
                $variationName = isset($mapping['name']) && ! empty($mapping['name'])
                    ? trim($mapping['name'])
                    : $sourceGood->name;

                $variation = \App\Models\ShopGoodVariation::create([
                    'good_id' => $mainGoodId,
                    'name' => $variationName, // РСЃРїРѕР»СЊР·СѓРµРј РЅР°Р·РІР°РЅРёРµ РёСЃС…РѕРґРЅРѕРіРѕ С‚РѕРІР°СЂР° РёР· goods_mapping
                    'sku' => $sourceGood->sku,
                    'price' => $sourceGood->price,
                    'sale_price' => $sourceGood->sale_price,
                    'demping_price' => $sourceGood->demping_price,
                    'show_demping' => $sourceGood->show_demping,
                    'stock_quantity' => $sourceGood->stock_quantity,
                    'remote_stock_quantity' => $sourceGood->remote_stock_quantity,
                    'fast_remote_stock_quantity' => $sourceGood->fast_remote_stock_quantity,
                    'weight' => $sourceGood->weight,
                    'length' => $sourceGood->length,
                    'height' => $sourceGood->height,
                    'width' => $sourceGood->width,
                    'is_active' => $sourceGood->is_active,
                    'sort_order' => ++$maxSortOrder,
                ]);

                // РџСЂРёРІСЏР·С‹РІР°РµРј Р·РЅР°С‡РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚РѕРІ Рє РІР°СЂРёР°С†РёРё
                foreach ($attributeValues as $attrValue) {
                    $attributeValueId = null;

                    // Р•СЃР»Рё РµСЃС‚СЊ value_id, РёСЃРїРѕР»СЊР·СѓРµРј РµРіРѕ
                    if (isset($attrValue['value_id']) && $attrValue['value_id']) {
                        $attributeValueId = $attrValue['value_id'];
                    }
                    // Р•СЃР»Рё РµСЃС‚СЊ value (РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ), СЃРѕР·РґР°РµРј РёР»Рё РЅР°С…РѕРґРёРј Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
                    elseif (isset($attrValue['value']) && trim($attrValue['value']) !== '') {
                        $attributeId = $attrValue['attribute_id'] ?? null;
                        $valueText = trim($attrValue['value']);

                        if ($attributeId) {
                            // РС‰РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ Р·РЅР°С‡РµРЅРёРµ РїРѕ С‚РµРєСЃС‚Сѓ
                            $existingValue = DB::table('shop_variation_attribute_values')
                                ->where('attribute_id', $attributeId)
                                ->where('value', $valueText)
                                ->first();

                            if ($existingValue) {
                                $attributeValueId = $existingValue->id;
                            } else {
                                // РЎРѕР·РґР°РµРј РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
                                $attributeValueId = DB::table('shop_variation_attribute_values')->insertGetId([
                                    'attribute_id' => $attributeId,
                                    'value' => $valueText,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                    // Р•СЃР»Рё РµСЃС‚СЊ attribute_name (РґР»СЏ РІСЂРµРјРµРЅРЅС‹С… Р°С‚СЂРёР±СѓС‚РѕРІ РёР· РІР°СЂРёР°С†РёР№)
                    elseif (isset($attrValue['attribute_name']) && isset($attrValue['value']) && trim($attrValue['value']) !== '') {
                        $attributeName = trim($attrValue['attribute_name']);
                        $valueText = trim($attrValue['value']);

                        // РќР°С…РѕРґРёРј Р°С‚СЂРёР±СѓС‚ РїРѕ РёРјРµРЅРё
                        $attribute = DB::table('shop_variation_attributes')
                            ->where('name', $attributeName)
                            ->first();

                        if ($attribute) {
                            // РС‰РµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРµ Р·РЅР°С‡РµРЅРёРµ РїРѕ С‚РµРєСЃС‚Сѓ
                            $existingValue = DB::table('shop_variation_attribute_values')
                                ->where('attribute_id', $attribute->id)
                                ->where('value', $valueText)
                                ->first();

                            if ($existingValue) {
                                $attributeValueId = $existingValue->id;
                            } else {
                                // РЎРѕР·РґР°РµРј РЅРѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ Р°С‚СЂРёР±СѓС‚Р°
                                $attributeValueId = DB::table('shop_variation_attribute_values')->insertGetId([
                                    'attribute_id' => $attribute->id,
                                    'value' => $valueText,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }

                    if ($attributeValueId) {
                        DB::table('shop_variation_attributes_values')->insert([
                            'variation_id' => $variation->id,
                            'attribute_value_id' => $attributeValueId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // РџРµСЂРµРЅРѕСЃРёРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РѕС‚ РёСЃС…РѕРґРЅРѕРіРѕ С‚РѕРІР°СЂР° Рє РІР°СЂРёР°С†РёРё
                DB::table('shop_good_images')
                    ->where('good_id', $sourceGoodId)
                    ->whereNull('variation_id')
                    ->update([
                        'good_id' => null,
                        'variation_id' => $variation->id,
                    ]);

                // РЈРґР°Р»СЏРµРј РёСЃС…РѕРґРЅС‹Р№ С‚РѕРІР°СЂ С‚РѕР»СЊРєРѕ РµСЃР»Рё СЌС‚Рѕ РЅРµ РіР»Р°РІРЅС‹Р№ С‚РѕРІР°СЂ
                if (! $isMainGood) {
                    $sourceGood->delete();
                }

                $createdVariations++;
            }

            DB::commit();

            $message = "РЈСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅРѕ РІР°СЂРёР°С†РёР№: {$createdVariations}";
            if ($skippedVariations > 0) {
                $message .= ", РїСЂРѕРїСѓС‰РµРЅРѕ: {$skippedVariations}";
            }
            if (! empty($errors)) {
                $message .= '. РћС€РёР±РєРё: '.implode('; ', array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $message .= ' Рё РµС‰Рµ '.(count($errors) - 5).' РѕС€РёР±РѕРє';
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'created_variations' => $createdVariations,
                    'skipped_variations' => $skippedVariations,
                    'errors' => $errors,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error in bulkCreateVariations: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ РІР°СЂРёР°С†РёР№: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРѕРёСЃРє РІР°СЂРёР°С†РёРё РїРѕ Р°С‚СЂРёР±СѓС‚Р°Рј
     */
    private function findVariationByAttributes($goodId, $attributeValues): ?\App\Models\ShopGoodVariation
    {
        // РџРѕР»СѓС‡Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё С‚РѕРІР°СЂР°
        $variations = \App\Models\ShopGoodVariation::where('good_id', $goodId)->get();

        if ($variations->isEmpty()) {
            return null;
        }

        // РЎРѕСЂС‚РёСЂСѓРµРј Р·РЅР°С‡РµРЅРёСЏ Р°С‚СЂРёР±СѓС‚РѕРІ РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ
        $searchAttributeIds = [];
        $searchValueIds = [];
        foreach ($attributeValues as $attrValue) {
            if (isset($attrValue['attribute_id'])) {
                $searchAttributeIds[] = $attrValue['attribute_id'];
            } elseif (isset($attrValue['attribute_name'])) {
                // Р”Р»СЏ РІСЂРµРјРµРЅРЅС‹С… Р°С‚СЂРёР±СѓС‚РѕРІ РЅР°С…РѕРґРёРј ID РїРѕ РёРјРµРЅРё
                $attribute = DB::table('shop_variation_attributes')
                    ->where('name', $attrValue['attribute_name'])
                    ->first();
                if ($attribute) {
                    $searchAttributeIds[] = $attribute->id;
                }
            }

            if (isset($attrValue['value_id']) && $attrValue['value_id']) {
                $searchValueIds[] = $attrValue['value_id'];
            } elseif (isset($attrValue['value']) && trim($attrValue['value']) !== '') {
                // Р”Р»СЏ РЅРѕРІС‹С… Р·РЅР°С‡РµРЅРёР№ РЅСѓР¶РЅРѕ РЅР°Р№С‚Рё value_id РїРѕ С‚РµРєСЃС‚Сѓ
                $attributeId = null;
                if (isset($attrValue['attribute_id'])) {
                    $attributeId = $attrValue['attribute_id'];
                } elseif (isset($attrValue['attribute_name'])) {
                    $attribute = DB::table('shop_variation_attributes')
                        ->where('name', $attrValue['attribute_name'])
                        ->first();
                    if ($attribute) {
                        $attributeId = $attribute->id;
                    }
                }

                if ($attributeId) {
                    $value = DB::table('shop_variation_attribute_values')
                        ->where('attribute_id', $attributeId)
                        ->where('value', trim($attrValue['value']))
                        ->first();
                    if ($value) {
                        $searchValueIds[] = $value->id;
                    }
                }
            }
        }
        sort($searchAttributeIds);
        sort($searchValueIds);

        // РџСЂРѕРІРµСЂСЏРµРј РєР°Р¶РґСѓСЋ РІР°СЂРёР°С†РёСЋ
        foreach ($variations as $variation) {
            $variationAttributeValues = DB::table('shop_variation_attributes_values')
                ->where('variation_id', $variation->id)
                ->pluck('attribute_value_id')
                ->toArray();

            if (empty($variationAttributeValues)) {
                continue;
            }

            // РџРѕР»СѓС‡Р°РµРј attribute_id РґР»СЏ РєР°Р¶РґРѕРіРѕ value_id
            $variationAttributes = DB::table('shop_variation_attributes_values as vav')
                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                ->where('vav.variation_id', $variation->id)
                ->select('av.attribute_id', 'vav.attribute_value_id')
                ->get();

            $variationAttributeIds = $variationAttributes->pluck('attribute_id')->toArray();
            $variationValueIds = $variationAttributes->pluck('attribute_value_id')->toArray();

            sort($variationAttributeIds);
            sort($variationValueIds);

            // РЎСЂР°РІРЅРёРІР°РµРј Р°С‚СЂРёР±СѓС‚С‹ Рё Р·РЅР°С‡РµРЅРёСЏ
            if ($searchAttributeIds === $variationAttributeIds && $searchValueIds === $variationValueIds) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * РџСЂРѕРІРµСЂРёС‚СЊ СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ СЃР»Р°РіР°
     */
    public function checkSlug(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        $slug = $request->input('slug');

        // РџСЂРѕСЃС‚Р°СЏ РїСЂРѕРІРµСЂРєР° СЃСѓС‰РµСЃС‚РІРѕРІР°РЅРёСЏ С‚РѕРІР°СЂР° СЃРѕ СЃР»Р°РіРѕРј (С‚РѕР»СЊРєРѕ РїСЂРѕРІРµСЂРєР°, Р±РµР· Р·Р°РіСЂСѓР·РєРё РґР°РЅРЅС‹С…)
        $exists = ShopGood::where('slug', $slug)->exists();

        return response()->json([
            'success' => true,
            'available' => ! $exists,
            'message' => $exists ? 'РЎР»Р°Рі СѓР¶Рµ РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ' : 'РЎР»Р°Рі РґРѕСЃС‚СѓРїРµРЅ',
        ]);
    }

    /**
     * РЎР»РёСЏРЅРёРµ РІР°СЂРёР°С†РёР№ РёР· РЅРµСЃРєРѕР»СЊРєРёС… С‚РѕРІР°СЂРѕРІ РІ РѕРґРёРЅ РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ
     */
    public function mergeVariations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'main_good_id' => 'required|exists:shop_goods,id',
            'donor_good_ids' => 'required|array|min:1',
            'donor_good_ids.*' => 'exists:shop_goods,id|different:main_good_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $mainGoodId = $request->input('main_good_id');
            $donorGoodIds = $request->input('donor_good_ids');

            // Р—Р°РіСЂСѓР¶Р°РµРј РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ
            $mainGood = ShopGood::findOrFail($mainGoodId);

            // Р—Р°РіСЂСѓР¶Р°РµРј С‚РѕРІР°СЂС‹-РґРѕРЅРѕСЂС‹ СЃ РІР°СЂРёР°С†РёСЏРјРё, РёР·РѕР±СЂР°Р¶РµРЅРёСЏРјРё Рё РІРёРґРµРѕ
            $donorGoods = ShopGood::with(['variations.images', 'variations.videos', 'variations'])->whereIn('id', $donorGoodIds)->get();

            if ($donorGoods->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'РўРѕРІР°СЂС‹-РґРѕРЅРѕСЂС‹ РЅРµ РЅР°Р№РґРµРЅС‹',
                ], 404);
            }

            $totalVariationsMoved = 0;
            $totalImagesMoved = 0;
            $totalVideosMoved = 0;

            // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј РєР°Р¶РґС‹Р№ С‚РѕРІР°СЂ-РґРѕРЅРѕСЂ
            foreach ($donorGoods as $donorGood) {
                // РџРµСЂРµРјРµС‰Р°РµРј РІСЃРµ РІР°СЂРёР°С†РёРё РёР· РґРѕРЅРѕСЂР° РІ РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ
                $variations = $donorGood->variations;

                if ($variations->isEmpty()) {
                    // Р•СЃР»Рё Сѓ С‚РѕРІР°СЂР°-РґРѕРЅРѕСЂР° РЅРµС‚ РІР°СЂРёР°С†РёР№, РїСЂРѕСЃС‚Рѕ СѓРґР°Р»СЏРµРј РµРіРѕ
                    // РЎРЅР°С‡Р°Р»Р° СѓРґР°Р»СЏРµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР°
                    ShopGoodImage::where('good_id', $donorGood->id)
                        ->whereNull('variation_id')
                        ->delete();

                    // РЈРґР°Р»СЏРµРј РІРёРґРµРѕ С‚РѕРІР°СЂР°
                    \App\Models\ShopGoodVideo::where('good_id', $donorGood->id)
                        ->whereNull('variation_id')
                        ->delete();

                    // Р Р°Р·СЂС‹РІР°РµРј СЃРІСЏР·Рё many-to-many РїРµСЂРµРґ СѓРґР°Р»РµРЅРёРµРј
                    $donorGood->categories()->detach();
                    $donorGood->brands()->detach();
                    $donorGood->tags()->detach();
                    $donorGood->properties()->detach();

                    // РЈРґР°Р»СЏРµРј С‚РѕРІР°СЂ-РґРѕРЅРѕСЂ
                    $donorGood->delete();

                    continue;
                }

                foreach ($variations as $variation) {
                    // РћР±РЅРѕРІР»СЏРµРј good_id РІР°СЂРёР°С†РёРё РЅР° РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ
                    // РџРѕСЃС‚Р°РІС‰РёРє (supplier) СѓР¶Рµ СЃРѕС…СЂР°РЅРµРЅ РІ РїРѕР»Рµ РІР°СЂРёР°С†РёРё, РѕРЅ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё СЃРѕС…СЂР°РЅРёС‚СЃСЏ
                    $variation->good_id = $mainGoodId;
                    $variation->save();

                    $totalVariationsMoved++;

                    // РџРµСЂРµРјРµС‰Р°РµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РІР°СЂРёР°С†РёРё
                    $variationImages = ShopGoodImage::where('variation_id', $variation->id)->get();
                    foreach ($variationImages as $image) {
                        // РћР±РЅРѕРІР»СЏРµРј good_id РёР·РѕР±СЂР°Р¶РµРЅРёСЏ РЅР° РѕСЃРЅРѕРІРЅРѕР№ С‚РѕРІР°СЂ
                        $image->good_id = $mainGoodId;
                        $image->save();
                        $totalImagesMoved++;
                    }

                    // РџРµСЂРµРјРµС‰Р°РµРј РІРёРґРµРѕ РІР°СЂРёР°С†РёРё (РµСЃР»Рё РµСЃС‚СЊ)
                    $variationVideos = \App\Models\ShopGoodVideo::where('variation_id', $variation->id)->get();
                    foreach ($variationVideos as $video) {
                        $video->good_id = $mainGoodId;
                        $video->save();
                        $totalVideosMoved++;
                    }

                    // РђС‚СЂРёР±СѓС‚С‹ РІР°СЂРёР°С†РёР№ (shop_variation_attributes_values) РїСЂРёРІСЏР·Р°РЅС‹ С‚РѕР»СЊРєРѕ Рє variation_id,
                    // РїРѕСЌС‚РѕРјСѓ РѕРЅРё Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё РѕСЃС‚Р°РЅСѓС‚СЃСЏ РїСЂРёРІСЏР·Р°РЅРЅС‹РјРё РїРѕСЃР»Рµ РѕР±РЅРѕРІР»РµРЅРёСЏ good_id
                }

                // РЈРґР°Р»СЏРµРј С‚РѕРІР°СЂ-РґРѕРЅРѕСЂ (РІР°СЂРёР°С†РёРё СѓР¶Рµ РїРµСЂРµРјРµС‰РµРЅС‹, СЃРІСЏР·Рё СЂР°Р·РѕСЂРІР°РЅС‹)
                // РЎРЅР°С‡Р°Р»Р° СѓРґР°Р»СЏРµРј РёР·РѕР±СЂР°Р¶РµРЅРёСЏ С‚РѕРІР°СЂР° (РЅРµ РІР°СЂРёР°С†РёР№, РѕРЅРё СѓР¶Рµ РїРµСЂРµРјРµС‰РµРЅС‹)
                ShopGoodImage::where('good_id', $donorGood->id)
                    ->whereNull('variation_id')
                    ->delete();

                // РЈРґР°Р»СЏРµРј РІРёРґРµРѕ С‚РѕРІР°СЂР°
                \App\Models\ShopGoodVideo::where('good_id', $donorGood->id)
                    ->whereNull('variation_id')
                    ->delete();

                // Р Р°Р·СЂС‹РІР°РµРј СЃРІСЏР·Рё many-to-many РїРµСЂРµРґ СѓРґР°Р»РµРЅРёРµРј
                $donorGood->categories()->detach();
                $donorGood->brands()->detach();
                $donorGood->tags()->detach();
                $donorGood->properties()->detach();

                // РЈРґР°Р»СЏРµРј С‚РѕРІР°СЂ-РґРѕРЅРѕСЂ
                $donorGood->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Р’Р°СЂРёР°С†РёРё СѓСЃРїРµС€РЅРѕ СЃР»РёС‚С‹',
                'data' => [
                    'variations_count' => $totalVariationsMoved,
                    'images_count' => $totalImagesMoved,
                    'videos_count' => $totalVideosMoved,
                    'deleted_goods_count' => count($donorGoodIds),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('РћС€РёР±РєР° СЃР»РёСЏРЅРёСЏ РІР°СЂРёР°С†РёР№: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° СЃР»РёСЏРЅРёСЏ РІР°СЂРёР°С†РёР№: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РµРЅРёРµ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє РґР»СЏ СѓРґР°Р»РµРЅРёСЏ (С‚РѕР»СЊРєРѕ С‚Рµ, РєРѕС‚РѕСЂС‹Рµ РїСЂРёСЃСѓС‚СЃС‚РІСѓСЋС‚ Сѓ РІС‹Р±СЂР°РЅРЅС‹С… С‚РѕРІР°СЂРѕРІ)
     */
    public function getPropertiesForRemove(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'good_ids' => 'required|string', // Accept comma-separated string
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Convert comma-separated string to array of integers
            $goodIdsString = $request->input('good_ids', '');
            $goodIds = array_map('intval', explode(',', $goodIdsString));
            $goodIds = array_filter($goodIds); // Remove empty values

            if (empty($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµРѕР±С…РѕРґРёРјРѕ СѓРєР°Р·Р°С‚СЊ С…РѕС‚СЏ Р±С‹ РѕРґРёРЅ ID С‚РѕРІР°СЂР°',
                ], 422);
            }

            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РІСЃРµ СѓРєР°Р·Р°РЅРЅС‹Рµ ID С‚РѕРІР°СЂРѕРІ СЃСѓС‰РµСЃС‚РІСѓСЋС‚
            $existingGoodIds = DB::table('shop_goods')
                ->whereIn('id', $goodIds)
                ->pluck('id')
                ->toArray();

            if (count($existingGoodIds) !== count($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'РќРµРєРѕС‚РѕСЂС‹Рµ СѓРєР°Р·Р°РЅРЅС‹Рµ ID С‚РѕРІР°СЂРѕРІ РЅРµ СЃСѓС‰РµСЃС‚РІСѓСЋС‚',
                ], 422);
            }

            $search = $request->input('search', '');
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 10);

            // РџРѕР»СѓС‡Р°РµРј РІСЃРµ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё Рё РёС… Р·РЅР°С‡РµРЅРёСЏ РґР»СЏ РІС‹Р±СЂР°РЅРЅС‹С… С‚РѕРІР°СЂРѕРІ
            $query = DB::table('shop_good_properties as gp')
                ->join('shop_properties as p', 'gp.property_id', '=', 'p.id')
                ->join('shop_property_values as pv', 'gp.shop_property_value_id', '=', 'pv.id')
                ->whereIn('gp.good_id', $goodIds)
                ->select(
                    'p.id as property_id',
                    'p.name as property_name',
                    'pv.id as property_value_id',
                    'pv.value as property_value_name',
                    DB::raw('COUNT(DISTINCT gp.good_id) as count')
                )
                ->groupBy('p.id', 'p.name', 'pv.id', 'pv.value')
                ->orderBy('p.name')
                ->orderBy('pv.value');

            // РџСЂРёРјРµРЅСЏРµРј РїРѕРёСЃРє РµСЃР»Рё СѓРєР°Р·Р°РЅ
            if (! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('p.name', 'like', '%'.$search.'%')
                        ->orWhere('pv.value', 'like', '%'.$search.'%');
                });
            }

            // РџРѕР»СѓС‡Р°РµРј РѕР±С‰РµРµ РєРѕР»РёС‡РµСЃС‚РІРѕ
            $totalCount = $query->count();

            // РџСЂРёРјРµРЅСЏРµРј РїР°РіРёРЅР°С†РёСЋ
            $properties = $query->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $properties,
                'total' => $totalCount,
                'page' => $page,
                'per_page' => $perPage,
            ]);

        } catch (\Exception $e) {
            \Log::error('РћС€РёР±РєР° РїСЂРё РїРѕР»СѓС‡РµРЅРёРё С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє РґР»СЏ СѓРґР°Р»РµРЅРёСЏ: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё РїРѕР»СѓС‡РµРЅРёРё С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє',
            ], 500);
        }
    }

    /**
     * РњР°СЃСЃРѕРІРѕРµ СѓРґР°Р»РµРЅРёРµ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє Сѓ С‚РѕРІР°СЂРѕРІ
     */
    public function bulkRemoveProperties(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'good_ids' => 'required|array',
            'good_ids.*' => 'exists:shop_goods,id',
            'properties' => 'required|array',
            'properties.*.property_id' => 'required|exists:shop_properties,id',
            'properties.*.property_value_id' => 'required|exists:shop_property_values,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $goodIds = $request->input('good_ids', []);
            $properties = $request->input('properties', []);
            $removedCount = 0;

            // Р”Р»СЏ РєР°Р¶РґРѕРіРѕ С‚РѕРІР°СЂР° СѓРґР°Р»СЏРµРј РІС‹Р±СЂР°РЅРЅС‹Рµ С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё
            foreach ($goodIds as $goodId) {
                foreach ($properties as $property) {
                    $deleted = DB::table('shop_good_properties')
                        ->where('good_id', $goodId)
                        ->where('property_id', $property['property_id'])
                        ->where('shop_property_value_id', $property['property_value_id'])
                        ->delete();

                    if ($deleted > 0) {
                        $removedCount++;
                    }
                }
            }

            DB::commit();

            // Р›РѕРіРёСЂСѓРµРј РґРµР№СЃС‚РІРёРµ РґР»СЏ РєР°Р¶РґРѕРіРѕ С‚РѕРІР°СЂР°
            foreach ($goodIds as $goodId) {
                $good = ShopGood::find($goodId);
                if ($good) {
                    $this->logAudit($good, 'bulk_remove_properties', [
                        'properties_removed' => $properties,
                        'removed_count' => $removedCount,
                    ], null);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'РҐР°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё СѓСЃРїРµС€РЅРѕ СѓРґР°Р»РµРЅС‹',
                'count' => $removedCount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('РћС€РёР±РєР° РїСЂРё РјР°СЃСЃРѕРІРѕРј СѓРґР°Р»РµРЅРёРё С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё СѓРґР°Р»РµРЅРёРё С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРє',
            ], 500);
        }
    }
}
