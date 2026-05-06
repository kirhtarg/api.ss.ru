<?php
$file = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\ShopGoodsController.php';
$content = file_get_contents($file);

$search1 = <<<'SEARCH1'
        // Фильтр по атрибутам вариаций (товары С этими атрибутами)
        if ($request->has('variation_attribute_names')) {
            $attributeNames = $request->input('variation_attribute_names');
            // Обрабатываем как массив, так и строку
            if (! is_array($attributeNames)) {
                $attributeNames = [$attributeNames];
            }
            if (! empty($attributeNames)) {
                // Фильтруем товары, у которых есть вариации с указанными атрибутами
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
SEARCH1;

$replace1 = <<<'REPLACE1'
        // Фильтр по атрибутам вариаций (товары С этими атрибутами)
        if ($request->has('variation_attribute_names')) {
            $attributeNames = $request->input('variation_attribute_names');
            // Обрабатываем как массив, так и строку
            if (! is_array($attributeNames)) {
                $attributeNames = [$attributeNames];
            }
            if (! empty($attributeNames)) {
                // Фильтруем товары, у которых есть ХОТЯ БЫ ОДНА вариация со ВСЕМИ указанными атрибутами
                $query->whereExists(function ($subQuery) use ($attributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->where(function ($q) use ($attributeNames) {
                            foreach ($attributeNames as $name) {
                                $q->whereExists(function ($attrSubQuery) use ($name) {
                                    $attrSubQuery->selectRaw('1')
                                        ->from('shop_variation_attributes_values as vav')
                                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                        ->whereColumn('vav.variation_id', 'v.id')
                                        ->where('a.name', $name);
                                });
                            }
                        });
                });
            }
        }
REPLACE1;

$content = str_replace($search1, $replace1, $content);

$search2 = <<<'SEARCH2'
        // Ч˜сключение атрибутов вариаций (товары БЕЗ этих атрибутов)
        if ($request->has('exclude_variation_attribute_names')) {
            $excludeAttributeNames = $request->input('exclude_variation_attribute_names');
            // Обрабатываем как массив, так и строку
            if (! is_array($excludeAttributeNames)) {
                $excludeAttributeNames = [$excludeAttributeNames];
            }
            if (! empty($excludeAttributeNames)) {
                // Когда применяется фильтр "БЕЗ атрибутов", показываем ТОЛЬКО товары с вариациями,
                // которые НЕ имеют указанные атрибуты
                // Ч˜спользуем подзапрос для точного контроля
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
SEARCH2;

$replace2 = <<<'REPLACE2'
        // Ч˜сключение атрибутов вариаций (товары БЕЗ этих атрибутов)
        if ($request->has('exclude_variation_attribute_names')) {
            $excludeAttributeNames = $request->input('exclude_variation_attribute_names');
            // Обрабатываем как массив, так и строку
            if (! is_array($excludeAttributeNames)) {
                $excludeAttributeNames = [$excludeAttributeNames];
            }
            if (! empty($excludeAttributeNames)) {
                // Когда применяется фильтр "БЕЗ атрибутов", показываем ТОЛЬКО товары с вариациями,
                // которые НЕ имеют указанные атрибуты.
                // Чтобы исключить все товары, у которых есть ХОТЯ БЫ ОДНА вариация с ЛЮБЫМ из исключаемых атрибутов:
                $query->whereExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('good_id', 'shop_goods.id')
                        ->limit(1);
                })->whereNotExists(function ($subQuery) use ($excludeAttributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->where(function ($q) use ($excludeAttributeNames) {
                            foreach ($excludeAttributeNames as $name) {
                                // Используем orWhereExists, чтобы исключить товар, если у него есть хотя бы одна вариация с любым из исключаемых атрибутов
                                $q->orWhereExists(function ($attrSubQuery) use ($name) {
                                    $attrSubQuery->selectRaw('1')
                                        ->from('shop_variation_attributes_values as vav')
                                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                        ->whereColumn('vav.variation_id', 'v.id')
                                        ->where('a.name', $name);
                                });
                            }
                        });
                });
            }
        }
REPLACE2;

$content = str_replace($search2, $replace2, $content);
file_put_contents($file, $content);
echo "File updated successfully!";
